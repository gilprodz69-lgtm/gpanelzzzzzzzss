#!/usr/bin/env bash
# Source from installers. Never remove package locks or terminate their owners.
vpm_apt() {
    local wait_seconds=${VPM_APT_WAIT_SECONDS:-600}
    [[ "$wait_seconds" =~ ^[0-9]{1,4}$ ]] && ((10#$wait_seconds >= 1 && 10#$wait_seconds <= 3600)) || {
        echo 'VPM_APT_WAIT_SECONDS deve estar entre 1 e 3600.' >&2; return 2;
    }
    local deadline=$((SECONDS + 10#$wait_seconds)) remaining output rc
    output=$(mktemp)
    while :; do
        remaining=$((deadline - SECONDS))
        ((remaining > 0)) || remaining=1
        # apt's native timeout covers dpkg locks. update also needs a retry for list locks.
        if LC_ALL=C apt-get -o "DPkg::Lock::Timeout=$remaining" "$@" 2>&1 | tee "$output"; then
            rm -f -- "$output"; return 0
        else
            rc=${PIPESTATUS[0]}
        fi
        if ! grep -Eq 'Could not get lock .*Resource temporarily unavailable|Could not get lock .*held by process|Unable to acquire the dpkg frontend lock|Unable to lock directory /var/lib/apt/lists' "$output"; then
            rm -f -- "$output"; return "$rc"
        fi
        remaining=$((deadline - SECONDS))
        if ((remaining <= 0)); then
            echo 'O gerenciador de pacotes continua ocupado. Aguarde a atualização do Ubuntu terminar e tente novamente. Nenhum bloqueio foi removido.' >&2
            rm -f -- "$output"; return "$rc"
        fi
        echo "Aguardando a atualização do Ubuntu liberar o gerenciador de pacotes (até ${remaining}s)..."
        ((remaining < 5)) || remaining=5
        sleep "$remaining"
    done
}
