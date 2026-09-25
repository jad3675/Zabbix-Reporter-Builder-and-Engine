# Report builder for Zabbix

A Zabbix 7.x frontend module for customer-facing reports: problems and notifications per
device, most frequent problems, top metrics, capacity outlook, availability and maintenance.
Preview in the browser, download as PDF, XLSX or CSV, or schedule delivery by email.

It is built to be safe to run against a production Zabbix:

- It only reads. Every API call passes a guard that allows `.get` on nine object types
  and refuses everything else.
- Every run has a time, API-call, row and memory budget. A report that hits one stops,
  says which limit it hit, and marks the remaining sections as skipped. Nothing is
  silently truncated.
- It reads hourly trends, never raw history, and pages events and alerts in bounded
  chunks.
- At most two reports run at once across all users and the scheduler (configurable).
- Results are cached, so preview-then-export queries Zabbix once.
- No database tables and no core files touched. Everything lives in the module directory
  and one data directory.

## Requirements

- Zabbix 7.0 or later (developed and tested against 7.4)
- RHEL-family or Debian/Ubuntu, Apache or nginx with PHP-FPM
- PHP 8.0+ with mbstring and gd (already there for the Zabbix frontend)
- The PHP zip extension for XLSX export (optional; CSV works without it)
- For scheduled delivery: PHP CLI with curl on the frontend server

| | RHEL | Ubuntu |
|---|---|---|
| XLSX | `dnf install php-pecl-zip` | `apt install php-zip` |
| Scheduled delivery | `dnf install php-cli` | `apt install php-cli php-curl` |

Restart PHP-FPM after adding an extension. The release archive includes its PHP
dependencies (mPDF, PHPMailer) in `vendor/`; there is no Composer step.

## Install

```sh
unzip reporter-*.zip -d /usr/share/zabbix/ui/modules/
sh /usr/share/zabbix/ui/modules/reporter/contrib/install-runner.sh
```

Use `/usr/share/zabbix/modules/` instead if that is where your frontend keeps modules.
Then in Zabbix: **Administration > General > Modules**, **Scan directory**, enable
**Report builder**. It appears under **Reports > Report builder**.

### What install-runner.sh does

Run it once as root. It is safe to run again.

1. Finds the PHP-FPM user (`www-data` on Ubuntu, `apache` on RHEL, or whatever your pool
   says) and checks the PHP CLI has the extensions it needs, printing the right package
   names for your distribution if not.
2. Creates `/var/lib/zabbix/reporter`, owned by that user, with no access for anyone
   else, and makes sure the user can reach it through the parent directories.
3. On RHEL with SELinux, labels it `httpd_sys_rw_content_t`.
4. Installs and starts `zabbix-reporter.timer`, which runs the runner every five
   minutes as the same user as the frontend.

Options: `--data-dir DIR`, `--web-user USER`.

Previews and downloads work without the runner. Scheduled delivery and "Send now" need it.

## Settings

**Reports > Report builder > Settings** (Super admin only).

**Zabbix connection.** Scheduled reports call the API with a token and show exactly what
the token's user can see. Create a dedicated user (for example `reporter`) with the
**User** role and read permission on the customer's host groups, then create an API token
for it under **Users > API tokens** and paste it here. On the frontend server itself,
`http://localhost/` plus the frontend path skips the load balancer.

**Email.** Pick one of your Zabbix Email media types. The report builder uses its server,
port, security, from address and credentials, so there is nothing to set up twice. The
runner uses a copy taken when you save, because its own token normally cannot read media
types; the page tells you when the media type has changed since and needs saving again.
OAuth media types are not supported.

**Access.** Who can view reports (viewers only ever see data their own permissions allow)
and who can edit them. The settings page itself is always Super admin only.

**Safety limits.** Tunable, each with a hard ceiling that cannot be raised from the UI.

**Test token** and **Send test email** run immediately against what is on the page,
before you save, and show the answer next to the button. An empty token field tests the
saved token. **Send now** on a scheduled report is handed to the runner, which has the larger
time budget, and goes out within five minutes. The page also shows when the runner last
ran and what each scheduled report last delivered.

On RHEL with SELinux enforcing, the test buttons need the web server to be allowed
outbound connections (`setsebool -P httpd_can_network_connect on`); the installer tells
you if it is off. Scheduled delivery does not need it.

## Security model

The same trust model as Zabbix's own scheduled reports:

- The API token and the copied SMTP credentials are stored in
  `<data_dir>/secrets.json`, mode 0600, owned by the web server user. Zabbix keeps media
  type passwords in its database, whose credentials the web server already holds, so this
  is no weaker. The page writes secrets but never shows them again.
- The runner runs as the web server user, under a hardened systemd unit:
  `ProtectSystem=strict` with only the data directory writable, `NoNewPrivileges`,
  private `/tmp`, no home, low CPU and I/O priority.
- The data and output directories cannot be changed from the UI, since that would let a
  Super admin choose where the web server user writes files.
- Every write action in the frontend (save, delete, settings, tests, send now) requires
  Zabbix's CSRF token.
- Reports only read. The runner's token user needs nothing beyond read access.

## Building a report

**Scope** is host groups (wildcards work: `Acme/*`) and optionally host tags, one per
line: `site`, `site=Toronto`, `site~tor`, `site!=Lab`, `!decommissioned`. A report with no
scope is refused.

**Period** is previous month, previous week (Monday to Sunday), yesterday, month to date,
or the last N days, in the report's time zone. The preview can override it, including
custom dates.

