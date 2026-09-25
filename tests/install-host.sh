#!/usr/bin/env bash
set -Eeuo pipefail
[[ ${GITHUB_ACTIONS:-} == true && $EUID -eq 0 ]] || exit 1
python3 tests/test_apt.py
# This VM is disposable and contains no customer data.
systemctl stop nginx apache2 mysql mariadb docker docker.socket 2>/dev/null || true
if dpkg-query -W -f='${Status}' mysql-server 2>/dev/null | grep -q 'install ok installed'; then
    DEBIAN_FRONTEND=noninteractive apt-get purge -y mysql-server mysql-server-8.0 mysql-client-8.0 mysql-server-core-8.0
    # Only the preinstalled runner database directory is replaced, after asserting the environment.
    [[ $(readlink -m /var/lib/mysql) == /var/lib/mysql ]] || exit 1
    mv /var/lib/mysql /var/lib/mysql-runner-original
fi
export PANEL_HOST=203.0.113.10 ADMIN_EMAIL=ci@example.invalid ADMIN_PASSWORD=CiOnlyPassword_839173 PANEL_PORT=8443 INSTALL_DOCKER=0
# Reproduce restrictive ancestors on a fresh host, using the actual downloadable package.
install -d -o root -g root -m 0700 /opt/vpsmanager /opt/vpsmanager/releases /opt/vpsmanager/shared
bash install.sh
check_permissions() {
    runuser -u vpsmanager -- test -r /opt/vpsmanager/current/scripts/console.php
    runuser -u www-data -- test -r /opt/vpsmanager/current/public/index.php
    runuser -u vpm-pma -- php8.3 -r 'require "/etc/phpmyadmin/config.inc.php"; if (strlen($cfg["blowfish_secret"]) !== 32) exit(1);'
    ! runuser -u www-data -- test -r /opt/vpsmanager/shared/.env
    ! runuser -u vpm-pma -- test -r /etc/vpsmanager/agent.secret
    ! runuser -u vpsmanager -- test -r /root/vpsmanager-access.txt
    test "$(stat -c %a /etc/vpsmanager)" = 700
    test "$(stat -c %a /root/vpsmanager-access.txt)" = 600
    grep -q '^Senha inicial: ' /root/vpsmanager-access.txt
}
check_permissions
credentials_before=$(sha256sum /root/vpsmanager-access.txt)
curl -ksSf https://127.0.0.1:8443/ >/dev/null
curl -ksSf https://127.0.0.1:8443/phpmyadmin/ | grep -q phpMyAdmin
python3 tests/linux-host.py
printf 'preserve-existing-data\n' > /srv/vpsmanager/update-preservation.txt
old_release=$(readlink -f /opt/vpsmanager/current)
printf 'obsolete\n' > "$old_release/obsolete-release-file.txt"
# Exercise the upgrade entrypoint and migration of the original phpMyAdmin secret.
install -o root -g vpm-pma -m 0640 /etc/vpsmanager-phpmyadmin/secret /etc/vpsmanager/phpmyadmin-secret
pma_before=$(sha256sum /etc/vpsmanager-phpmyadmin/secret | cut -d ' ' -f 1)
rm -- /etc/vpsmanager-phpmyadmin/secret
VPM_UPDATE=1 bash install.sh
check_permissions
test "$credentials_before" = "$(sha256sum /root/vpsmanager-access.txt)"
test "$pma_before" = "$(sha256sum /etc/vpsmanager-phpmyadmin/secret | cut -d ' ' -f 1)"
test "$(cat /srv/vpsmanager/update-preservation.txt)" = preserve-existing-data
test ! -e /opt/vpsmanager/current/obsolete-release-file.txt
test -f "$old_release/obsolete-release-file.txt"
curl -ksSf https://127.0.0.1:8443/phpmyadmin/ | grep -q phpMyAdmin
systemctl is-active --quiet vpsmanager-worker vpsmanager-agent vpsmanager-metrics.timer
echo 'PASS fresh install, phpMyAdmin and in-place update with data preservation'
