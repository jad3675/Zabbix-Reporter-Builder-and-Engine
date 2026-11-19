# Report builder for Zabbix

A Zabbix 7.x frontend module for customer-facing reports: problems and notifications per
device, most frequent problems, top metrics, capacity outlook, availability and maintenance.
Preview in the browser, download as PDF, XLSX or CSV, or schedule delivery by email.

It is built to be safe to run against a production Zabbix:

- It only reads. Every API call passes a guard that allows `.get` on a fixed list of
  object types and refuses everything else.
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

**Scope** has four parts, and a report can mix them:

- **Host groups**, picked with the standard Zabbix group multiselect.
- **Group name patterns** for groups that come and go, one per line: `Acme/*` matches
  every group whose name starts with `Acme/`.
- **Devices**, picked with the standard host multiselect, for the reports where naming
  six switches beats inventing a group. Named devices are added on top of the groups,
  whatever the tag filter says.
- **Host tags**, applied to the groups, one per line: `site`, `site=Toronto`, `site~tor`,
  `site!=Lab`, `!decommissioned`.

A report with no scope at all is refused. Groups and devices are stored by name, so a
definition copied to another instance resolves there; anything that does not resolve is
reported instead of silently dropped.

**Period** is previous month, previous week (Monday to Sunday), yesterday, month to date,
or the last N days, in the report's time zone. It can also be pinned to the calendar: a
single day picked from a date picker, or a range from one date and time to another, to
the minute. A range end is exclusive (08:00 to 17:00 stops at 16:59:59); a bare date as
the end means the whole of that day. A report pinned to fixed dates cannot be scheduled,
since it would send the same window every time.

The preview can override the period with any of these. In single-day mode the ◀ and ▶
buttons step a day back or forward and rerun, so "last Wednesday" is one click on the
calendar. Downloads and the print view use whatever period the preview is showing.

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
| Top metrics | either one ranking across devices, or the top items for each device, chosen by item tag, key or name pattern, with a daily trend line |
| Time above threshold | hours each device spent above a value, whether or not a trigger fired |
| Network interfaces | ports picked by description (ifAlias), name and link state: speed, traffic in and out, peak utilization, errors and discards, status, uptime and time not up, daily trend |
| Capacity outlook | days until volumes cross a threshold, from a straight-line fit of daily averages, with R² shown |
| Availability | ICMP ping availability per device against a target |
| Monitoring health | items not collecting and interfaces unreachable, by device |
| Inventory summary | devices under management grouped by an inventory field: vendor, model, OS, location |
| Configuration changes | who changed what, from the Zabbix audit log, with each changed field on its own line |
| Maintenance windows | windows in effect during the period; devices in maintenance now, with who added them and a flag on the ones nobody took out; time each device spent in maintenance with and without data; problems that started during maintenance |

**Network interfaces** rebuilds ports from the items interface discovery creates
(`net.if.in`, `net.if.out`, `.errors`, `.discards`, `net.if.status`, `net.if.speed`),
grouped by host and the index in the key. Both key styles are understood
(`net.if.in.errors[ifInErrors.5]` and the 7.x walk-based `net.if.in.errors[5]`), as are
agent keys (`net.if.in["eth0"]`). The description and name come from the `description`
and `interface` tags, or from the item name (`Interface Gi1/0/5(Uplink): ...` or
`Interface [Gi1/0/5(Uplink)]: ...`) when the tag is missing or holds the literal
`{#IFALIAS}` some stock templates leave behind. Patterns are comma-separated and
case-insensitive; a word with no wildcard matches anywhere, so `uplink` finds "Uplink to
core" and "CORE-UPLINK". A description pattern or a name pattern is required, and the API
search is narrowed by them, so a 48-port stack is not read whole to find its two
uplinks. Utilization is traffic over the speed the port reports at the end of the
period; ports with no speed item show traffic only. Errors and discards are totals
estimated from hourly averages of the per-second rates.

