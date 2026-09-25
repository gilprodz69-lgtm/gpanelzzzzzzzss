#!/usr/bin/env bash
# VPS Manager installer. Only for a fresh Ubuntu 22.04/24.04 server.
set -Eeuo pipefail
umask 027
[[ $EUID -eq 0 ]] || { echo 'Execute este instalador como root ou usando sudo.' >&2; exit 1; }
source /etc/os-release
[[ "$ID" == ubuntu && ( "$VERSION_ID" == 22.04 || "$VERSION_ID" == 24.04 ) ]] || { echo 'É necessário Ubuntu Server 22.04 ou 24.04.' >&2; exit 1; }
for existing in /www/server/panel /usr/local/CyberCP /usr/local/cpanel /opt/vpsmanager/current; do
    [[ ! -e "$existing" ]] || { echo "Instalação existente detectada em $existing. Use uma VPS limpa; nenhum serviço foi alterado." >&2; exit 1; }
done
if systemctl is-active --quiet nginx apache2 mariadb mysql docker; then
    echo 'Há serviços de hospedagem ativos. Este instalador exige uma VPS limpa.' >&2; exit 1
fi
[[ $(awk '/MemTotal/ {print $2}' /proc/meminfo) -ge 1800000 ]] || { echo 'São necessários 2 GB de RAM.' >&2; exit 1; }
[[ $(df -Pk / | awk 'NR==2 {print $4}') -ge 5000000 ]] || { echo 'São necessários 5 GB livres.' >&2; exit 1; }
default_ip=$(hostname -I | awk '{print $1}')
PANEL_HOST=${PANEL_HOST:-${PANEL_DOMAIN:-}}
if [[ -z "$PANEL_HOST" ]]; then
    [[ -r /dev/tty ]] || { echo 'Defina PANEL_HOST e ADMIN_EMAIL para instalação não interativa.' >&2; exit 1; }
    printf 'Domínio do painel ou IP da VPS [%s]: ' "$default_ip" > /dev/tty
    read -r PANEL_HOST < /dev/tty
    PANEL_HOST=${PANEL_HOST:-$default_ip}
fi
is_domain=0
if [[ "$PANEL_HOST" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]]; then
    is_domain=1
