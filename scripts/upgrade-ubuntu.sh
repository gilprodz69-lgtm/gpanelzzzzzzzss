#!/usr/bin/env bash
# Called from the verified, self-contained installer. Preserve shared data and switch releases.
set -Eeuo pipefail
umask 027
[[ $EUID -eq 0 ]] || { echo 'Execute como root.' >&2; exit 1; }
source /etc/os-release
[[ "$ID" == ubuntu && ( "$VERSION_ID" == 22.04 || "$VERSION_ID" == 24.04 ) ]] || exit 1
exec 9>/run/lock/vpsmanager-update.lock
flock -n 9 || { echo 'Uma atualização já está em execução.' >&2; exit 1; }
project_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
old_release=$(readlink -f /opt/vpsmanager/current)
[[ "$old_release" == /opt/vpsmanager/releases/* && -d "$old_release" && -f /opt/vpsmanager/shared/.env ]] || { echo 'Instalação existente inválida.' >&2; exit 1; }
[[ ! -f /opt/vpsmanager/shared/storage/maintenance ]] || { echo 'Há uma manutenção anterior pendente. Confira o log e o backup antes de atualizar novamente.' >&2; exit 1; }
[[ $(df -Pk /opt/vpsmanager | awk 'NR==2 {print $4}') -ge 2000000 ]] || { echo 'Libere pelo menos 2 GB antes de atualizar.' >&2; exit 1; }
stamp=$(date -u +%Y%m%d%H%M%S)
backup_dir="/var/backups/vpsmanager/$stamp"
release_dir="/opt/vpsmanager/releases/$stamp"
[[ ! -e "$release_dir" ]] || exit 1
install -d -m 0700 "$backup_dir"
install -d -m 0750 /var/log/vpsmanager
exec > >(tee -a /var/log/vpsmanager/update.log) 2>&1
echo "Atualizando de $(cat "$old_release/VERSION") para $(cat "$project_dir/VERSION")."
mariadb-dump --protocol=socket --single-transaction --routines --events vpsmanager > "$backup_dir/database.sql"
chmod 0600 "$backup_dir/database.sql"
cp -a /opt/vpsmanager/shared/.env "$backup_dir/environment"
cp -a /etc/nginx/conf.d/vpsmanager-panel.conf "$backup_dir/panel.conf"
cp -a /etc/vpsmanager "$backup_dir/configuration"
printf '%s\n' "$old_release" > "$backup_dir/previous-release"
stage='dependências'
migration_started=0
recover() {
    if [[ $migration_started == 0 ]]; then
        rm -f -- /opt/vpsmanager/shared/storage/maintenance
        systemctl start vpsmanager-agent vpsmanager-worker vpsmanager-metrics.timer || true
    fi
    echo "Atualização interrompida em: $stage. Backup: $backup_dir. Consulte /var/log/vpsmanager/update.log antes de repetir." >&2
}
trap recover ERR
bash "$project_dir/scripts/install-runtime.sh"
stage='nova versão'
install -d -m 0755 "$release_dir"
rsync -a --exclude=.env --exclude=storage --exclude=.tools --exclude=node_modules --exclude=dist --exclude=.git "$project_dir/" "$release_dir/"
find "$release_dir" -type d -exec chmod 0755 {} +
find "$release_dir" -type f -exec chmod 0644 {} +
runuser -u vpsmanager -- test -r "$release_dir/scripts/console.php"
runuser -u www-data -- test -r "$release_dir/public/index.php"
ln -s /opt/vpsmanager/shared/storage "$release_dir/storage"
ln -s /opt/vpsmanager/shared/.env "$release_dir/.env"
find "$release_dir/app" "$release_dir/public" "$release_dir/database" -name '*.php' -print0 | xargs -0 -n1 php8.3 -l >/dev/null
stage='migração'
touch /opt/vpsmanager/shared/storage/maintenance
chmod 0644 /opt/vpsmanager/shared/storage/maintenance
systemctl stop vpsmanager-metrics.timer vpsmanager-metrics.service
stage='aguardando operações em andamento'
runuser -u vpsmanager -- php8.3 "$release_dir/scripts/console.php" queue:wait
systemctl stop vpsmanager-worker vpsmanager-agent
# Final consistent snapshot after stopping mutations.
mariadb-dump --protocol=socket --single-transaction --routines --events vpsmanager > "$backup_dir/database.sql"
stage='migração'
migration_started=1
php8.3 "$release_dir/scripts/console.php" migrate
stage='configuração'
install -m 0644 "$release_dir/deploy/nginx-upload.conf" /etc/nginx/snippets/vpm-upload.conf
if ! grep -q 'include /etc/nginx/snippets/vpm-upload.conf;' /etc/nginx/conf.d/vpsmanager-panel.conf; then
    sed -i '/client_max_body_size 2m;/a\    include /etc/nginx/snippets/vpm-upload.conf;' /etc/nginx/conf.d/vpsmanager-panel.conf
fi
if ! grep -q 'include /etc/nginx/snippets/vpm-phpmyadmin.conf;' /etc/nginx/conf.d/vpsmanager-panel.conf; then
    sed -i '/client_max_body_size 2m;/a\    include /etc/nginx/snippets/vpm-phpmyadmin.conf;' /etc/nginx/conf.d/vpsmanager-panel.conf
fi
install -d -m 0755 /var/lib/letsencrypt /var/lib/vpsmanager-acme
# Existing sites receive HTTPS independently through the SSL module; new sites default to HTTPS.
install -m 0644 "$release_dir"/deploy/vpsmanager-*.service "$release_dir"/deploy/vpsmanager-*.timer /etc/systemd/system/
php8.3 "$release_dir/scripts/upgrade-config.php" > "$backup_dir/https.json"
python3 - "$backup_dir/https.json" /etc/vpsmanager/agent.env <<'PY'
import json,sys,pathlib,ipaddress,re
data=json.loads(pathlib.Path(sys.argv[1]).read_text())
path=pathlib.Path(sys.argv[2]); lines=path.read_text().splitlines() if path.exists() else []
lines=[line for line in lines if not line.startswith('VPM_ACME_EMAIL=')]
lines.append('VPM_ACME_EMAIL='+data['email'])
path.write_text('\n'.join(lines)+'\n');path.chmod(0o600)
try: ipaddress.IPv4Address(data['host'])
except ValueError: pass
else:
    panel=pathlib.Path('/etc/nginx/conf.d/vpsmanager-panel.conf')
    content=panel.read_text()
    content=re.sub(r'(listen 80;\s*server_name )'+re.escape(data['host'])+r';',r'\g<1>_;',content)
    panel.write_text(content)
    default=pathlib.Path('/etc/nginx/sites-enabled/default')
    if default.is_symlink() and default.resolve()==pathlib.Path('/etc/nginx/sites-available/default'): default.unlink()
PY
nginx -t
stage='ativação'
ln -s "$release_dir" /opt/vpsmanager/current.next
mv -Tf /opt/vpsmanager/current.next /opt/vpsmanager/current
systemctl daemon-reload
systemctl restart php8.3-fpm vpsmanager-agent vpsmanager-worker
systemctl enable --now vpsmanager-metrics.timer vpsmanager-certificates.timer
systemctl reload nginx
sleep 2
systemctl is-active --quiet nginx php8.3-fpm mariadb vpsmanager-agent vpsmanager-worker
runuser -u vpsmanager -- php8.3 "$release_dir/scripts/console.php" metrics
runuser -u vpsmanager -- php8.3 "$release_dir/scripts/health.php"
python3 - "$backup_dir/https.json" "$release_dir/scripts/panel-certificate.py" <<'PY'
import json,sys,pathlib,subprocess
data=json.loads(pathlib.Path(sys.argv[1]).read_text())
result=subprocess.run(['/usr/bin/python3',sys.argv[2],data['host'],data['email']])
if result.returncode: print('Certificado público pendente. O HTTPS existente foi preservado; verifique DNS/IP e porta 80.')
PY
rm -- /opt/vpsmanager/shared/storage/maintenance
trap - ERR
echo "Painel atualizado. Backup e versão anterior preservados em $backup_dir."
echo 'Sites, bancos, contas e senhas preservados. Arquivos antigos não fazem parte da versão ativa.'
