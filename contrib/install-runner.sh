#!/bin/sh
# One-time server setup for scheduled report delivery. Run as root on the Zabbix
# frontend server. Works on RHEL-family and Debian/Ubuntu, Apache or nginx. Safe to run
# again.
#
#   sh contrib/install-runner.sh [--data-dir DIR] [--web-user USER]
#
# It creates the data directory for the web server user, labels it for SELinux where
# that is on, checks the PHP extensions, and installs a systemd timer that runs the
# runner every five minutes as the web server user.

set -eu

MODULE_DIR=$(cd "$(dirname "$0")/.." && pwd)
DATA_DIR=/var/lib/zabbix/reporter
WEB_USER=

while [ $# -gt 0 ]; do
	case "$1" in
		--data-dir) DATA_DIR=$2; shift 2 ;;
		--web-user) WEB_USER=$2; shift 2 ;;
		*) echo "unknown option: $1" >&2; exit 2 ;;
	esac
done

[ "$(id -u)" -eq 0 ] || { echo "Run this as root." >&2; exit 1; }

# Distribution, for package names in messages.
. /etc/os-release 2>/dev/null || true
case " ${ID:-} ${ID_LIKE:-} " in
	*" debian "*|*" ubuntu "*) FAMILY=debian ;;
	*" rhel "*|*" fedora "*|*" centos "*) FAMILY=rhel ;;
	*) FAMILY=other ;;
esac

# The PHP-FPM pool user: Ubuntu keeps pools in /etc/php/<ver>/fpm/pool.d, RHEL in
# /etc/php-fpm.d. Zabbix's own pool file is preferred when there are several.
if [ -z "$WEB_USER" ]; then
	for f in /etc/php/*/fpm/pool.d/zabbix*.conf /etc/php-fpm.d/zabbix*.conf \
			/etc/php/*/fpm/pool.d/*.conf /etc/php-fpm.d/*.conf; do
		[ -f "$f" ] || continue
		WEB_USER=$(sed -n 's/^[[:space:]]*user[[:space:]]*=[[:space:]]*\([A-Za-z0-9_-]*\).*/\1/p' "$f" | head -n1)
		[ -n "$WEB_USER" ] && break
	done
fi

if [ -z "$WEB_USER" ]; then
	[ "$FAMILY" = debian ] && WEB_USER=www-data || WEB_USER=apache
fi

id "$WEB_USER" >/dev/null 2>&1 || { echo "Web server user '$WEB_USER' does not exist; pass --web-user." >&2; exit 1; }
WEB_GROUP=$(id -gn "$WEB_USER")

# PHP CLI: the runner needs the same extensions as the frontend, plus curl.
PHP=$(command -v php || true)
if [ -z "$PHP" ]; then
	[ "$FAMILY" = debian ] && echo "Install PHP CLI: apt install php-cli" >&2 || echo "Install PHP CLI: dnf install php-cli" >&2
	exit 1
fi

missing=
for ext in mbstring gd curl; do
	"$PHP" -m | grep -qix "$ext" || missing="$missing $ext"
done

if [ -n "$missing" ]; then
	echo "The PHP CLI is missing:$missing" >&2
	if [ "$FAMILY" = debian ]; then
		echo "  apt install$(for e in $missing; do printf ' php-%s' "$e"; done)" >&2
	else
		echo "  dnf install$(for e in $missing; do [ "$e" = curl ] && printf ' php-common' || printf ' php-%s' "$e"; done)" >&2
	fi
	exit 1
fi

"$PHP" -m | grep -qix zip || {
	[ "$FAMILY" = debian ] && pkg=php-zip || pkg=php-pecl-zip
	echo "note: PHP zip extension missing; XLSX export is off until you install $pkg (and restart PHP-FPM)."
}

echo "module:   $MODULE_DIR"
echo "data dir: $DATA_DIR"
echo "runs as:  $WEB_USER:$WEB_GROUP"
echo "php:      $PHP"

# Data directory, owned by the web server user and nobody else.
mkdir -p "$(dirname "$DATA_DIR")"
install -d -m 2770 -o "$WEB_USER" -g "$WEB_GROUP" "$DATA_DIR"
chown -R "$WEB_USER:$WEB_GROUP" "$DATA_DIR"
chmod -R o-rwx "$DATA_DIR"

# The parent must let the web server through (a 0750 /var/lib/zabbix owned by zabbix
# would not).
if ! runuser -u "$WEB_USER" -- test -w "$DATA_DIR"; then
	parent=$(dirname "$DATA_DIR")
	chmod o+x "$parent" && echo "made $parent traversable (o+x) so $WEB_USER can reach $DATA_DIR"
	runuser -u "$WEB_USER" -- test -w "$DATA_DIR" || {
		echo "$WEB_USER still cannot write $DATA_DIR. Check: namei -l $DATA_DIR" >&2; exit 1; }
fi

# SELinux (RHEL): let php-fpm write the data directory.
if command -v selinuxenabled >/dev/null 2>&1 && selinuxenabled; then
	semanage fcontext -a -t httpd_sys_rw_content_t "$DATA_DIR(/.*)?" 2>/dev/null \
		|| semanage fcontext -m -t httpd_sys_rw_content_t "$DATA_DIR(/.*)?"
	restorecon -R "$DATA_DIR"

	if [ "$(getsebool httpd_can_network_connect 2>/dev/null | awk '{print $3}')" != on ]; then
		echo "note: SELinux blocks the web server's outbound connections, so the Settings page's"
		echo "      test buttons will fail. Scheduled delivery is not affected. To allow the tests:"
		echo "      setsebool -P httpd_can_network_connect on"
	fi
fi

# The frontend must know a non-default data directory too.
if [ "$DATA_DIR" != /var/lib/zabbix/reporter ]; then
	echo "NOTE: set \"data_dir\": \"$DATA_DIR\" in $MODULE_DIR/manifest.json so the frontend uses it."
fi

# systemd timer.
for unit in zabbix-reporter.service zabbix-reporter.timer; do
	sed -e "s#@MODULE_DIR@#$MODULE_DIR#g" -e "s#@DATA_DIR@#$DATA_DIR#g" -e "s#@PHP@#$PHP#g" \
		-e "s#@WEB_USER@#$WEB_USER#g" -e "s#@WEB_GROUP@#$WEB_GROUP#g" \
		"$MODULE_DIR/contrib/systemd/$unit" > "/etc/systemd/system/$unit"
done

systemctl daemon-reload
systemctl enable --now zabbix-reporter.timer
systemctl start zabbix-reporter.service || true

echo
echo "Done. Next: Zabbix > Reports > Report builder > Settings. Enter the Zabbix URL and an"
echo "API token, pick the email media type, and use the test buttons."
