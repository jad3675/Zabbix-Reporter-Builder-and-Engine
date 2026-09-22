#!/bin/sh
# End-to-end runner test: fake Zabbix JSON-RPC, fake SMTP, settings and secrets written
# the way the Settings page writes them, a "Send now" queued the way the UI queues it.
set -u
M=$(cd "$(dirname "$0")/.." && pwd)
T=$(mktemp -d)
D=$T/data; MAIL=$T/mail
mkdir -p "$D/definitions" "$MAIL"
export REPORTER_DATA_DIR=$D
fail=0
ok() { if [ "$1" = 0 ]; then echo "ok    $2"; else echo "FAIL  $2"; fail=$((fail+1)); fi; }

rm -f /tmp/reporter-fakeapi.ser
php -S 127.0.0.1:8089 "$M/tests/fake_server.php" >/dev/null 2>&1 & ZPID=$!
python3 "$M/tests/smtp_sink.py" 8025 "$MAIL" >/dev/null 2>&1 & SPID=$!
sleep 1

# What the Settings page does: save settings, copy the media type, store the token.
php -r '
require "'"$M"'/lib/bootstrap.php";
use Modules\Reporter\Lib\Core\{Settings, Secrets, Requests};
Settings::save(["runner" => ["api_url" => "http://127.0.0.1:8089/"], "delivery" => ["mediatypeid" => "1"]]);
Secrets::setToken("test-token", "Admin");
Secrets::setSmtp(["mediatypeid" => "1", "name" => "Email (HTML)", "smtp_server" => "127.0.0.1", "smtp_port" => 8025,
	"smtp_helo" => "", "smtp_email" => "ZaaS Reports <reports@encore.example>", "smtp_security" => 0,
	"smtp_verify_peer" => 0, "smtp_verify_host" => 0, "smtp_authentication" => 1, "username" => "reports",
	"passwd" => "hunter2"], "Admin");
Requests::enqueue("run_report", ["id" => "cch"], "Admin");
'
cat > "$D/definitions/cch.json" <<JSON
{"id":"cch","name":"CCH weekly","timezone":"UTC","period":{"type":"previous_month"},
 "scope":{"groups":["CCH/*"]},"sections":[{"type":"summary"},{"type":"problems_by_host"}],
 "branding":{"title":"Service report","customer":"CCH"},
 "schedule":{"enabled":true,"cycle":"monthly","day":1,"hour":0},
 "delivery":{"formats":["pdf","xlsx"],"email_to":["noc@cch.example"]}}
JSON
[ "$(stat -c %a "$D/secrets.json")" = 600 ]; ok $? "secrets file is 0600"
php "$M/bin/reporter.php" test-api | grep -q "Token accepted"; ok $? "test-api uses the stored token"
php "$M/bin/reporter.php" test-mail john@encore.example >/dev/null; ok $? "test-mail uses the media type copy"

php "$M/bin/reporter.php" run-due --quiet; ok $? "run-due exits 0"
php -r '
require "'"$M"'/lib/bootstrap.php";
foreach (Modules\Reporter\Lib\Core\Requests::results() as $r) echo $r["type"], " ", $r["ok"] ? "ok" : "FAILED", ": ", $r["message"], "\n";
' > "$T/results.txt"
cat "$T/results.txt" | sed 's/^/        /'
grep -q "run_report ok: " "$T/results.txt"; ok $? "Send now request delivered"
grep -q "X-Auth: reports:hunter2" "$MAIL"/*.eml; ok $? "SMTP auth used the media type's credentials"
grep -qi "From: ZaaS Reports <reports@encore.example>" "$MAIL"/*.eml; ok $? "display name from the media type"
[ "$(ls "$MAIL" | wc -l)" = 3 ]; ok $? "three messages (test, send-now, scheduled)"
grep -l "noc@cch.example" "$MAIL"/*.eml | head -1 | xargs grep -q "application/pdf"; ok $? "report mail has the PDF attached"
[ -s "$D/state/runner.json" ]; ok $? "heartbeat written"
php -r 'exit(json_decode(file_get_contents("'"$D"'/state/cch.json"), true)["last_error"] === null ? 0 : 1);'; ok $? "schedule state recorded"

php "$M/bin/reporter.php" run-due --quiet; ok $? "second run-due is quiet"
[ "$(ls "$MAIL" | wc -l)" = 3 ]; ok $? "scheduled report not sent twice"

# No token: the runner says what to do and exits non-zero.
php -r 'require "'"$M"'/lib/bootstrap.php"; Modules\Reporter\Lib\Core\Secrets::setToken(null, "Admin");'
php "$M/bin/reporter.php" test-api 2>&1 | grep -q "Settings page"; ok $? "missing token explained"

kill $ZPID $SPID 2>/dev/null
rm -rf "$T"
echo; [ $fail = 0 ] && echo "runner e2e: all passed" || echo "runner e2e: $fail failed"
exit $fail
