#!/usr/bin/env bash
set -Eeuo pipefail
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y software-properties-common ca-certificates python3-venv
add-apt-repository -y ppa:ondrej/php
apt-get update
packages=()
for version in 7.4 8.0 8.1 8.2 8.3 8.4; do
    for extension in cli fpm mysql curl mbstring xml zip gd intl bcmath soap sqlite3; do
        packages+=("php${version}-${extension}")
    done
done
apt-get install -y "${packages[@]}"
for version in 7.4 8.0 8.1 8.2 8.3 8.4; do
    systemctl enable --now "php${version}-fpm"
done
python3 -m venv /opt/vpsmanager/acme
/opt/vpsmanager/acme/bin/pip install --disable-pip-version-check 'certbot>=5.4,<6'
echo 'phpmyadmin phpmyadmin/reconfigure-webserver multiselect' | debconf-set-selections
echo 'phpmyadmin phpmyadmin/dbconfig-install boolean false' | debconf-set-selections
apt-get install -y --no-install-recommends phpmyadmin
id vpm-pma >/dev/null 2>&1 || useradd --system --user-group --no-create-home --shell /usr/sbin/nologin vpm-pma
install -d -o vpm-pma -g vpm-pma -m 0700 /var/lib/vpsmanager-pma
install -d -m 0755 /var/lib/vpsmanager-acme /etc/vpsmanager
if [[ ! -f /etc/vpsmanager/phpmyadmin-secret ]]; then openssl rand -hex 16 > /etc/vpsmanager/phpmyadmin-secret; fi
chown root:vpm-pma /etc/vpsmanager/phpmyadmin-secret
chmod 0640 /etc/vpsmanager/phpmyadmin-secret
cat > /etc/phpmyadmin/config.inc.php <<'PHP'
<?php
$cfg['blowfish_secret'] = trim(file_get_contents('/etc/vpsmanager/phpmyadmin-secret'));
$cfg['Servers'][1]['auth_type'] = 'cookie';
$cfg['Servers'][1]['host'] = 'localhost';
$cfg['Servers'][1]['AllowNoPassword'] = false;
$cfg['Servers'][1]['AllowRoot'] = false;
$cfg['AllowArbitraryServer'] = false;
$cfg['TempDir'] = '/var/lib/vpsmanager-pma';
$cfg['UploadDir'] = '';
$cfg['SaveDir'] = '';
PHP
chmod 0644 /etc/phpmyadmin/config.inc.php
cat > /etc/php/8.3/fpm/pool.d/vpm-pma.conf <<'POOL'
[vpm-pma]
user = vpm-pma
group = vpm-pma
listen = /run/php/vpm-pma.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 3
pm.process_idle_timeout = 10s
php_admin_value[session.save_path] = /var/lib/vpsmanager-pma
php_admin_value[upload_tmp_dir] = /var/lib/vpsmanager-pma
php_admin_value[upload_max_filesize] = 100M
php_admin_value[post_max_size] = 105M
php_admin_value[memory_limit] = 256M
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec
POOL
cat > /etc/nginx/snippets/vpm-phpmyadmin.conf <<'NGINX'
location = /phpmyadmin { return 301 /phpmyadmin/; }
location /phpmyadmin/ {
    root /usr/share;
    index index.php;
    client_max_body_size 105m;
    location ~ ^/phpmyadmin/(setup|libraries|templates|vendor)/ { deny all; }
    location ~ /\. { deny all; }
    location ~ ^/phpmyadmin/.*\.php$ {
        root /usr/share;
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/vpm-pma.sock;
        fastcgi_read_timeout 190s;
    }
}
NGINX
systemctl disable --now certbot.timer 2>/dev/null || true
systemctl reload php8.3-fpm