**Comparison** against the period before can be switched on per report. Headline numbers
then read "43 problems, down 12" and the per-device table gains a change column. It
doubles the queries, so it is off by default.

**Sections**, in any order and any number (up to 20):

| Section | What it shows |
|---|---|
| Written summary | free text from the account manager, at the top of the report |
| Summary | devices, problems raised and resolved, median time to resolve, notifications, problems per day, notifications by media type |
| Problems and notifications by device | per device: problems by severity, time in problem state, mean time to resolve, notifications sent and failed; optional roll-up by a host tag such as `site` |
| Most frequent problems | the problems that fired most, by trigger or by name across devices, with total and longest duration |
| Response times | time to acknowledge and time to resolve, broken down by severity, device or host tag, plus the slowest to acknowledge |
| Problem log | every problem, one row each: start, duration, status and notifications |
| Top metrics | top or bottom N by any numeric metric, chosen by item tag, key or name pattern, with a daily trend line |
| Time above threshold | hours each device spent above a value, whether or not a trigger fired |
| Capacity outlook | days until volumes cross a threshold, from a straight-line fit of daily averages, with R² shown |
| Availability | ICMP ping availability per device against a target |
| Monitoring health | items not collecting and interfaces unreachable, by device |
| Inventory summary | devices under management grouped by an inventory field: vendor, model, OS, location |
| Maintenance windows | maintenance active during the period on devices in scope |

Every section takes an intro paragraph in the customer's own words, and filters to skip
devices by name pattern and (where it deals with problems) to skip problems by name, so
"agent is not available" noise stays out of the numbers.

Item selection defaults to the tags the official 7.x templates use (`component:cpu`,
`component:storage`), so the same report works for agent and SNMP hosts.

**Branding**: title, customer, accent colour, paper size, footer. For a logo, put a PNG,
JPEG or SVG in `<data_dir>/assets/` and enter its file name.

The editor's **Definition as JSON** panel copies a report between instances.

### Your own sections

Drop a PHP file in `sections/`. See `sections/README.md` and the example
`sections/problems_by_hour.php.example`.

## Runner CLI

The timer runs `run-due`. The rest is for setup and troubleshooting. Run it as the web
server user (`www-data` on Ubuntu, `apache` on RHEL):

```sh
cd /usr/share/zabbix/ui/modules/reporter
sudo -u www-data php bin/reporter.php check              # settings, API, export support
sudo -u www-data php bin/reporter.php test-mail you@example.com
sudo -u www-data php bin/reporter.php run acme-monthly --period=previous_month --format=pdf,xlsx
sudo -u www-data php bin/reporter.php run acme-monthly --from=2026-08-01 --till=2026-08-31 --mail
sudo -u www-data php bin/reporter.php run-due --dry-run
```

Logs: `journalctl -u zabbix-reporter.service`.

A failed scheduled report is retried hourly until it succeeds. If the server is down at
the scheduled time, the report goes out at the next run after it comes back.

## Files

```
/var/lib/zabbix/reporter/      the data directory
  definitions/<id>.json        report definitions
  settings.json                Settings page values (no secrets)
  secrets.json                 API token and copied media type (0600)
  assets/                      logos
  cache/                       cached report results
  state/                       runner heartbeat, per-report delivery state, request results
  requests/                    "Send now" requests waiting for the runner
  out/<id>/                    delivered files (retention set in Settings)
```

## Upgrading

Unzip the new release over the module directory. Definitions, settings and secrets live
in the data directory and are untouched.

## Starting over, and removing

To reset the runner setup and set it up again from scratch:

```sh
sh contrib/uninstall-runner.sh          # add --purge to delete reports, settings and token
sh contrib/install-runner.sh
```

`install-runner.sh` prints each step and says which one failed, and ends by listing the
data directory so you can see the ownership and modes it produced. It also repairs a
data directory that was created by hand as root.

`reporter.php check` reports the same ground truth from the runner's side: the user it
runs as, any directory it cannot write, leftover setgid bits, and whether the systemd
unit runs as a different user than the one owning the data directory.

To remove the module as well: disable it in Zabbix, then delete the module directory.

## Known limitations

- Problems are counted in the period they were raised. A problem raised before the
  period and still open counts in the earlier period.
- Availability includes maintenance windows.
- Media type names are visible only to Super admins. The Settings page records them each
  time it is saved, so reports run by other users still show names.
- The email body is plain text with the files attached.
- Email media types using OAuth (Microsoft 365, Gmail) cannot be used for delivery.

## Tests

```sh
php -d memory_limit=512M tests/run.php --out /tmp/out     # units + every section, all formats
php -d memory_limit=1G tests/scale.php 2500 31            # a 2,500-host month
sh tests/runner_e2e.sh                                     # runner, settings, SMTP delivery
php tests/zabbix_harness.php /path/to/zabbix/ui           # frontend against real Zabbix classes
NODE_PATH=... node tests/ui_scripts.js /tmp/out           # editor and settings scripts (jsdom)
```

## Licences

The module's own code is yours to license. The bundled components:

| Component | Licence |
|---|---|
| mPDF | GPL-2.0-only |
| PHPMailer | LGPL-2.1 |
| FPDI | MIT |
| DeepCopy, psr/log, psr/http-message | MIT |

mPDF's GPL-2.0-only and the Zabbix 7 frontend's AGPL-3.0 are not obviously compatible.
Running the module on your own servers is not distribution; shipping it to customers
could be. Get that checked before you hand it out.