**A morning down report.** Set the link state to "Not up right now (latest value)" and the
section becomes a list of only the matching interfaces whose link is not up at the moment
the report runs: status, down since, for how long, and when Zabbix last checked. It reads
each status item's latest value, not trends, so a link that dropped ten minutes ago is on
it and one that came back is not. "Down since" is the end of the last hour the link was
seen up, from hourly trends going back "Look back for when it went down" days (7 by
default), whatever the report period, and only for the interfaces that are down. Pair it
with the "Last 24 hours, up to when it runs" period and a daily 06:00 schedule. "Last N
days" and "Yesterday" both end at midnight, which is right for a monthly report and wrong
for this one. Interfaces with only a status item (fabric links on some templates keep no
traffic counters) are reported.

When nothing matches, the section says why in its notes: no item names contain the
pattern; they do, but on keys that are not interface keys (and which keys); the text is
in the interface name rather than its description, or the other way round; or "Skip
interfaces" left everything out.

**Link state** comes from the operational status item (ifOperStatus): 1 is up, and 2
(down), 7 (lower layer down) and every other value count as not up. The section can be
limited to ports that were not up at some point, not up at the end of the period, not up
at any time, or up the whole period, and shows each port's status at the end, its uptime
and its time not up. These come from hourly trends: an hour in which the link changed is
split by its average, which is exact for a single change in that hour. A port with no
status item has no link state and is left out when one is picked, with a note.

"Leave out interfaces with no traffic" drops unused ports: quiet and steady, always up or
always down. A port whose link went down during the period is kept even with no traffic,
and picking a link state turns the idle filter off, so a dead uplink is never hidden for
being quiet.

Only the items a run will read count against "Items per selector" (Settings): one per
port for "Not up right now", three for traffic alone, up to eight with errors, discards
and link state. Each port reads up to eight items for the whole period (four without errors and
discards), so a month of a few hundred ports is a frontend-sized job and a month of
thousands belongs to the CLI runner. A pattern that matches too much fails that section
with a message; the rest of the report still runs.

The Zabbix API cannot see ifDescr: discovery reads it, then it is gone. The name pattern
matches ifName, and also an `ifdescr` tag where one exists. To filter on ifDescr, add that
tag (value `{#IFDESCR}`) to the item prototypes in your interface templates.

**Maintenance windows** has four parts, each switched on or off.

*Windows in effect during the period* lists each window that actually ran, with how long
it was in effect. Zabbix keeps the schedule, not a history, so this is worked out from
it the way the server does: one-time periods, and daily, weekly and monthly ones
repeating from the window's "active since", at their local time of day, cut to the
window's active since and till. The server reads those times in its own time zone; the
report uses its own, and a note says which. If the two differ, recurring windows shift.

*Devices in maintenance now* is a snapshot taken when the report runs. Zabbix records
on each host when its current maintenance period began, so the table shows how long each
device has been in, when that period is due to end (its length, capped by the window's
own end), and who added it, from the audit log. A device is flagged when it has been in
longer than a set number of days (7 by default) or is not due out for more than a year.
A recurring window resets the clock each time it opens, so a nightly window is not
flagged; a one-time period someone set to 999 days is. With only this part switched on
and "List only flagged devices" ticked, the section is a report of devices nobody took
out. Who added a device needs Super admin to read the audit log; a device added through a
host group has no entry of its own, and the audit log forgets what housekeeping removes.

*Time each device spent in maintenance* splits it into with and without data
collection. Hours without data hold no values: graphs have gaps, availability leaves
them out, and no problem can be raised. This is the usual answer to "why is this
device's availability so low" or "why is it so quiet".

*Problems during maintenance* counts, per device, the problems that started while it
was in a maintenance with data collection, so their notifications were held back. A
window with tag conditions only covers problems whose tags match (the problems' tags are
read only for those). "Still open when it ended" counts problems that outlasted the
maintenance and alerted from then on, following a window that runs past the end of the
report to its real end. Maintenance without data collection raises no problems at all,
so it hides more than can be counted.

