#!/usr/bin/env bash
# Signed, operator-supplied release; no arbitrary download or execution from the UI.
set -Eeuo pipefail
umask 077
[[ $EUID -eq 0 ]] || { echo 'Root necessário.' >&2; exit 1; }
: "${RELEASE_SHA256:?Defina o SHA256 esperado}"
: "${RELEASE_PUBLIC_KEY:?Informe a chave pública confiável, provisionada fora do pacote}"
archive=${1:?Informe o pacote .tar.gz}
signature=${2:?Informe a assinatura do pacote}
[[ "$RELEASE_SHA256" =~ ^[a-fA-F0-9]{64}$ ]] || exit 1
[[ -f "$archive" && -f "$signature" && -f "$RELEASE_PUBLIC_KEY" ]] || exit 1
actual=$(sha256sum -- "$archive"); actual=${actual%% *}
[[ "${actual,,}" == "${RELEASE_SHA256,,}" ]] || { echo 'SHA256 incorreto.' >&2; exit 1; }
openssl dgst -sha256 -verify "$RELEASE_PUBLIC_KEY" -signature "$signature" "$archive"
old_release=$(readlink -f /opt/vpsmanager/current)
[[ "$old_release" == /opt/vpsmanager/releases/* && -d "$old_release" ]] || exit 1
release_dir="/opt/vpsmanager/releases/$(date -u +%Y%m%d%H%M%S)"
[[ ! -e "$release_dir" ]] || exit 1
install -d -m 0755 "$release_dir"
python3 - "$archive" "$release_dir" <<'PY'
import sys,tarfile,pathlib
archive, destination=sys.argv[1:]
with tarfile.open(archive,'r:gz') as package:
    members=package.getmembers()
    if len(members)>10000 or sum(m.size for m in members)>1024**3:
        raise SystemExit('Release exceeds size limit')
    for member in members:
        p=pathlib.PurePosixPath(member.name)
        if p.is_absolute() or '..' in p.parts or member.issym() or member.islnk() or not (member.isfile() or member.isdir()):
            raise SystemExit('Unsafe archive entry')
        if p.parts and p.parts[0] in ['.env','storage','.tools']:
            raise SystemExit('Release must not include configuration, data or tools')
    package.extractall(destination,filter='data')
PY
[[ -f "$release_dir/public/index.php" && -f "$release_dir/scripts/console.php" ]] || exit 1
find "$release_dir" -type d -exec chmod 0755 {} +
find "$release_dir" -type f -exec chmod 0644 {} +
ln -s /opt/vpsmanager/shared/.env "$release_dir/.env"
ln -s /opt/vpsmanager/shared/storage "$release_dir/storage"
find "$release_dir/app" "$release_dir/public" "$release_dir/database" -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
backup_dir="/var/backups/vpsmanager/$(date -u +%Y%m%d%H%M%S)"
install -d -m 0700 "$backup_dir"
touch /opt/vpsmanager/shared/storage/maintenance
chmod 0644 /opt/vpsmanager/shared/storage/maintenance
systemctl stop vpsmanager-worker vpsmanager-metrics.timer vpsmanager-agent
mariadb-dump --protocol=socket --single-transaction --routines --events vpsmanager > "$backup_dir/database.sql"
cp -a /opt/vpsmanager/shared/.env "$backup_dir/environment"
printf '%s\n' "$old_release" > "$backup_dir/previous-release"
recover() {
    echo "Atualização interrompida. Backup em $backup_dir. Painel permanece em manutenção." >&2
    echo 'Não reabra o painel até reconciliar a migration e o banco. Consulte docs/DEPLOY.md.' >&2
}
trap recover ERR
php "$release_dir/scripts/console.php" migrate
ln -s "$release_dir" /opt/vpsmanager/current.next
mv -Tf /opt/vpsmanager/current.next /opt/vpsmanager/current
systemctl restart php8.3-fpm vpsmanager-agent vpsmanager-worker
systemctl start vpsmanager-metrics.timer
rm -- /opt/vpsmanager/shared/storage/maintenance
trap - ERR
echo "Atualização aplicada. Backup preservado em $backup_dir."
