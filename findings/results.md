# Filament 5.11.4 performance: what testing found

*1994 Limited · 17 November 2026 · measured with [filament-bench](https://github.com/1994limited/filament-bench)*

## Summary

A scouting report (`findings/report.md`) listed about 170 possible performance problems in Filament, from a static read of the `4.x` branch. We checked every item against the code of **Filament 5.11.4** (Livewire 4.4.8, Laravel 13.43, PHP 8.4). None of the cited code has changed in 5.x. We then built a bench that measures 38 of the items, including all of the top 20, on a real panel.

**31 of the 38 problems are there on 5.11.4. 6 are not, and 1 is a measurement only.** The counts are exact and repeat from run to run. The timings are medians of 7 runs.

The problems that cost the most, measured:

1. **Image columns ask storage about every image** (#2). A 50-row table makes 41 `exists()` calls. On a bucket 20 ms away, as S3 is, that adds **845 ms to every page**. Locally it is 9 ms.
2. **A per-record selectable check loads the whole table on every render** (#4). It loads 2,050 customers to show 50: 31 ms (18%) of the page at 2,000 rows, and it grows with the table.
3. **Closure options on a `SelectColumn` run once per cell** (3.1-h). That is 101 queries instead of 2, which costs 37 ms (18%) of the page.
4. **Query-builder date filters can't use an index** (#17). On 10,000 orders, "is after" scans the table in 3.75 ms, while the equivalent range takes 0.05 ms through the index. The scan grows with the table.
5. **The import request builds every job in memory** (#7). It needs 0.8 MB more for every 1,000 rows. A 1M-row file would need about 800 MB in one request; that is extrapolated, not run.
6. **Option lists are copied into every row and every Repeater item** (3.2-b, #14). The products list writes 3,056 `<option>`s into a 2 MB page. The order edit page runs 20 identical option queries and writes 6,026 `<option>`s, half of its 2.4 MB, which makes it the slowest page at 484 ms.

**The report's top item, closure reflection (#1), is real but cheap.** A cache removed 93% of the `ReflectionFunction` constructions on a list page (565 → 38). It made no measurable difference to any request: within ±1.2% in both orders of comparison.

## How we tested

The bench builds a Laravel app with a Filament panel over a seeded shop. The `small` data set has 2,000 customers, 10,000 orders, 300 products and 2,000 tags, and every list page shows 50 rows. Every request runs in a fresh PHP process, as PHP-FPM serves it, with opcache on, `APP_DEBUG=false`, and the app optimised as a deploy leaves it. Livewire scenarios send the real update request a browser sends after a search, a page change, a live field or a poll.

For each finding, the bench measures what the report says costs:

- **the SQL a request runs:** the queries, and SQLite's query plan;
- **function calls**, counted with Xdebug's function monitor;
- **objects built**, counted through the container;
- **storage round trips**, on a disk that counts them and can add latency;
- **response bytes**;
- **time with and without the cost:** the same request with the thing the finding blames switched off.

It reports **SHOWS** when the cost is there, and **no** when it isn't.

## Results: the report's top 20

| # | Finding | Measured on 5.11.4 | Result |
| --- | --- | --- | --- |
| 1 | `evaluate()` builds a `ReflectionFunction` every time | 100 evaluations of one closure build 100. The customers list builds 2,365 and the big form 1,007. Caching them changed no request's time | SHOWS, minor |
| 2 | ImageColumn checks and presigns every image | 41 `exists()` and 35 `temporaryUrl()` per 50 rows; +845 ms per page at 20 ms per round trip | SHOWS |
| 3 | Widgets poll every 5 s | Stats and chart widgets poll every 5 s (4 and 1 queries per poll); notifications every 30 s | SHOWS |
| 4 | The select-all count loads the whole table | 2,050 customers loaded to show 50; 50 without the check. +31 ms per page | SHOWS |
| 5 | `getRedirectUrl()` builds a second navigation | 1 NavigationManager per request. The extra one only happens on tenant-domain panels, which the bench doesn't have | no |
| 6 | Navigation badges count on every page | 8 COUNT queries on every page; none on Livewire updates | SHOWS |
| 7 | Import builds every job in the HTTP request | 5.5 MB for 1,000 rows, 21 MB for 20,000 | SHOWS |
| 8 | Import looks up the relationship for every row | 500 category lookups for 500 rows with 10 distinct categories | SHOWS |
| 9 | A sorted export pages with OFFSET | 101 OFFSET queries when sorted by date, 0 by id. No time difference at 10,000 rows | SHOWS |
| 10 | A live update walks the whole form | Blur on the 1st of 200 fields walks 9 schemas; on the 200th, 50 (+4.6 ms) | SHOWS |
| 11 | Layout components rebuild their child schema every time | Two calls give two different schemas; the big form builds 516 schemas per render | SHOWS |
| 12 | Clearing schema caches builds the tree first | Clearing 10 sections builds 40 schemas | SHOWS |
| 13 | A relationship Select queries twice when it fills | 1 query, searchable or not. The double query needs `preload()` or `multiple()` | no |
| 14 | Each Repeater item queries the Select's options again | 20 identical option queries for 20 items | SHOWS |
| 15 | Disabling options picked in sibling items is quadratic | 456 ms with it, 137 ms without, for 20 items × 300 options: about 70% of the order edit page and of each live update | SHOWS |
| 16 | Postgres text search can't use an index | Equals becomes `lower("number"::text) like ?`. On SQLite the same LIKE scans the table, where `=` uses the index | SHOWS |
| 17 | Date filters wrap the column | "Is after" scans the table (3.75 ms); a range uses the index (0.05 ms) | SHOWS |
| 18 | Echo and Pusher load on every page | `echo.js` (92 KB, 24 KB gzipped) on every page with broadcasting off | SHOWS |
| 19 | The code editor ships every language | 986 KB (340 KB gzipped), with no lazy loading | SHOWS |
| 20 | The tags input loads every tag | All 2,000 tags written into the edit page, from a query with no limit | SHOWS |

## Results: other findings

| id | Finding | Measured on 5.11.4 | Result |
| --- | --- | --- | --- |
| 3.1-b | A BelongsTo filter uses a subquery | A correlated EXISTS instead of `country_id in (…)` | SHOWS |
| 3.1-e | Search adds a subquery per word | A 3-word search adds 3 EXISTS to each query | SHOWS |
| 3.1-h | SelectColumn options run per cell | 101 queries with a closure, 2 with an array; +37 ms | SHOWS |
| 3.2-b | SelectColumn options in every row | 3,056 `<option>`s; the page is 2 MB | SHOWS |
| 3.2-f | A NumberFormatter per money cell | 74 for 50 rows | SHOWS |
| 3.2-g | A Markdown converter per cell | 50 for 50 rows | SHOWS |
| 3.2-h | An enum with `HasIcon` leaves TextColumn's fast path | +3%, inside the noise; +10 KB of HTML | no |
| 3.2-j | The loading-targets list in every control | 49 copies, 15 KB | SHOWS |
| 3.4-a | RichEditor checks every embedded image | 10 `exists()` for 10 images | SHOWS |
| 3.4-d | FileUpload makes 3 requests per file | 1 per file on page load. The other 2 come in a later request the bench doesn't send yet | no (partly untested) |
| 3.4-f | A relationship Repeater re-queries on every update | 1 query on an unrelated live update | SHOWS |
| 3.5-g | Record pages fetch the record twice | Once | no |
| 3.6-c | A spinner SVG in every control | 60 spinners, 51 KB of the customers list | SHOWS |
| 3.8-inf-a | RepeatableEntry over a relationship is N+1 | 20 single-product queries for 20 items | SHOWS |
| 3.8-n-a | The notifications poll checks twice | 2 queries per poll (a count and an `exists()`) | SHOWS |
| 3.8-n-c | PublishNotifications runs on every request | It is in the global middleware stack | SHOWS |
| 3.8-w-a | The chart re-renders on every poll | 6.5 KB re-sent every 5 s, unchanged or not | SHOWS |
| 3.9 | Bundle sizes | code-editor.js 963 KB, theme CSS 677 KB, rich-editor.js 542 KB; 12 files over 50 KB | measured |

## Where the original report was wrong or overstated

- **#1 closure reflection** is the report's top item, but it isn't where the time goes. On a list page `evaluate()` runs 21,000 times, and only about 2,400 of those calls receive a closure. The 200 visibility closures on the big form cost 6.5 ms, and that is mostly the work inside them.
- **#13** is wrong for the common case. A Select on a foreign key fills without a query.
- **#3, #5 and #6** are narrower than stated:
    - Livewire 4 sends all of a page's 5-second polls as one request per tick.
    - The second NavigationManager only appears on tenant-domain panels.
    - Livewire updates don't rebuild the sidebar.
- **#4**'s suggested fix (`toBase()` with a minimal select) can't work, because the selectable check needs full models. The count has to wait until someone selects all.
- **#19**: the "lazy-import path" the report points to doesn't exist. Lazy languages need code splitting in the build.
- **#9**: the cost is real, but the ordering is stable, so rows are only skipped or duplicated if data changes during the export.
- **Smaller corrections:**
    - A default table builds its filtered query twice per render, not 6–10 times.
    - `generate_href_html` costs nothing unless SPA mode is on.
    - The tenant list is one query, not 3–4.

## What the original report missed

- **Signed URLs on the default disk:** every ImageColumn counts as private unless its disk is named `public`. So a signed URL is built per image even with no remote storage.
- **Large option lists in a Repeater:** a non-searchable relationship Select inside a Repeater writes the whole related table into every item. That is 1.3 MB of the order edit page here.
- **Uncached hints:** every field's hint is a child schema that is never cached.
- **Validation on save:** a relationship Repeater re-runs validation for each item when it saves.
- **The import's single INSERT:** it has 6 placeholders per job, which reaches SQLite's limit at about 546,000 rows. This is from the code; not run.
- **Breadcrumbs:** `navigationHierarchyInBreadcrumbs()` rebuilds the whole navigation on every Livewire update.

## What we recommend fixing first

Ordered by measured cost against the size of the change.

1. **Default `checkFileExistence` off for remote disks**, and memoise per path within a request (#2, 3.4-a). It is a one-line default and removes most of the 845 ms.
2. **Count selectable records only when someone selects all** (#4). This removes a cost that grows with the table on every render.
3. **Memoise `SelectColumn` and relationship `Select` options** per column and across Repeater clones (3.1-h, #14), as CheckboxList already does.
4. **Rewrite the date operators as ranges** (#17), and give Equals a real `=` (#16).
5. **Load Echo only when broadcasting is on** (#18), and give the tags input a limit (#20).
6. **Stream import chunks** into the batch from a queued job, as exports already do (#7), and keep the relationship lookups for the whole job (#8).

We don't recommend the reflection cache (#1) on its own. It is safe, but it doesn't make anything faster.

## Limits of this testing

- **Database:** SQLite only. The Postgres and MySQL SQL is what Filament generates, read without running it. Index use on those databases is inferred, not measured.
- **Browser:** not covered. The JavaScript findings (per-cell interceptors, dropdown observers, the Tabs observer, editor work per keystroke) need a browser and are not measured yet.
- **Not built into the panel yet:** tenancy, global search, Builder blocks and the media-library plugin.
- **Noise:** on the cloud VM we used, two identical copies of the app differed by 5–10% on a short run. Differences under about 5% need a longer `compare`, run both ways round.

## Reproducing this

```shell
bin/bench setup stock --filament=5.11.4
bin/bench findings stock               # every measurement above, plus JSON in results/
bin/bench setup mine --filament=5.11.4 --patch=your-fix.patch
bin/bench check stock mine             # same output?
bin/bench findings stock mine          # does the problem go away?
bin/bench compare stock mine           # and is it faster?
```

The review of all ~170 items, with code references and a measurement plan for each, is in `findings/review-*.md`.
