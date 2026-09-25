#!/usr/bin/env bash
set -Eeuo pipefail
[[ ${GITHUB_ACTIONS:-} == true && $EUID -eq 0 ]] || exit 1
# This VM is disposable and contains no customer data.
systemctl stop nginx apache2 mysql mariadb docker docker.socket 2>/dev/null || true
if dpkg-query -W -f='${Status}' mysql-server 2>/dev/null | grep -q 'install ok installed'; then
    DEBIAN_FRONTEND=noninteractive apt-get purge -y mysql-server mysql-server-8.0 mysql-client-8.0 mysql-server-core-8.0
    # Only the preinstalled runner database directory is replaced, after asserting the environment.
    [[ $(readlink -m /var/lib/mysql) == /var/lib/mysql ]] || exit 1
    mv /var/lib/mysql /var/lib/mysql-runner-original
fi
export PANEL_HOST=203.0.113.10 ADMIN_EMAIL=ci@example.invalid ADMIN_PASSWORD=CiOnlyPassword_839173 PANEL_PORT=8443 INSTALL_DOCKER=0
bash scripts/install-ubuntu.sh
curl -ksSf https://127.0.0.1:8443/ >/dev/null
curl -ksSf https://127.0.0.1:8443/phpmyadmin/ | grep -q phpMyAdmin
python3 tests/linux-host.py
printf 'preserve-existing-data\n' > /srv/vpsmanager/update-preservation.txt
old_release=$(readlink -f /opt/vpsmanager/current)
printf 'obsolete\n' > "$old_release/obsolete-release-file.txt"
bash scripts/upgrade-ubuntu.sh
test "$(cat /srv/vpsmanager/update-preservation.txt)" = preserve-existing-data
test ! -e /opt/vpsmanager/current/obsolete-release-file.txt
test -f "$old_release/obsolete-release-file.txt"
curl -ksSf https://127.0.0.1:8443/phpmyadmin/ | grep -q phpMyAdmin
systemctl is-active --quiet vpsmanager-worker vpsmanager-agent vpsmanager-metrics.timer
echo 'PASS fresh install, phpMyAdmin and in-place update with data preservation'
