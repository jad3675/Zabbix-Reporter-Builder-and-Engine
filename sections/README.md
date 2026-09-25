# Custom sections

Any `*.php` file in this directory that returns an instance of
`Modules\Reporter\Lib\Section\SectionInterface` is registered as a section and shows up
in the editor, the preview, every export format and the CLI runner.

Start from `problems_by_hour.php.example`: rename it to `.php` and it is live.

## The contract

A section declares its options and returns blocks. It never writes HTML and never talks
to the database directly.

- `type()`: a stable identifier, `^[a-z][a-z0-9_]{1,63}$`. It is stored in report
  definitions, so do not rename it once reports use it.
- `options()`: a schema the editor renders and the server validates. Types: `text`,
  `textarea`, `int`, `float` (with `min` and `max`), `bool`, `select` (with `choices`),
  `tags` (one tag filter per line).
- `validateOptions()`: return error strings for combinations normalizing cannot fix,
  such as a selector that would match every item.
- `run(Context $ctx, array $options)`: read data, return a `SectionResult`.

## Reading data

`$ctx->api` is the only way out, and it is the guarded client: `.get` methods on an
allowlist of objects, inside the run's time, call, row and memory budgets. Prefer the
shared fetchers, which are computed once per report and reused by every section:

| Fetcher | Returns |
|---|---|
| `$ctx->hosts` | in-scope hosts: `name`, `tags`, `groupids` |
| `$ctx->problems()` | problems raised in the period, with recovery time and in-scope host ids |
| `$ctx->alerts()` | notifications sent in the period, with status and media type |
| `$ctx->items($selector)` | numeric items matching tags, key patterns or name patterns |
| `$ctx->trendSummary($itemids)` | period avg, min, max, sample count per item |
| `$ctx->trendDaily($itemids)` | daily averages per item, in the report timezone |
| `$ctx->previous()` | the same context over the preceding period, or null when the report does not compare |

Extending `AbstractSection` also gives you `commonOptions()` (intro paragraph plus the
device and problem exclusion filters), `$this->hosts($ctx, $o)`, `$this->problems($ctx,
$o)` and `$this->alerts($ctx, $o)`, which apply those filters for you. Use them instead
of `$ctx->hosts` and `$ctx->problems()` so your section honours the same exclusions as
the built-in ones.

For anything else, call `$ctx->api->call('object.get', [...])` and chunk ids with
`$ctx->chunks($ids, $ctx->limit('chunk_ids', 1000))`. Never read `history.get`: use
trends. If a query could be large, page it with `Data\Paginator::byClock()`.

## Returning data

`SectionResult` blocks: `kpis`, `table` (raw values, formatting per column), `chart`
(`hbar` or `severity`), `text`, plus `note()` for caveats the reader should see. Column
formats: `text`, `int`, `delta`, `number`, `number2`, `pp`, `pct`, `pct1`, `pct3`, `duration`,
`units`, `date`, `datetime`, `severity`, `sevstrip`, `spark`. Mark a column
`'export' => false` for screen-only visuals or `'screen' => false` for spreadsheet-only
detail.

## Why here and not in the data directory

The data directory is writable by the web server. Code the web server can write is code
an attacker who compromises the frontend can change. Keep sections in the module
directory, owned by root and deployed like the rest of the module.