**Configuration changes** reads the Zabbix audit log. It flattens the details field, so a
macro deleted inside a host update appears as its own line instead of hiding behind an
"Update", and it collapses a bulk operation (one recordsetid, many objects) into a single
row with a count. Ids are looked up and printed as names, so `hostgroup_rights[45].id: 197` reads
`Servers/Linux (197)`, and coded values such as permission or GUI access are labelled.
Where the shape is known and a whole object arrived at once, sibling fields are written
as one sentence instead of one row each: an action condition becomes
`Event tag value: notify.class contains cpu`, an operation becomes `Send message to user
group NOC (24) via Email (HTML), escalation step 2`, a macro becomes
`{$ALERT.CHANNEL:"cpu"} = ,email, (Set by Alert routing)`, and a permission becomes
`Servers/Linux (197): Read`. Recovery and update operations compose the same way. Zabbix leaves out any field equal
to its database default, so a plain message operation carries no operationtype and a host
group condition no conditiontype; those defaults are filled back in. A partial update
falls back to per-field rows, where seeing exactly which field moved matters more. Action condition types, operators and operation
types are spelled out, so `conditiontype: 26` reads `Event tag value (26)`. An id whose object has since been deleted, or was renamed since, is named from the audit
log itself; a live name always wins over that fallback. A change buried in a long description shows only the part that
moved, rather than two truncated copies of the same paragraph. Internal link-table ids
(rightid, hostmacroid and friends), uuids, the object's own id, and container rows whose
children are already listed are dropped, which is most of the noise
in a raw audit entry. Both cleanups can be switched off. Filters: configuration changes
only, deletions only including nested ones, by resource, by user, and skipping the
automation accounts. Two Zabbix limits
apply: reading the audit log needs Super admin, so a scheduled run only includes this
section if the API token belongs to one; and the audit housekeeping period decides how
far back it goes. The audit log is instance-wide, so this section ignores the report's
device scope.

Every section takes an intro paragraph in the customer's own words, and filters to skip
devices by name pattern and (where it deals with problems) to skip problems by name, so
"agent is not available" noise stays out of the numbers.

Item selection defaults to the tags the official 7.x templates use (`component:cpu`,
`component:storage`), so the same report works for agent and SNMP hosts. With several
tags, **Tag logic** decides whether an item needs all of them or any one, so
`component=cpu` plus `component=memory` with "any" covers both in a single section.

**Top metrics** has two layouts. *One ranking across every device* answers "which
devices are worst at this". *Top items for each device* answers "for each device, what
are its busiest items", listing the device once with its top few items underneath. The
spreadsheet export repeats the device name on every row so it can be sorted.

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
sudo -u www-data php bin/reporter.php run acme-monthly --day=2026-11-11
sudo -u www-data php bin/reporter.php run acme-monthly --from="2026-11-11 08:00" --till="2026-11-11 17:00"
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
- A time range reads hourly trends, so its edges are hour-aligned for metric sections:
  a range starting at 08:30 leaves the 08:00 hour out of traffic and CPU figures.
  Problems and notifications use the exact minute.
- Network interfaces cannot filter on ifDescr unless a template exposes it (see above).
- Media type names are visible only to Super admins. The Settings page records them each
  time it is saved, so reports run by other users still show names.
- The email body is plain text with the files attached.
- Email media types using OAuth (Microsoft 365, Gmail) cannot be used for delivery.

## Tests

```sh
php -d memory_limit=512M tests/run.php --out /tmp/out     # units + every section, all formats
php -d memory_limit=1G tests/scale.php 2500 31            # a 2,500-host month, frontend budget
php -d memory_limit=1G tests/scale.php 2500 31 --cli      # the same with the CLI runner's budget
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
