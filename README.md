# filament-bench

A test bench for Filament performance work. It builds a real Laravel app with a Filament panel over a generated shop, then lets you:

* **time** panel pages and the Livewire round trips that follow them (search, sort, paging, live fields, lazy widgets, polls), each in a fresh PHP process
* **count** what each request does: SQL queries, storage round trips, models hydrated, response bytes
* **compare** two versions of `filament/filament` (a release against your branch or patch) with alternating runs
* **check** that both versions render the same HTML and Livewire responses
* **test each finding** of a performance report (`findings/report.md`): measure what the finding says costs, and say whether the problem shows
* **profile** any scenario with Xdebug

**What we found testing Filament 5.11.4 is in [findings/results.md](findings/results.md).**

It follows the design of [statamic-bench](https://github.com/1994limited/statamic-bench): a performance change needs three answers. Is it faster, is the output still identical, and is the problem it fixes really there?

## Requirements

PHP 8.2 or newer with `intl`, `pdo_sqlite`, `mbstring`, `dom`, `curl`, `zip`, `gd` and `opcache`; Composer; `rsync` and `patch`. `findings` and `profile` also need Xdebug, and `profile` needs Python 3. The bench loads Xdebug only for those two commands, whatever your php.ini says.

## Quick start

```shell
# a baseline: the release
bin/bench setup stock --filament=5.11.4

# your change: the same release with a patch, or with a local filamentphp/filament checkout copied over it
bin/bench setup mine --filament=5.11.4 --patch=examples/evaluate-reflection-cache.patch
bin/bench setup mine --filament=5.11.4 --src=~/code/filament

bin/bench check stock mine       # same HTML?
bin/bench findings stock mine    # which of the report's problems does each version have?
bin/bench compare stock mine     # is it faster?
bin/bench profile mine customers_index
```

Each target is a complete app in `targets/<name>`. After changing your checkout, `bin/bench sync mine` copies it over again. Patches use the monorepo's paths (`packages/tables/src/...`); the bench maps them onto `vendor/filament/*`.

Targets are optimised the way a production deploy is (`artisan optimize` and `filament:optimize`), with `APP_DEBUG=false`. `--no-optimize` leaves that out.

## The app it generates

A shop, written straight into SQLite with plain inserts by a seeded generator (`app/bench/generate.php`). It never goes through the models or Filament, so the code under test can't shape its own fixture. The same seed and size always give the same rows, and a fingerprint of them is kept so `compare` and `check` refuse two targets with different data.

| size | customers | orders | order items | products | tags |
| --- | --- | --- | --- | --- | --- |
| `micro` | 200 | 600 | ~1,800 | 60 | 50 |
| `small` (default) | 2,000 | 10,000 | ~30,000 | 300 | 2,000 |
| `medium` | 20,000 | 100,000 | ~300,000 | 2,000 | 20,000 |
| `large` | 100,000 | 500,000 | ~1.5M | 5,000 | 50,000 |

```shell
bin/bench generate stock --size=medium
bin/bench generate stock --size=small --tags=20000   # override any one count
```

The panel (`app/app/Filament`) is shaped so the report's findings have something to act on, with Filament's defaults left alone wherever the report says a default is what costs:

* **Customers**: 50 rows per page with an `ImageColumn` on a private disk, a searchable relationship column, a BelongsTo `SelectFilter`, money and date columns, a per-row `ActionGroup`, and bulk actions with `checkIfRecordIsSelectableUsing`.
* **Products**: editable `SelectColumn` (options from a closure), `TextInputColumn` and `ToggleColumn`, a markdown column. The edit page has tabs, a `RichEditor` with 10 embedded images, single and multiple `FileUpload`, a `CodeEditor` and a `SpatieTagsInput`. Also an importer and an exporter.
* **Orders**: a query builder (text, number and date constraints), a summary, groups. The edit page has a searchable BelongsTo `Select`, a live field, and a relationship `Repeater` of 20 items, each with a relationship `Select` that disables options picked in sibling items. The view page has a `RepeatableEntry` over the items.
* **Five small resources**, so the sidebar has eight navigation badges, each a COUNT.
* **A dashboard** with a stats widget and a chart widget, both on the default polling interval, plus database notifications.
* **Big form**: 200 fields in 4 tabs of 5 sections, each field's visibility a closure on one live `Select` that uses `$get`.

The `media` disk is local files behaving like a private S3 bucket. Every call that would be a round trip (exists, size, MIME type, reads) is counted, and `--disk-latency=20` makes each one wait 20 ms, as a real bucket would.

## Scenarios

| kind | scenarios |
| --- | --- |
| pages | `ping` (a plain Laravel route), `login`, `dashboard`, `customers_index`, `products_index`, `orders_index`, `customer_edit`, `order_edit`, `order_view`, `product_edit`, `big_form`, `not_found` |
| Livewire round trips | `customers_search`, `customers_search_words`, `customers_filter_country`, `customers_page_2`, `customers_sort`, `orders_page_2`, `order_edit_live`, `big_form_live`, `big_form_live_first`, `big_form_live_last`, `stats_lazy_load`, `chart_lazy_load`, `notifications_lazy_load`, `stats_poll`, `chart_poll`, `notifications_poll` |

Every request runs in **its own PHP process**, as PHP-FPM serves it. The app boots with the request bound, and the user is put on the guard as a session would put them. Compiled code comes from an opcache file cache, standing in for FPM's shared memory. Booting and handling are timed separately.

A Livewire scenario first loads its page untimed and keeps the component's snapshot, loading it first if the component is lazy. It then sends the update the browser would, through the real `/livewire-…/update` route with CSRF protection on. So a sample is one round trip, the way a user pays for it.

```shell
bin/bench run stock --only=customers_index,customers_search --r=20
```

Each row shows the median time, boot time, peak memory, queries, storage round trips and response size. The JSON in `results/` has every sample, with the models hydrated by class and the storage calls by folder.

## Comparing two versions

```shell
bin/bench compare stock mine --rounds=3 --r=10
```

Each round runs every scenario on one target, then on the other, and the order swaps each round. A difference smaller than twice the noise (median absolute deviation of both targets' samples) or under 2% is reported as `same`. Queries, storage calls and response size are compared too; they shouldn't vary between samples, so any change in them is real.

Close other work first. On a shared cloud VM, two identical targets can differ by 5–10% on a short run. Run `compare` both ways round (`stock mine`, then `mine stock`) before you believe a small difference.

## Checking the output

```shell
bin/bench check stock mine
```

This renders every page and every Livewire round trip in both targets and compares them byte for byte, after masking what changes on every request: CSRF tokens, Livewire's component ids, snapshots and checksums, keys derived from them, and random keys. It also prints any scenario whose query count changed. Both versions are written to `results/check/`.

## Findings

```shell
bin/bench findings stock            # one target
bin/bench findings stock mine       # both, side by side
bin/bench findings stock --only=#2,#4,3.1-b
```

`app/bench/findings.php` holds one probe per finding. Each measures the thing the report says costs, and the result says whether the problem **SHOWS** on that target. To measure one thing, the probes use:

* **SQL**, for things like an EXISTS where a foreign key would do, a query per row, or a COUNT per badge.
* **Function counts** from Xdebug's function monitor: `ReflectionFunction`, `NumberFormatter`, CommonMark environments, `NavigationManager`, schema walks.
* **Container resolutions**, such as how many `Schema` objects a render builds.
* **Storage round trips** per folder, and **bytes** of repeated markup.
* **SQLite query plans**, plus the SQL Filament writes for MySQL and Postgres.
* **The same request with and without the cost.** A variant (`BENCH_VARIANT`, see `app/app/Bench/Variant.php`) switches off what the finding blames, such as the existence check, the selectable check, the sibling-disable, the `HasIcon` interface or the visibility closures.

The report's findings came from reading 4.x. They were reviewed against 5.11.4 one by one; the review is in `findings/review-*.md`, with a verdict, code and a measurement plan for every item. The bench measures the 38 that can be measured on the server and decided in one request:

| | measured |
| --- | --- |
| top 20 | #1–#20 (all of them) |
| tables | 3.1-b, 3.1-e, 3.1-h, 3.2-b, 3.2-f, 3.2-g, 3.2-h, 3.2-j |
| forms, infolists | 3.4-a, 3.4-d, 3.4-f, 3.8-inf-a |
| panels, support | 3.5-g, 3.6-c |
| widgets, notifications | 3.8-w-a, 3.8-n-a, 3.8-n-c |
| frontend | 3.9 (bundle sizes) |

Not measured yet, and why:

* **Browser-side JavaScript**: per-cell Livewire interceptors, dropdown observers, Tabs' MutationObserver, FilePond and the RichEditor's per-keystroke work. These need a browser and the Chrome DevTools Protocol; `findings/review-actions-qb-js.md` says what to count.
* **Tenancy**: the extra `NavigationManager` in #5 only appears on `tenantDomain()` panels, and the tenant-list, `IdentifyTenant` and tenancy-scope items also need a tenant panel. The bench's panel has no tenancy, so #5 reports `no`.
* **Global search**, Builder blocks, the media-library plugin, and the remaining Low items. The review gives each a measurement plan; adding one is a resource or page in `app/` and a probe in `findings.php`.

## Profiling

```shell
bin/bench profile mine customers_index --top=40
bin/bench profile mine big_form_live_last --callees='Schema->callAfterStateUpdated'
bin/bench profile mine order_edit --keep     # keep the cachegrind file for KCachegrind
```

Xdebug charges small, frequent functions more than they really cost. Use the profile to find candidates, then measure the change with `compare`.

## How it keeps runs fair

* PHP runs with `-n`, with only the extensions the app needs loaded, so nothing in your php.ini (Xdebug above all) can skew a run. Opcache is on, JIT off.
* Session, cache and queue are `array`/`sync`: nothing one request does carries over to the next. The database is put back byte for byte from `bench/pristine.sqlite` before and after every command.
* `APP_KEY` is fixed, so every target has the same Livewire endpoint and comparable snapshots.
* Every process has a deadline (`--timeout`, 900 s by default), and `--memory-limit` caps every process.

Set `BENCH_PHP=/path/to/php` to choose the PHP binary.

## Layout

```
bin/bench            the command
app/                 copied into every target
  app/               the panel: resources, widgets, the big form, the counting disk, variants
  bench/             generate.php, request.php, livewire.php, probe.php, findings.php, jobs.php, lib.php
  database/          the shop's migration
examples/            a patch to try the bench with (#1: cache closure reflection)
findings/            the report, its review against 5.11.4, and what testing found (results.md)
tools/               the cachegrind summary script
targets/             the apps (not committed)
results/             JSON results, rendered HTML, profiles (not committed)
```

## Licence

MIT
