#!/bin/sh
# Removes the runner: the timer, the service and (with --purge) the data directory.
# The module itself stays; disable it in Zabbix and delete its directory to remove that.
#
#   sh contrib/uninstall-runner.sh [--purge] [--data-dir DIR]

set -eu

DATA_DIR=/var/lib/zabbix/reporter
PURGE=0

while [ $# -gt 0 ]; do
	case "$1" in
		--purge) PURGE=1; shift ;;
		--data-dir) DATA_DIR=$2; shift 2 ;;
		*) echo "unknown option: $1" >&2; exit 2 ;;
	esac
done

[ "$(id -u)" -eq 0 ] || { echo "Run this as root." >&2; exit 1; }

if command -v systemctl >/dev/null 2>&1; then
	systemctl disable --now zabbix-reporter.timer 2>/dev/null || true
	systemctl stop zabbix-reporter.service 2>/dev/null || true
	systemctl reset-failed zabbix-reporter.service 2>/dev/null || true
fi

rm -f /etc/systemd/system/zabbix-reporter.service /etc/systemd/system/zabbix-reporter.timer
command -v systemctl >/dev/null 2>&1 && systemctl daemon-reload || true
echo "removed the timer and service"

if [ "$PURGE" -eq 1 ]; then
	rm -rf "$DATA_DIR"
	echo "removed $DATA_DIR, including report definitions, settings and the API token"
else
	echo "kept $DATA_DIR (reports, settings and token). Add --purge to remove it too."
fi

echo "Run contrib/install-runner.sh to set it up again."