elif [[ "$PANEL_HOST" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
    IFS=. read -r -a octets <<< "$PANEL_HOST"
    for octet in "${octets[@]}"; do [[ $((10#$octet)) -le 255 ]] || { echo 'IP inválido.' >&2; exit 1; }; done
else
    echo 'Use um domínio ou endereço IPv4 válido, sem protocolo nem porta.' >&2; exit 1
fi
ADMIN_EMAIL=${ADMIN_EMAIL:-}
if [[ -z "$ADMIN_EMAIL" ]]; then
    printf 'E-mail do administrador: ' > /dev/tty
    read -r ADMIN_EMAIL < /dev/tty
fi
[[ "$ADMIN_EMAIL" =~ ^[a-zA-Z0-9_.+%-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,63}$ ]] || { echo 'E-mail inválido.' >&2; exit 1; }
PANEL_PORT=${PANEL_PORT:-8443}
[[ "$PANEL_PORT" =~ ^[0-9]{2,5}$ ]] && ((10#$PANEL_PORT >= 1024 && 10#$PANEL_PORT <= 65535)) || { echo 'Use uma porta entre 1024 e 65535.' >&2; exit 1; }
for reserved in 3306 9081 9443; do [[ "$PANEL_PORT" != "$reserved" ]] || { echo 'Porta reservada.' >&2; exit 1; }; done
if ss -H -lnt "sport = :$PANEL_PORT" | grep -q .; then echo 'Porta do painel já está em uso.' >&2; exit 1; fi
if [[ -n "${ADMIN_PASSWORD:-}" && ( ${#ADMIN_PASSWORD} -lt 12 || ${#ADMIN_PASSWORD} -gt 72 ) ]]; then echo 'ADMIN_PASSWORD deve ter entre 12 e 72 caracteres.' >&2; exit 1; fi
project_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
[[ -f "$project_dir/public/index.php" && -f "$project_dir/agent/server.py" ]] || { echo 'Pacote incompleto.' >&2; exit 1; }
install -d -m 0750 /var/log/vpsmanager
exec > >(tee -a /var/log/vpsmanager/install.log) 2>&1
stage='dependências'
trap 'echo "Instalação interrompida na etapa: $stage. Consulte /var/log/vpsmanager/install.log. Não execute novamente sem verificar o estado." >&2' ERR
echo "Instalando VPS Manager em Ubuntu $VERSION_ID; painel HTTPS na porta $PANEL_PORT."
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y ca-certificates curl openssl rsync software-properties-common
if [[ "$VERSION_ID" == 22.04 ]]; then
    # Ubuntu 22.04 ships PHP 8.1; this documented repository provides PHP 8.3.
    add-apt-repository -y ppa:ondrej/php
    apt-get update
fi
apt-get install -y nginx mariadb-server python3 openssh-server ufw cron
bash "$project_dir/scripts/install-runtime.sh"
if [[ ${INSTALL_DOCKER:-1} == 1 ]]; then apt-get install -y docker.io; fi
php8.3 -r 'if (PHP_VERSION_ID < 80300 || !extension_loaded("sodium") || !extension_loaded("pdo_mysql")) exit(1);'
stage='arquivos e contas do sistema'
id vpsmanager >/dev/null 2>&1 || useradd --system --user-group --home-dir /opt/vpsmanager --shell /usr/sbin/nologin vpsmanager
release_dir="/opt/vpsmanager/releases/$(date -u +%Y%m%d%H%M%S)"
install -d -m 0755 "$release_dir" /opt/vpsmanager/shared /srv/vpsmanager /var/lib/vpsmanager-acme /etc/letsencrypt /var/lib/letsencrypt /var/mail
install -d -m 0700 /etc/vpsmanager /var/lib/vpsmanager-agent
install -d -o vpsmanager -g vpsmanager -m 0750 /opt/vpsmanager/shared/storage /opt/vpsmanager/shared/storage/logs /opt/vpsmanager/shared/storage/cache /opt/vpsmanager/shared/storage/backups
rsync -a --exclude=.tools --exclude=.env --exclude=.git --exclude=node_modules --exclude=test-results --exclude=storage --exclude=dist "$project_dir/" "$release_dir/"
find "$release_dir" -type d -exec chmod 0755 {} +
find "$release_dir" -type f -exec chmod 0644 {} +
ln -s /opt/vpsmanager/shared/storage "$release_dir/storage"
database_password=$(openssl rand -hex 32)
app_key=$(php8.3 "$release_dir/scripts/console.php" key:generate)
ADMIN_PASSWORD=${ADMIN_PASSWORD:-$(openssl rand -hex 20)}
export ADMIN_PASSWORD ADMIN_EMAIL
cat > /opt/vpsmanager/shared/.env <<EOF
APP_ENV=production
APP_URL=https://$PANEL_HOST:$PANEL_PORT
APP_KEY=$app_key
DB_DSN=mysql:host=127.0.0.1;dbname=vpsmanager;charset=utf8mb4
DB_USER=vpsmanager
DB_PASSWORD=$database_password
COOKIE_SECURE=1
SESSION_TTL=3600
AGENT_ALLOW_HTTP_LOOPBACK=1
EOF
chown root:vpsmanager /opt/vpsmanager/shared/.env
chmod 0640 /opt/vpsmanager/shared/.env
ln -s /opt/vpsmanager/shared/.env "$release_dir/.env"
stage='banco de dados'
systemctl enable --now mariadb
mariadb --protocol=socket <<SQL
CREATE DATABASE vpsmanager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'vpsmanager'@'127.0.0.1' IDENTIFIED BY '$database_password';
GRANT ALL ON vpsmanager.* TO 'vpsmanager'@'127.0.0.1';
SQL
php8.3 "$release_dir/scripts/console.php" install
openssl rand -hex 32 > /etc/vpsmanager/agent.secret
chmod 0600 /etc/vpsmanager/agent.secret
php8.3 "$release_dir/scripts/register-local-server.php"
stage='HTTPS e painel'
san="IP:$PANEL_HOST"
[[ $is_domain -eq 0 ]] || san="DNS:$PANEL_HOST"
openssl req -x509 -newkey rsa:3072 -sha256 -nodes -days 365 -subj "/CN=$PANEL_HOST" -addext "subjectAltName=$san" -keyout /etc/vpsmanager/panel.key -out /etc/vpsmanager/panel.crt >/dev/null 2>&1
chmod 0600 /etc/vpsmanager/panel.key
cat > /etc/php/8.3/fpm/pool.d/vpsmanager.conf <<'POOL'
[vpsmanager]
user = vpsmanager
group = vpsmanager
listen = /run/php/vpsmanager.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 10s
clear_env = yes
security.limit_extensions = .php
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_value[error_log] = /opt/vpsmanager/shared/storage/logs/php.log
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec
POOL
ln -s "$release_dir" /opt/vpsmanager/current
cat > /etc/nginx/conf.d/vpsmanager-panel.conf <<EOF
server {
    listen $PANEL_PORT ssl;
    server_name $PANEL_HOST;
    ssl_certificate /etc/vpsmanager/panel.crt;
    ssl_certificate_key /etc/vpsmanager/panel.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    root /opt/vpsmanager/current/public;
    index index.php;
    client_max_body_size 2m;
    include /etc/nginx/snippets/vpm-phpmyadmin.conf;
    location / { try_files \$uri /index.php?\$query_string; }
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /opt/vpsmanager/current/public/index.php;
        fastcgi_pass unix:/run/php/vpsmanager.sock;
        fastcgi_read_timeout 190s;
    }
    location ~ \.php\$ { return 404; }
    location ~ /\. { deny all; }
}
server {
    listen 80;
    server_name $PANEL_HOST;
    location /.well-known/acme-challenge/ { root /var/lib/vpsmanager-acme; }
    location / { return 301 https://$PANEL_HOST:$PANEL_PORT\$request_uri; }
}
EOF
nginx -t
systemctl enable --now php8.3-fpm nginx cron
systemctl reload php8.3-fpm nginx
stage='firewall'
ssh_connection=${SSH_CONNECTION:-}
ssh_port=${ssh_connection##* }
ssh_port=${ssh_port:-22}
[[ "$ssh_port" =~ ^[0-9]+$ ]] || ssh_port=22
ufw allow "$ssh_port/tcp"
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow "$PANEL_PORT/tcp"
# Preserve current firewall default policy. No access to database or agent is opened.
trusted_certificate=0
stage='certificado público'
if python3 "$release_dir/scripts/panel-certificate.py" "$PANEL_HOST" "$ADMIN_EMAIL"; then
    trusted_certificate=1
else
    echo 'HTTPS local ativo. Validação pública pendente: verifique DNS/IP e porta 80.'
fi
install -d -m 0755 /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\n/usr/sbin/nginx -t && /usr/bin/systemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/vpsmanager-nginx
chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/vpsmanager-nginx
stage='agente e processamento'
touch /etc/subuid /etc/subgid
cat > /etc/vpsmanager/agent.env <<EOF
VPM_PROTECTED_PORTS=$ssh_port,80,443,9443,$PANEL_PORT
VPM_PANEL_HOST=$PANEL_HOST
VPM_ACME_EMAIL=$ADMIN_EMAIL
VPM_DOCKER_IMAGES=nginx:stable-alpine,redis:7-alpine
EOF
install -m 0644 "$release_dir"/deploy/vpsmanager-*.service "$release_dir"/deploy/vpsmanager-*.timer /etc/systemd/system/
nginx -t
systemctl daemon-reload
systemctl enable --now vpsmanager-agent vpsmanager-worker vpsmanager-metrics.timer vpsmanager-certificates.timer
systemctl reload nginx
stage='verificações finais'
sleep 2
systemctl is-active --quiet nginx php8.3-fpm mariadb vpsmanager-agent vpsmanager-worker
runuser -u vpsmanager -- php8.3 "$release_dir/scripts/console.php" metrics
runuser -u vpsmanager -- php8.3 "$release_dir/scripts/health.php"
umask 077
printf 'URL: https://%s:%s\nE-mail: %s\nSenha inicial: %s\n' "$PANEL_HOST" "$PANEL_PORT" "$ADMIN_EMAIL" "$ADMIN_PASSWORD" > /root/vpsmanager-access.txt
chmod 0600 /root/vpsmanager-access.txt
if [[ -w /dev/tty ]]; then
    printf '\nVPS Manager instalado!\nAcesse: https://%s:%s\nE-mail: %s\nSenha inicial: %s\n\n' "$PANEL_HOST" "$PANEL_PORT" "$ADMIN_EMAIL" "$ADMIN_PASSWORD" > /dev/tty
fi
unset ADMIN_PASSWORD database_password app_key
trap - ERR
echo "Instalação concluída: https://$PANEL_HOST:$PANEL_PORT"
echo 'Credenciais preservadas em /root/vpsmanager-access.txt (somente root).'
if [[ $trusted_certificate -eq 0 ]]; then echo 'Certificado local: o navegador exibirá um aviso de confiança até instalar um certificado público.'; fi
echo 'Se o provedor tem firewall externo, libere a porta TCP do painel no portal do provedor.'
echo 'A versão instalada e seus módulos em desenvolvimento estão documentados em docs/STATUS.md.'
