#!/usr/bin/env bash
set -euo pipefail
umask 077

ops_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
if (( EUID != 0 )) || [[ ! -x /usr/sbin/clamd || ! -x /usr/bin/freshclam || ! -x /usr/bin/qpdf ]]; then
    printf '%s\n' 'Run as root after installing qpdf, clamav-daemon and clamav-freshclam.' >&2
    exit 1
fi
if [[ $(findmnt -n -o FSTYPE --target /run) != tmpfs ]]; then
    printf '%s\n' '/run must be on volatile storage.' >&2
    exit 1
fi

install -m 0644 -- "$ops_dir/tmpfiles.conf" /etc/tmpfiles.d/timeapp-personnel-beta.conf
systemd-tmpfiles --create /etc/tmpfiles.d/timeapp-personnel-beta.conf
install -d -m 0711 -o root -g root -- /var/lib/timeapp-personnel /etc/timeapp-personnel
install -d -m 0700 -o www-data -g www-data -- /var/lib/timeapp-personnel/store

if [[ ! -e /etc/clamav/clamd.conf.before-timeapp-personnel ]]; then
    install -m 0600 -- /etc/clamav/clamd.conf /etc/clamav/clamd.conf.before-timeapp-personnel
fi
install -m 0644 -- "$ops_dir/clamd.conf" /etc/clamav/clamd.conf
install -d -m 0755 -- /etc/systemd/system/clamav-daemon.socket.d
install -m 0644 -- "$ops_dir/clamav-socket.conf" /etc/systemd/system/clamav-daemon.socket.d/timeapp-personnel.conf

if [[ -f /etc/apparmor.d/usr.sbin.clamd ]]; then
    apparmor_local=/etc/apparmor.d/local/usr.sbin.clamd
    install -d -m 0755 -- /etc/apparmor.d/local
    if ! grep -Fq -- '/run/timeapp-personnel/scanner/** krw,' "$apparmor_local" 2>/dev/null; then
        printf '\n' >> "$apparmor_local"
        cat -- "$ops_dir/clamd.apparmor" >> "$apparmor_local"
    fi
    if command -v aa-enabled >/dev/null && aa-enabled --quiet; then
        apparmor_parser -r -T -W /etc/apparmor.d/usr.sbin.clamd
    fi
fi

systemctl daemon-reload
systemctl enable --now clamav-freshclam.service
for (( attempt=0; attempt<150; attempt++ )); do
    if compgen -G '/var/lib/clamav/main.c[vl]d' >/dev/null && compgen -G '/var/lib/clamav/daily.c[vl]d' >/dev/null; then
        break
    fi
    systemctl is-active --quiet clamav-freshclam.service
    sleep 2
done
if ! compgen -G '/var/lib/clamav/main.c[vl]d' >/dev/null || ! compgen -G '/var/lib/clamav/daily.c[vl]d' >/dev/null; then
    printf '%s\n' 'FreshClam did not install the standard signature databases.' >&2
    exit 1
fi

systemctl stop clamav-daemon.service
systemctl enable clamav-daemon.socket clamav-daemon.service
systemctl restart clamav-daemon.socket
systemctl start clamav-daemon.service
runuser -u www-data -- php "$ops_dir/verify-scanner.php"
