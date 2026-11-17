# Tables review: report sections 3.1 and 3.2, items #2 and #4, and the table parts of themes 4–6, checked against Filament v5.11.4

The code is at a `filamentphp/filament` checkout (tag `v5.11.4`, HEAD `6427cc1`). Paths below are relative to `packages/`.
This was a static read. I ran nothing against Filament. To check dependency behaviour I read the vendor sources of an existing app at a scratch Laravel app (read-only): filament v5.11.4, livewire v4.4.8, laravel/framework v13.43.0, league/commonmark 2.10.7, blade-ui-kit/blade-icons.

Some facts many findings depend on:
- Columns are **one instance per table**. Each cell calls `$column->record($record)` on that same instance (`tables/resources/views/index.blade.php:2557-2561`). So a per-instance memo such as `rememberedOptions` lasts for the whole render.
- `evaluate()` still creates `new ReflectionFunction` on every closure call, with no cache (`support/src/Concerns/EvaluatesClosures.php:24-37`). Every "closure evaluated per cell" below pays this cost.
- Deferred filters are on by default (`tables/src/Table/Concerns/HasFilters.php:47`, `$hasDeferredFilters = true`).
- The default disk is `config('filament.default_filesystem_disk') = env('FILESYSTEM_DISK', 'local')` (`support/config/filament.php:44`). It is **not** `public`.

---

### #2 ImageColumn/ImageEntry existence check and presign per image
- **Report:** `checkFileExistence` is on by default, so every image costs a `Storage::exists()`. That is one HEAD request per image on S3/R2. Non-`public` disks also presign a `temporaryUrl()` per image.
- **Verdict:** Confirmed, and slightly worse than reported:
  - (a) Laravel's `exists()` is Flysystem `has()`, which is `fileExists() || directoryExists()`. On S3 a **missing** image therefore costs 2 round trips: HeadObject, then ListObjectsV2. A present image costs 1.
  - (b) Visibility is `'private'` for every disk whose name is not literally `'public'`, including the default `local` disk. So `temporaryUrl()` runs per image. On S3 the presign is local CPU work (SigV4 HMAC) with no network call. On Laravel 11+ `local` with `serve => true` it builds a signed route URL.
  - The checks run only on the items actually shown, because `limit()` slicing happens before them.
- **Where on 5.11.4:**
  - `tables/src/Columns/ImageColumn.php:64` (`$shouldCheckFileExistence = true`), `:229-239` (exists), `:241-249` (temporaryUrl), `:263-279` (visibility rule), `:563-569` (called per state item)
  - `infolists/src/Components/ImageEntry.php:68`, `:237-245`, `:247`, `:575`
  - The cause of (a) is in Laravel: `Illuminate/Filesystem/FilesystemAdapter.php:231-234` and `league/flysystem/src/Filesystem.php:46-51`.
- **Evidence:**
  ```php
  protected bool | Closure $shouldCheckFileExistence = true;
  if (! $storage->exists($state)) { return null; }
  return ($diskName === 'public') ? 'public' : 'private';
  return $this->adapter->fileExists($path) || $this->adapter->directoryExists($path); // flysystem has()
  ```
- **Measure:**
  - Scenario: a 50-row table with `ImageColumn::make('avatar')->disk('s3')`, where the S3 disk points at MinIO or a counting proxy.
    - Record the `HeadObject` and `ListObjectsV2` requests per full render. Expected: 50 HEAD when all files exist, and 50 HEAD + 50 LIST when all are missing.
    - Also count `temporaryUrl()` calls, using a disk adapter decorator or `Storage::buildTemporaryUrlsUsing()` with a counter. Expected: 50 per render.
    - Add `->stacked()->limit(3)` over 5 images per row. Expected: 150 rather than 250.
  - Fix scenario: `->checkFileExistence(false)->visibility('public')` should give 0 HEAD and 0 presign.
  - Default-config control: `FILESYSTEM_DISK=local` gives 50 `stat` calls plus 50 signed-URL builds per render.
  - Same test for `ImageEntry` inside a `RepeatableEntry` with N items. Expected: N HEAD.

### #4 The select-all count hydrates the whole filtered table when `checkIfRecordIsSelectableUsing` is set
- **Report:** `getAllSelectableTableRecordsCount()` runs `->get()` over every page, including eager loads and aggregates, on every render.
- **Verdict:** Confirmed.
  - It is evaluated on every render where selection is enabled and the table is loaded. Selection is enabled by any visible bulk action, or by `selectable()`.
  - Not affected: `selectsCurrentPageOnly()` (it filters the cached page) and `records()` array tables.
  - The suggested fix "`toBase()` with a minimal select" **does not work as written**. `isRecordSelectable()` passes a hydrated `Model` to the user callback (`tables/src/Table/Concerns/HasBulkActions.php:90-101`), so models must be built. Workable fixes:
    - compute the count lazily, only when the selection indicator is shown;
    - or drop eager loads and aggregates and iterate with `lazy()`/`cursor()`, which bounds memory but is still O(N) CPU.
- **Where on 5.11.4:** `tables/src/Concerns/HasBulkActions.php:236-249`. The call site is `tables/resources/views/index.blade.php:163`.
- **Evidence:**
  ```php
  $allSelectableRecordsCount = ($isSelectionEnabled && $isLoaded) ? $getAllSelectableRecordsCount() : null;
  ... $this->hydratePivotRelationForTableRecords((...$this->getFilteredTableQuery())->get());
  return $records->filter(fn (Model | array $record): bool => $this->getTable()->isRecordSelectable($record))->count();
  ```
  `getFilteredTableQuery()` adds `applyEagerLoading()` and `applyRelationshipAggregates()` for every visible column (`tables/src/Concerns/HasRecords.php:51-70`).
- **Measure:**
  - Scenario: a list page with 50 rows per page, 10,000 filtered rows, `->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])`, `->checkIfRecordIsSelectableUsing(fn (Model $record) => $record->status !== 'locked')`, and one `TextColumn::make('author.name')` plus `->counts('comments')`.
  - Record per initial render and per subsequent Livewire round trip (for example `gotoPage(2)`):
    - SQL query count and rows returned (`DB::listen`). Expected: one unpaginated SELECT returning 10,000 rows, plus 1 eager-load query for `author`, with `comments_count` as a subquery.
    - Models hydrated (`Model::retrieved` listener). Expected: about 10,050.
    - Closure evaluations of the selectable callback. Expected: about 10,050 (10,000 for the count plus 50 per-row checks).
    - Peak memory.
  - Control without `checkIfRecordIsSelectableUsing`: 0 extra queries, because it uses `LengthAwarePaginator::total()`.
  - Fix scenario (lazy count): 0 extra queries until the user clicks select-all.
  - Scale rows across 1k, 10k and 100k to show the O(N) slope.

---

## 3.1 Tables: query and state

### 3.1-a Group-header select-all runs a query per group and embeds every key from all pages
- **Report:** each group header calls `getGroupedSelectableTableRecordKeys()` and `json_encode`s every key in that group (all pages) into the HTML. With a selectable check this becomes a full `get()` per group.
- **Verdict:** Confirmed.
  - The header checkbox renders when selection is enabled and `maxSelectableRecords !== 1`. Without a selectable check it runs one `toBase()->pluck(key)` per group header on the page. With a selectable check it runs a full hydrated `get()` per group.
  - The keys cover the whole group across all pages unless `selectsCurrentPageOnly()` is set.
  - **Missed by the report:** the client re-runs `JSON.parse($el.dataset.groupSelectableRecordKeys)` and then `areRecordsSelected()` over all keys inside `x-bind:checked`. That happens on every Alpine reactive update of the selection, per group header. So each checkbox click on the client costs O(keys in visible groups).
- **Where on 5.11.4:**
  - `tables/resources/views/index.blade.php:1236` (content-grid layout), and `:2292`/`:2385` (table layout, before or after the cells; only one of the two renders)
  - `tables/src/Concerns/HasBulkActions.php:161-233`
- **Evidence:**
  ```blade
  data-group-selectable-record-keys="{{ json_encode($this->getGroupedSelectableTableRecordKeys($recordGroupKey)) }}"
  x-bind:checked="const recordsInGroup = JSON.parse($el.dataset.groupSelectableRecordKeys) ..."
  ```
  ```php
  $records = $query->toBase()->pluck(...)          // no selectable check
  $this->hydratePivotRelationForTableRecords($query->get());  // with selectable check
  ```
- **Measure:**
  - Scenario: `->defaultGroup('status')` with 5 statuses on 10,000 rows, 50 per page, and one bulk action.
    - Record the SQL queries per render that come from group headers. Expected: 1 pluck per group header on the page.
    - Record the bytes of the `data-group-selectable-record-keys` attributes. Expected: about 10,000 keys × (key length + 3) in total, across the groups present on the page.
    - In the browser, time per checkbox click (Performance panel), or count `JSON.parse` calls with a wrapper. Expected: one per group header per reactive tick.
  - Add `->checkIfRecordIsSelectableUsing(...)`: each group query becomes a hydrated `SELECT *` with eager loads. Count models hydrated per render. Expected: rows in all visible groups, plus #4's 10,000.
  - Fix (fetch keys on click): 0 queries and 0 key bytes per render.

### 3.1-b `SelectFilter` on a BelongsTo still uses `whereHas` (correlated EXISTS)
- **Report:** relationship SelectFilters always emit `whereHas`. For BelongsTo the fix is to short-circuit to `whereIn(fk)`/`whereNull(fk)`.
- **Verdict:** Confirmed.
  - Constraint on the fix: the short-circuit is only valid when there is no `modifyRelationshipQueryUsing` (also called `modifyQueryUsing`) on the relationship. That callback is applied inside the `whereHas` closure and may add constraints on the related table. `hasEmptyRelationshipOption()` uses `whereDoesntHave`, which is equivalent to `whereNull(fk)` for BelongsTo.
- **Where on 5.11.4:** `tables/src/Filters/SelectFilter.php:217-271` (`whereHas` at `:222`, `whereDoesntHave`/`orWhereDoesntHave` at `:264` and `:267`)
- **Evidence:**
  ```php
  $query->whereHas($this->getRelationshipName(), function (Builder $query) use (...) { ... $query->whereIn($relationshipKey, $queryValues); });
  ```
- **Measure:**
  - Scenario: `SelectFilter::make('author')->relationship('author', 'name')->multiple()` with 2 values selected, on a 1M-row `posts` table with an index on `posts.author_id`.
  - Capture the SQL text of the records query and the COUNT query. Expected: `exists (select * from authors where authors.id = posts.author_id and authors.id in (?,?))`.
  - Run `EXPLAIN`/`EXPLAIN ANALYZE` and time both queries (the COUNT for the paginator and the SELECT).
  - Compare with an equivalent `->query(fn ($q, $data) => $q->whereIn('author_id', $data['values']))` filter. On MySQL and Postgres the planner may semi-join either way, so **wall time is the deciding observable**, not just the SQL shape. Test on MySQL 8 and Postgres 16.

### 3.1-c The filtered query is rebuilt about 6–10× per render, and each build swaps the filter-form state path twice
- **Report:** `getFilteredTableQuery()` runs 6–10 times per render. With deferred filters each run swaps the filter form's state path twice and clears its caches.
- **Verdict:** Partly.
  - The swap-and-clear cost is confirmed: two `statePath()` calls plus `flushCachedAbsoluteStatePaths()` and `clearCachedChildSchemas()` per `applyFiltersToTableQuery()`. It is also confirmed that there is no memo of the base query.
  - The count of 6–10 is **overstated for a default table**. On a plain list page (no summaries, no groups) there are **2 builds**: `index.blade.php:64` (`$hasSummary($this->getAllTableSummaryQuery())`, which is unconditional) and the records fetch. The select-all count uses `LengthAwarePaginator::total()` without a rebuild.
  - The count grows with features:
    - +2 with page and all-table summaries (`components/summary/index.blade.php:32-33`)
    - +1 with grouping and summaries (`:219`)
    - +1 per group summary row (`:1184/:2225/:2697`)
    - +1 per group checkbox (3.1-a)
    - +1 for the trailing-group check (3.1-f)
    - +1 for #4 when a selectable check is set
    - +1 for each active relationship SelectFilter indicator (3.1-p)
  - Each build re-evaluates the `query()` closure, scopes, all filters, search, aggregates and eager loads.
- **Where on 5.11.4:**
  - `tables/src/Concerns/HasRecords.php:31-70`
  - `tables/src/Concerns/HasFilters.php:190-215`
  - `tables/src/Table/Concerns/HasFilters.php:216-237`
  - `tables/resources/views/index.blade.php:64,153,219,1184,2225,2697`
- **Evidence:**
  ```php
  $this->setFiltersFormStatePath('tableFilters'); try { return $callback(); } finally { $this->setFiltersFormStatePath('tableDeferredFilters'); }
  $filtersForm->flushCachedAbsoluteStatePaths(); $filtersForm->clearCachedChildSchemas();
  ```
- **Measure:**
  - Count `filterTableQuery()` calls per render. Wrap it with an override in the test Livewire page and increment a counter.
  - Count `clearCachedChildSchemas()` calls per render on the filter form.
  - Time the PHP render excluding SQL (Server-Timing, or SPX/Xdebug on `filterTableQuery` and `clearCachedChildSchemas`).
  - Configurations:
    - A: default table with 5 filters and no summaries. Expect 2 builds and 4 clears.
    - B: A plus `->summarize(Sum::make())` on 2 columns. Expect 4 builds.
    - C: B plus `->defaultGroup('status')` with 5 groups on the page and a bulk action. Expect about 4 + 5 + 5 + 1 + 1.
  - Repeat C with `->deferFilters(false)` to isolate the swap cost.
  - Fix (memoise the base query per request and clone it): expect 1 build.

### 3.1-d Simple and cursor pagination still run a COUNT when bulk actions exist
- **Report:** with `paginationMode(Simple|Cursor)` and bulk actions, a COUNT still runs (`HasBulkActions.php:251-259`, `HasRecords.php:342-349`).
- **Verdict:** Partly.
  - `getAllSelectableTableRecordsCount()` does fall back to `getFilteredTableQuery()->count()` whenever the paginator is not length-aware. That is a full COUNT on every render once selection is enabled, which defeats the point of simple or cursor pagination.
  - The second cited place, `HasRecords::getAllTableRecordsCount()`, is called **only** from `panels/src/Pages/Concerns/ExposesTableToWidgets.php:16` (and testing helpers), not by normal table rendering.
- **Where on 5.11.4:** `tables/src/Concerns/HasBulkActions.php:251-259`, `tables/src/Concerns/HasRecords.php:341-349`
- **Evidence:**
  ```php
  if ($this->cachedTableRecords instanceof LengthAwarePaginator) { return $this->cachedTableRecords->total(); }
  return $this->getFilteredTableQuery()?->count() ?? $this->cachedTableRecords->count();
  ```
- **Measure:**
  - Scenario: `->paginationMode(PaginationMode::Simple)` (and separately `Cursor`) on 1M rows with one `DeleteBulkAction` in `toolbarActions`.
  - Record the number of `select count(*)` queries and their duration per render. Expected: 1.
  - Control without bulk actions: 0.
  - Separately: a resource list page that uses `ExposesTableToWidgets` with simple pagination. Expected: +1 COUNT from `getAllTableRecordsCount()`.

### 3.1-e Search emits one `whereHas` per relationship search column per word
- **Report:** global search adds a separate `orWhereHas` for every relationship column and every search word, with no grouping.
- **Verdict:** Confirmed.
  - `splitSearchTerms` defaults to true (`tables/src/Table/Concerns/CanSearchRecords.php:36`). A W-word search over C relationship search columns gives W × C EXISTS subqueries.
  - Columns on the same relationship are not merged, and neither are multiple `searchable([...])` attributes of one relationship column.
  - The WHERE clause appears in both the records SELECT and the paginator COUNT.
- **Where on 5.11.4:** `tables/src/Columns/Concerns/InteractsWithTableQuery.php:88-128` (`orWhereHas` at `:109`), `tables/src/Concerns/CanSearchRecords.php:144-200`
- **Evidence:**
  ```php
  foreach ($this->getSearchColumns($query->getModel()) as $searchColumn) { ... return $query->{"{$whereClause}Has"}($relationshipName, fn ... apply_search_constraint(...)); }
  ```
- **Measure:**
  - Scenario: `TextColumn::make('author.name')->searchable()`, `TextColumn::make('author.email')->searchable()` and `TextColumn::make('category.name')->searchable()`, with the search set to `"foo bar baz"`.
  - Count `exists (` occurrences in the records-query SQL. Expected: 3 × 3 = 9, and the same again in the COUNT.
  - Time both queries on 1M posts.
  - Fix (grouping by relationship) should give 2 EXISTS per word (6 in total), or 2 in total if the words are combined inside one EXISTS per relationship.

### 3.1-f The trailing-group check fetches a fully hydrated, eager-loaded model with OFFSET
- **Report:** to decide whether the last group's summary renders, it runs a `skip()->first()` on the full query.
- **Verdict:** Confirmed, but narrower than implied. It runs only when all of these hold:
  - the table is grouped,
  - at least one column has a summarizer (`$hasSummary` guards `index.blade.php:1503/2694`),
  - the table is paginated with more pages,
  - and there are records.
  - It runs once per render. The query includes eager loads, so it is 1 + E queries (one per eager-loaded relationship), plus aggregates.
- **Where on 5.11.4:** `tables/src/Concerns/CanSummarizeRecords.php:21-69`, call sites `tables/resources/views/index.blade.php:1503,2694`
- **Evidence:**
  ```php
  $nextPageFirstRecord = (clone $query)->skip($records->currentPage() * $records->perPage())->first();
  ```
- **Measure:**
  - Scenario: `->defaultGroup('status')`, `TextColumn::make('total')->summarize(Sum::make())` and `TextColumn::make('author.name')`, 50 per page, on page 100 of 1M rows.
  - Record the extra SQL statements per render. Expected: 1 SELECT with OFFSET 5000 LIMIT 1, plus 1 eager-load query for `author`.
  - Record their duration and the models hydrated. Expected: 1 + 1.
  - Fix (`toBase()->select(groupColumn)->offset()->value()`): 1 query, 0 models, 0 eager loads.

### 3.1-g Record resolution for mounted actions re-applies every filter and aggregate, with no memo
- **Report:** `resolveTableRecord()` re-applies all filters and relationship aggregates for a primary-key lookup, on every request, with no memo.
- **Verdict:** Partly.
  - It does re-apply filters (except those that set `shouldExcludeWhenResolvingRecord`) and every visible column's `applyRelationshipAggregates()` (withCount/withSum subqueries) for a `find($key)`.
  - It **is** memoised within a request: `cacheMountedActions()` runs once at boot (`tables/src/Concerns/InteractsWithTable.php:58`) and `getMountedActions()`/`getMountedAction()` reuse `$cachedMountedActions`.
  - The once-per-request lookup is inherent to stateless Livewire. Applying filters is plausibly deliberate (scoping); applying aggregates is so the action sees counts.
  - The editable-column paths (`tables/src/Concerns/HasColumns.php:20,58,121`) also resolve once per call.
- **Where on 5.11.4:** `tables/src/Concerns/HasRecords.php:214-264`, `actions/src/Concerns/InteractsWithActions.php:808-836`, `:637-704`
- **Evidence:**
  ```php
  $query = $this->applyFiltersToTableQuery($this->getTable()->getQuery(isResolvingRecord: true), isResolvingRecord: true);
  foreach ($this->getTable()->getVisibleColumns() as $column) { $column->applyRelationshipAggregates($query); }
  return $query->find($key);
  ```
- **Measure:**
  - Scenario: open an `EditAction` modal on a row, then send 5 `->live()` field updates inside the modal. The table has 3 `->counts()`/`->sum()` columns and 4 active filters.
  - Record the SQL statements per Livewire request that select by primary key. Expected: exactly 1 per request, containing 3 aggregate subqueries and the filter WHEREs.
  - Record their duration, and compare with a plain `Model::find()`.
  - The number of record lookups per request should be 1. If it is more than 1, the memo is broken.

### 3.1-h SelectColumn evaluates `getOptions()` up to 3× per cell, and `isOptionDisabled` per option per cell
- **Report:** `getOptions()` runs up to 3× per cell, and `rememberOptions` is off unless the column uses a relationship.
- **Verdict:** Confirmed, with nuance on what it costs.
  - Native mode is the default (`$isNative = true`). There, `getOptions()` runs **2×** per cell: once in `toEmbeddedHtml()` and once in `getOptionLabel()`. In non-native mode (`->searchable()` or `->native(false)`) it runs **3×**, adding `getOptionsForJs()`.
  - Static array options cost almost nothing, because `evaluate()` returns non-closures immediately.
  - The real cost appears when `->options(fn () => Model::pluck(...))` is a closure: that is 2–3 SQL queries per cell.
  - Enum options rebuild `cases()`/`getLabel()` on every call.
  - `->relationship()` sets `rememberOptions()` (`:596`). Because the column instance is shared, its options are then computed once per render.
  - `isOptionDisabled()` loops over an empty array unless `disableOptionWhen()` is set, so it is free by default. With a closure it costs options × cells evaluations: in the native `<option>` loop, or inside `transformOptionsForJs`.
- **Where on 5.11.4:** `tables/src/Columns/SelectColumn.php:957,962,966` (calls), `:398` (in `getOptionLabel`), `:382` (`getOptionsForJs`), `:771-778` (`getOptions`/remember), `:126-133` (transform with `isOptionDisabled`), `:1077-1078` (native loop), `:594-596`
- **Evidence:**
  ```php
  $options = $this->getOptions(); ... $optionLabel = $this->getOptionLabel(); $optionsForJs = $isNative ? [] : $this->getOptionsForJs();
  if ($this->areOptionsRemembered()) { return $this->rememberedOptions ??= $this->getBaseOptions(); }
  ```
- **Measure:**
  - Scenario: `SelectColumn::make('category_id')->options(fn () => Category::pluck('name', 'id'))`, 50 rows.
    - SQL queries on `categories` per render. Expected: 100 native, 150 with `->native(false)`.
    - Fix with `->rememberOptions()`: 1.
  - Scenario: `->options([...200 static options])->disableOptionWhen(fn ($value) => $value === 'x')`.
    - Closure evaluations of the disable callback per render. Expected: 200 × 50 = 10,000.
  - Control with static options and no `disableOptionWhen`: 0 queries and 0 closure evaluations.

### 3.1-i The `Count::icons()` summarizer is an N+1
- **Report:** one `count()` per distinct value, per summary row.
- **Verdict:** Confirmed. It runs 1 `DISTINCT pluck` plus D `count()` queries per summary row. Summary rows are the page summary, the all-records summary, and each group summary.
- **Where on 5.11.4:** `tables/src/Columns/Summarizers/Count.php:31-61`
- **Evidence:**
  ```php
  foreach ($query->clone()->distinct()->pluck($attribute) as $value) { ... $state[$color][$iconKey] += $query->clone()->where($attribute, $value)->count(); }
  ```
- **Measure:**
  - Scenario: `IconColumn::make('status')->summarize(Count::make()->icons())` where `status` has 6 distinct values, with page and all summaries visible.
  - Record the SQL queries from the summarizer per render. Expected: 2 × (1 + 6) = 14. With `->defaultGroup()` and G groups on the page, add G × 7.
  - Fix (one `GROUP BY attribute` with `count(*)`): 1 query per summary row.

### 3.1-j The summary query is built even when no column has a summarizer
- **Report:** `index.blade.php:64` and `:219` build the summary query unconditionally.
- **Verdict:** Partly.
  - `:64` always calls `getAllTableSummaryQuery()`, which is a full `getFilteredTableQuery()` build with the filter-form swap. That is half of the default 2 builds in 3.1-c.
  - **No SQL is executed**, because `hasSummary()` only inspects the columns.
  - `:219` only runs when grouped. It then calls `getTableSummarySelectedState()`, which returns `[]` before any SQL when there are no summarizers.
- **Where on 5.11.4:** `tables/resources/views/index.blade.php:64,219`, `tables/src/Concerns/CanSummarizeRecords.php:71-75,156-158`
- **Evidence:**
  ```blade
  $hasSummary = $hasSummary($this->getAllTableSummaryQuery());
  ```
- **Measure:** use the same counter as 3.1-c. A default table with no summarizers shows `filterTableQuery()` = 2 per render. With the fix (check `hasSummary(null)` first, or build lazily) it should be 1. SQL query count is unchanged, so measure PHP time.

### 3.1-k Record locales are re-applied to every record on every `getTableRecords()` call
- **Report:** with a translatable driver, `setRecordLocale` runs for all cached records on every `getTableRecords()` call.
- **Verdict:** Confirmed. It only applies when `makeFilamentTranslatableContentDriver()` returns a driver, which needs a translatable plugin; there is none in this monorepo.
  - `getTableRecords()` runs about 1–4 times per render: once for records, twice in `getPageTableSummaryQuery()`, once in the trailing-group check, and in `selectsCurrentPageOnly` paths.
- **Where on 5.11.4:** `tables/src/Concerns/HasRecords.php:175-187`
- **Evidence:**
  ```php
  if ($this->cachedTableRecords) { return $setRecordLocales($this->cachedTableRecords); }
  ```
- **Measure:** with a translatable-plugin page (for example LaraZeus/Spatie translatable on a resource) and 50 rows, count `setRecordLocale()` calls per render. Expected: 50 × the number of `getTableRecords()` calls (about 50–200). With the fix (apply once on fetch) it should be 50.

### 3.1-l Relationship summarizers run one query per summary row
- **Report:** summarizers on relationship columns are not batched, so each summary row runs its own query.
- **Verdict:** Confirmed. Relationship columns are skipped in the batched select (`CanSummarizeRecords.php:121-123`), and `Summarizer::getState()` then runs its own `whereHas` subquery per summary row.
- **Where on 5.11.4:** `tables/src/Columns/Summarizers/Summarizer.php:72-169`, `tables/src/Concerns/CanSummarizeRecords.php:121-123`
- **Evidence:**
  ```php
  if ($column->hasRelationship($query->getModel())) { continue; }   // excluded from batch
  $query = $relationship->getQuery()->getModel()->newQuery()->whereHas($inverseRelationship, ...);
  ```
- **Measure:** with `TextColumn::make('items.price')->summarize(Sum::make())` and page plus all summaries, count the summarizer queries per render. Expected: 2, plus 1 per group summary row. With `->defaultGroup()` and 10 groups on the page, expect 12.

### 3.1-m The cell state cache key goes through Livewire on each `getState()`
- **Report:** `getStateCacheKey()` calls into Livewire for every `getState()`.
- **Verdict:** Confirmed, and minor. Each `cacheState()` (every `getState()`/`getRelatedRecords()` lookup) calls `getTable()->hasPivotRecordKeys()` and `getLivewire()->getTableRecordKey($record)`. The column has already been given `recordKey($recordKey)` in the row loop (`index.blade.php:2561`), so that value could be reused.
- **Where on 5.11.4:** `support/src/Concerns/HasCellState.php:618-677`
- **Evidence:**
  ```php
  return $this->getLivewire()->getTableRecordKey($record);
  ```
- **Measure:** count `getTableRecordKey()` calls per render of 50 rows × 10 columns. Expected: several per cell, in the low thousands. Report PHP time with SPX. Low priority.

### 3.1-n The column manager writes the full column array to the session on every boot
- **Report:** the column manager calls `session()->put()` with the whole column array on every boot.
- **Verdict:** Partly.
  - `persistTableColumns()` → `session()->put()` does run on every Livewire boot, because `persistsColumnsInSession` defaults to **true** (`tables/src/Table/Concerns/HasColumnManager.php:39`). This happens even for tables with no toggleable columns; it is skipped only for `columnsLayout` tables.
  - However, Laravel's session store serialises and writes the whole session on every request anyway, so there is **no extra storage write**. The real costs are:
    - the session payload grows by one column array per distinct table visited;
    - that array is read and written on every request site-wide;
    - and it is re-serialised.
- **Where on 5.11.4:** `tables/src/Concerns/HasColumnManager.php:36-47,79-103,198-206`, `tables/src/Concerns/InteractsWithTable.php:62`
- **Evidence:**
  ```php
  $this->persistTableColumns();   // end of applyTableColumnManager(), called from initTableColumnManager() on every boot
  if ($this->getTable()->persistsColumnsInSession()) { session()->put($this->getTableColumnsSessionKey(), $this->tableColumns); }
  ```
- **Measure:**
  - Record the session payload size in bytes (the `sessions.payload` length with the database driver) after visiting 1, 10 and 30 resource list pages, each with 15 columns.
  - Count `session()->put` calls per Livewire request on a table page. Expected: ≥1.
  - Control: `->persistColumnsInSession(false)`.
  - Fix (write only when changed): same bytes, 0 puts on unchanged requests. The size growth remains, because it is by design.

### 3.1-o Filter indicators run a label query per render
- **Report:** active relationship SelectFilters query their label on every render to build the indicator.
- **Verdict:** Confirmed, for an active relationship `SelectFilter` only. Inactive filters return early.
  - Each render builds the indicators (`index.blade.php:53`). For an active filter this runs `withAppliedFiltersFormState()` (2 more state-path swaps and cache clears) and then `Select::getOptionLabel()`/`getOptionLabels()`, which for `->relationship()` evaluates a label query.
- **Where on 5.11.4:** `tables/src/Filters/SelectFilter.php:55-166`, `forms/src/Components/Select.php:572-624`
- **Evidence:**
  ```php
  $relationshipOptionLabels = $filter->getTable()->withAppliedFiltersFormState(static function () use ($filter): ?array { ... $select->getOptionLabel(withDefault: false) ... });
  ```
- **Measure:** with `SelectFilter::make('author')->relationship('author', 'name')` set to one value, count the queries on `authors` per render (expected ≥1, measured on any round trip such as `gotoPage`). Compare with the filter unset (0). Fix target: 0 when the state is unchanged, or a cached label.

---

## 3.2 Tables: rendering

### 3.2-a ImageColumn evaluates `getDiskName` and visibility 2–3× per image
- **Report:** besides #2, `getDiskName` and visibility are evaluated 2–3 times per image.
- **Verdict:** Partly. The repeat count is accurate:
  - `getDiskName` runs 2× per image (in `getDisk()` and `getVisibility()`).
  - `getCustomVisibility` runs 1–3× (once directly, plus once inside each `getDiskName` when the default disk is `public`).
  - `shouldCheckFileExistence` runs 1×.
  - It only costs something when `disk()`/`visibility()` are closures, which then pay reflection each time. Static values return immediately, and `Storage::disk()` instances are cached by `FilesystemManager`.
- **Where on 5.11.4:** `tables/src/Columns/ImageColumn.php:144-173,220-287`
- **Evidence:**
  ```php
  $storage = $hasState ? $this->getDisk($state, $relatedRecord) : ...; ... $this->getVisibility($state, $relatedRecord) // → getCustomVisibility + getDiskName again
  ```
- **Measure:** with `->disk(fn ($record) => $record->disk)->visibility(fn () => 'private')` over 50 rows, count the disk closure calls (expected 100) and the visibility closure calls (expected 50–150) per render. Fix target: 50 and 50.

### 3.2-b SelectColumn embeds the full options list in every row
- **Report:** the full options list goes into every row's `x-data` (non-native) or into `<option>`s (native), so HTML size is O(rows × options).
- **Verdict:** Confirmed. In non-native mode `options: Js::from($optionsForJs)` goes into each cell's `x-data`. In native mode, which is the default, every option is rendered as `<option>` per cell.
- **Where on 5.11.4:** `tables/src/Columns/SelectColumn.php:996` (x-data), `:1072-1083` (native `<option>` loop)
- **Evidence:**
  ```php
  options: ' . Js::from($optionsForJs) . ',
  <?php foreach ($options as $value => $label) { ?> <option ... value="<?= e($value) ?>"><?= e($label) ?></option>
  ```
- **Measure:**
  - Record the bytes of the Livewire response HTML (`effects.html` length, or the initial page HTML) for 50 rows × `SelectColumn::make('country')->options(<200 countries>)`.
  - Native: expect about 50 × 200 × (about 40 bytes) ≈ 400 KB of `<option>`.
  - `->native(false)`: expect about 50 × 200 × (about 60 bytes of JSON) ≈ 600 KB in `x-data`.
  - Also record the DOM node count after load (native: about 10k `<option>` nodes) and the time to morph on `gotoPage`.
  - Fix (options fetched once per column, or lazily on open): about 200 × 1.

### 3.2-c Searchable SelectColumn builds the full dropdown DOM up front, plus one `document` click listener per cell
- **Report:** each non-native SelectColumn cell renders its whole option list into the DOM on init and adds a `document` click listener.
- **Verdict:** Confirmed, with two corrections:
  - Rendering is capped by `optionsLimit`, which defaults to 50 (`SelectColumn.php:92`). So it is up to 50 `<li>` elements (each with its own click listener) per cell at construction, not the full list.
  - The component is `x-load`ed eagerly with no lazy strategy, so every cell constructs a `Select` on page load. Each `Select` adds a `document` click listener, and per-element listeners on the button, dropdown and container.
- **Where on 5.11.4:** `support/resources/js/utilities/select.js:286` (`renderOptions()` in `render()`), `:1120` (`document.addEventListener('click', …)`), `:572` (per-option click listener), `tables/resources/js/components/columns/select.js:47-50`
- **Evidence:**
  ```js
  this.renderOptions()   // in render(), before any open
  document.addEventListener('click', this.documentClickListener)
  ```
- **Measure:**
  - In agent-browser, inject an init script that wraps `EventTarget.prototype.addEventListener` and counts calls with `this === document && type === 'click'`.
  - Load 50 rows × `SelectColumn::make('category_id')->options(<200>)->searchable()`. Expected: about 50 document click listeners, plus a baseline.
  - Also record the count of `li.fi-select-input-option` (or whatever option class the DOM uses) right after load (expected 50 × 50 = 2,500) and the time from navigation to idle.
  - Fix (render options on first open, use one delegated document listener): 1 listener and 0 option nodes before any open.

### 3.2-d Each editable cell registers its own global Livewire hook
- **Report:** `editable-table-column.js` registers a `Livewire.hook('commit')` per editable cell, which runs on every round trip.
- **Verdict:** Changed in 5.x, but still true in substance.
  - On Livewire 4 the code uses `Livewire.interceptMessage(...)` (global), not `Livewire.hook('commit')`. There is still one global interceptor per editable cell (in `initEditableColumn()`).
  - Each interceptor runs for every message of every component, and returns early on a component-id mismatch.
  - For the table's own messages, each one registers `onSuccess` → `onMorphed` callbacks, which queue a `$nextTick(syncServerState)` when HTML is returned.
  - Livewire 4 has a per-component `interceptComponentMessage`/`$wire.$interceptMessage` that would cut the cost to one per component (found in `livewire.esm.js:12585`, v4.4.8).
- **Where on 5.11.4:** `tables/resources/js/utilities/editable-table-column.js:31-65`
- **Evidence:**
  ```js
  this.unsubscribeLivewireHook = Livewire.interceptMessage(({ message, onSuccess }) => { if (message.component.id !== this.$wire.$id) { return } ... onSuccess(({ payload, onMorphed }) => onMorphed(() => { ... this.$nextTick(() => { ... this.syncServerState() }) })) })
  ```
- **Measure:**
  - Wrap `Livewire.interceptMessage` in an init script and count registrations after load. 100 rows × (`ToggleColumn` + `TextInputColumn` + `CheckboxColumn` + `SelectColumn`) should give 400.
  - On one `gotoPage` or sort round trip, count `syncServerState` calls (expected 400) and the scripting time between response and idle.
  - Fix: 1 interceptor per table component.

### 3.2-e `watchForCheckboxClicks` is re-bound for every Livewire `element.init`
- **Report:** the table re-binds its delegated checkbox listener every time Livewire fires `element.init`, although binding once is enough.
- **Verdict:** Confirmed.
  - In Livewire 4.4.8, `element.init` fires for every element that carries a `wire:` directive while Alpine initialises it (`livewire.esm.js:16020-16046`). That includes each row's `wire:key`, `wire:loading`, `wire:target` and so on.
  - Each event aborts and recreates an `AbortController` and re-adds the delegated listener on `$root`. Only one listener is ever live, so the cost is churn, not a leak. It is O(new wire-elements) per morph.
- **Where on 5.11.4:** `tables/resources/js/components/table.js:88-97,510-526`
- **Evidence:**
  ```js
  this.unsubscribeLivewireHook = Livewire.hook('element.init', ({ component }) => { if (component.id === this.livewireId) { this.watchForCheckboxClicks() } })
  ```
- **Measure:** wrap `watchForCheckboxClicks` (via `Alpine.$data(tableEl)`) and count calls on initial load and on `gotoPage(2)`. Use 50 rows, a bulk action, and 3 record actions. Expected: hundreds per round trip. Fix target: 1 on load and 0 per morph.

### 3.2-f A new `NumberFormatter` is created for every money or number cell
- **Report:** `money()` and `numeric()` construct a new `NumberFormatter` per cell.
- **Verdict:** Confirmed. `numeric()` without explicit separators calls `Number::format()`, and `money()` calls `Number::currency()`. In Laravel 13.43, both run `new NumberFormatter(...)` on every call (`Illuminate/Support/Number.php:40,189`).
  - Each cell also evaluates the `formatStateUsing` closure (with reflection) and 3–5 `evaluate()` calls on mostly non-closure arguments.
  - The TextColumn fast path still calls `formatState()`, so the fast path does not avoid this.
- **Where on 5.11.4:** `tables/src/Columns/Concerns/CanFormatState.php:252` (currency), `:293` (format)
- **Evidence:**
  ```php
  return Number::currency($state, $currency, $locale, $decimalPlaces);
  return Number::format($state, $decimalPlaces, $column->evaluate($maxDecimalPlaces, ...), $locale);
  ```
- **Measure:**
  - Count `NumberFormatter::__construct` calls per render (SPX/Xdebug function trace, or call-count in a profiler) for 50 rows × 4 `->money('usd')`/`->numeric()` columns. Expected: 200.
  - Record the PHP render time for those columns, against the same columns with `->numeric(thousandsSeparator: ',')`, which takes the `number_format()` path with no formatter.
  - Fix (cache a formatter per locale, style and precision): 1–2 constructions.

### 3.2-g A new CommonMark converter is built per markdown cell, and `isMarkdown` is evaluated twice
- **Report:** `->markdown()` cells build a new CommonMark converter each, and `isMarkdown` is checked twice.
- **Verdict:** Confirmed.
  - `Str::markdown()` constructs `new GithubFlavoredMarkdownConverter($options)` (environment plus all GFM extensions) on every call (`Illuminate/Support/Str.php:830-842`). It then sanitises with the request-scoped `HtmlSanitizer` (`support/src/SupportServiceProvider.php:132-137,326-328`).
  - `isMarkdown()` runs inside `isHtml()` (`:507`, because `isHtml` is false for markdown) and again at `:419`. That only matters when `markdown()` is given a closure.
- **Where on 5.11.4:** `tables/src/Columns/Concerns/CanFormatState.php:389,419-427,503-508`
- **Evidence:**
  ```php
  if ($this->isMarkdown($stateItem, $relatedRecord)) { $state = Str::markdown($state, $this->getCommonMarkOptions(...), $this->getCommonMarkExtensions(...)); }
  ```
- **Measure:**
  - Count `GithubFlavoredMarkdownConverter::__construct` (or `League\CommonMark\Environment\Environment::__construct`) calls per render for 50 rows × `TextColumn::make('excerpt')->markdown()`. Expected: 50.
  - Record the PHP time per cell. A typical converter build is in the 100s of µs.
  - Fix (one converter per options and extensions set): 1.

### 3.2-h TextColumn drops its optimised path for any `HasIcon` enum, even when there is no icon
- **Report:** `canRenderOptimized()` returns false for any state implementing `HasIcon`, so such enum cells always take the slow path.
- **Verdict:** Confirmed.
  - The check rejects the fast path for any state implementing `Filament\Support\Contracts\HasIcon`, whatever `getIcon()` returns.
  - Practical caveat: most `HasIcon` enums return an icon. The suggested `filled($state->getIcon())` check only helps cases that return null. A broader fix would be to render enum icons in the fast path.
- **Where on 5.11.4:** `tables/src/Columns/TextColumn.php:188-215` (check at `:194`)
- **Evidence:**
  ```php
  $state instanceof HasIconInterface   // → return false
  ```
- **Measure:**
  - Record the render time of 50 rows × 5 `TextColumn::make('status')->badge()` columns where the enum implements `HasLabel, HasColor, HasIcon` and `getIcon()` returns null.
  - Compare with the same enum without `HasIcon`, and record the time difference.
  - Also record HTML bytes per cell, since the slow path emits more wrapper markup.
  - Fix scenario: equal time and bytes in both cases.

### 3.2-i Row-level work is repeated in every cell
- **Report:** record-link HTML, the extra record-link attributes, the record-action JS and the cell label are computed per cell rather than per row.
- **Verdict:** Partly.
  - Confirmed per cell when the table has a record URL (`recordUrl()`, which is the default on resources with an edit/view page):
    - `generate_href_html()`, which runs `FilamentView::hasSpaMode($url)`: `is_app_url()` plus `Str::is` only when SPA URL exceptions are configured;
    - in SPA mode without prefetch, an inline `x-on:click="… Alpine.navigate(…)"` string of about 150 bytes per cell;
    - `getExtraRecordLinkAttributeBag($record)`, a closure call only if `extraRecordLinkAttributes` is set.
  - When the table has a record action instead, each cell computes `getTable()->getAction($recordAction)` and `Js::from([...])`.
  - `e($column->getLabel())` runs per cell only with `->stackedOnMobile()`, which is off by default.
- **Where on 5.11.4:** `tables/resources/views/index.blade.php:2557-2614`, `support/src/helpers.php:163-184`, `support/src/View/ViewManager.php:151-166`
- **Evidence:**
  ```blade
  {{ \Filament\Support\generate_href_html($columnUrl ?: $recordUrl, ..., hasNestedClickEventHandler: true) }}
  $columnWireClickAction = "mountAction('{$recordAction}', {}, " . Js::from(['table' => true, 'recordKey' => $recordKey]) . ')';
  ```
- **Measure:**
  - Scenario: 50 rows × 10 columns, `->recordUrl(fn ($r) => EditPost::getUrl([$r]))`, panel `->spa()`.
  - Count `generate_href_html` calls (expected 500) and `is_app_url` calls (expected 500) per render.
  - Count the bytes of `Alpine.navigate` handler strings in the HTML (expected 500 × about 150 B ≈ 75 KB).
  - Fix (per-row computation): 50 calls, and the handler moved to one delegated listener.

### 3.2-j The `LOADING_TARGETS` string is imploded per editable control and per row checkbox
- **Report:** the roughly 300-byte `LOADING_TARGETS` list is imploded for every editable control and every row checkbox.
- **Verdict:** Partly.
  - In `index.blade.php` the implode is already hoisted to once per render (`:226`, `$loadingTargetsWireTarget`), so the PHP cost for row checkboxes is gone.
  - Editable columns still implode per cell: `SelectColumn.php:1022`, `TextInputColumn.php:255`, `ToggleColumn.php:77`, `CheckboxColumn.php:64`. The PHP cost is negligible.
  - What remains real is the **HTML bytes** (the string is 299 bytes) and Livewire's per-element `wire:loading` setup on the client, repeated for every row checkbox and every editable control.
- **Where on 5.11.4:** `tables/src/Table.php:58-79`, `tables/resources/views/index.blade.php:226,2529,2656`, plus the column files above
- **Evidence:**
  ```blade
  $loadingTargetsWireTarget = implode(',', Table::LOADING_TARGETS);
  wire:target="{{ $loadingTargetsWireTarget }}"
  ```
- **Measure:** record the bytes of `wire:target="activeTab,…"` occurrences in the HTML for 50 rows × (checkbox + 3 editable columns). Expected: 200 × about 310 B ≈ 62 KB. Also record the count of elements with `wire:loading` (200). Fix (a target on the table container, or a shorter alias): about 0.

### 3.2-k Identical icon SVGs are rendered per cell
- **Report:** IconColumn renders an SVG per cell, and ToggleColumn renders 2–3 per cell.
- **Verdict:** Partly.
  - IconColumn does emit one inline SVG per cell. blade-icons caches file contents per request (`BladeUI\Icons\Factory::$cache`), so the cost is string building and HTML bytes, not disk I/O.
  - ToggleColumn emits 2–3 SVGs **only when `onIcon()`/`offIcon()` are set**. Both default to null (`forms/src/Components/Concerns/HasToggleIcons.php:11-13`) and `generate_icon_html()` returns null for a blank icon.
- **Where on 5.11.4:** `tables/src/Columns/IconColumn.php:348`, `tables/src/Columns/ToggleColumn.php:134,138,156`, `support/src/helpers.php:191-242`
- **Evidence:**
  ```php
  <?= generate_icon_html($offIcon, size: IconSize::ExtraSmall)?->toHtml() ?>   // null by default
  ```
- **Measure:**
  - Record the SVG bytes in the HTML for 50 rows × `IconColumn::make('is_active')->boolean()` (expected 50 SVGs) and for `ToggleColumn::make('x')->onIcon(Heroicon::Check)->offIcon(Heroicon::XMark)` (expected 100–150 SVGs).
  - Control: a plain `ToggleColumn` should have 0.
  - Fix (`<use href="#sprite">`): about 50 B per icon.

### 3.2-l `getAlpineComponentSrc()`, `__()` and Tippy per editable or tooltip cell
- **Report:** each editable cell resolves its Alpine component URL and translates its update messages, and each tooltip cell gets its own Tippy instance.
- **Verdict:** Confirmed, all small.
  - `getAlpineComponentSrc()` runs per editable cell. Each call does `Arr::flatten`, a linear scan, `asset()`, `config()` and `InstalledVersions::getVersion()` (plus `sha256` on dev versions).
  - `getUpdateStateMessages()` makes 3 `__()` calls per editable cell. SelectColumn adds about 8 more `__()`/message getters per cell.
  - `x-tooltip` creates one Tippy per tooltip element through `@ryangjchandler/alpine-tooltip`.
- **Where on 5.11.4:** `support/src/Assets/AssetManager.php:112-126`, `support/src/Assets/AlpineComponent.php:19-22`, `support/src/Assets/Asset.php:65-106`, `tables/src/Columns/Concerns/CanUpdateState.php:48-55`, `tables/src/Columns/SelectColumn.php:975-1005`
- **Evidence:**
  ```php
  'x-load-src' => FilamentAsset::getAlpineComponentSrc('columns/select', 'filament/tables'),
  ```
- **Measure:**
  - Count `getAlpineComponentSrc` calls per render. 50 rows × 4 editable columns should give 200; the fix target is 4.
  - Count `Translator::get` calls per render with SelectColumn: expect about 11 per cell.
  - Count Tippy instances after load (`document.querySelectorAll('[data-tippy-root]')` only shows open ones, so count `_tippy` properties on elements instead) for 50 rows × 2 tooltip columns. Expected: 100.

---

## Cross-cutting theme items (table parts)

### T5-a NumberFormatter and the CommonMark converter rebuilt per cell (theme 5)
- **Report:** same claim as 3.2-f and 3.2-g.
- **Verdict:** Confirmed. See those two findings. Line numbers on 5.11.4 are `CanFormatState.php:252,293,420`, unchanged.
- **Measure:** as in 3.2-f and 3.2-g.

### T5-b `getAlpineComponentSrc()` resolved per editable cell (theme 5)
- **Verdict:** Confirmed. See 3.2-l.

### T4-a `SelectFilter` `whereHas` for BelongsTo, and search `whereHas` per column per word (theme 4)
- **Verdict:** Confirmed. See 3.1-b and 3.1-e. The tenancy `whereHas` bullet is in panels, outside this area.

### T6-a SelectColumn options evaluated 3× per cell and embedded per row (theme 6)
- **Verdict:** Partly, as described in 3.1-h and 3.2-b. It is 2× in the default native mode and 3× in non-native mode, and it only costs queries when the options are a closure.

### T6-b Record-link HTML and record-action JS computed per cell (theme 6)
- **Verdict:** Confirmed. See 3.2-i.

### T6-c Each editable cell registers a global Livewire hook (theme 6)
- **Verdict:** Changed API, same problem. See 3.2-d.

### T6-d Each dropdown instance gets its own global listeners and observers (theme 6, relevant to per-row `ActionGroup`)
- **Report:** every dropdown instance adds its own window, document and observer listeners.
- **Verdict:** Confirmed, though the code is in support. Each dropdown component's `init()` (`dropdown.js`) adds:
  - `window` `resize` and `document` `livewire:navigate` listeners;
  - one `MutationObserver` on the panel and trigger;
  - one `ResizeObserver` on the trigger;
  - 4 panel listeners for menus.
  - With `->recordActions([ActionGroup::make([...])])` that is one set per row.
- **Where on 5.11.4:** `support/resources/js/components/dropdown.js:66-110,144-201`
- **Measure:** with an `addEventListener` and observer-constructor counting init script, load 50 rows with a record `ActionGroup`. Expected: 50 window `resize` listeners, 50 MutationObservers and 50 ResizeObservers. Also record the scripting time of a window resize.

### T6-e The loading spinner SVG is inlined per button (theme 6)
- **Verdict:** Partly (support code; noted briefly). `generate_loading_indicator_html()` caches the `LoadingIndicator` instance in a `static`, but still builds and inlines the SVG string per call (`support/src/helpers.php:245-260`). Each record action button carries one.
- **Measure:** count the bytes of `fi-loading-indicator` SVGs in the HTML for 50 rows × 3 record actions.

### T1-a Default date formats are closures, so they pay reflection per cell (theme 1, table-related; outside the assigned list but in `CanFormatState`)
- **Report:** the default formats for `dateTime()`, `isoDate()`, `isoDateTime()`, `time()` and `isoTime()` are closures, so each cell pays a closure evaluation just to get a constant.
- **Verdict:** Confirmed.
  - `dateTime()`, `isoDateTime()`, `time()` and `isoTime()` default `$format` to `fn (TextColumn $column) => …`, which `evaluate()` reflects on per cell. Plain `date()` passes null and falls back to `getDefaultDateDisplayFormat()` with no closure.
  - So a `dateTime()` cell makes 2 closure evaluations (`formatStateUsing` plus the format) against 1 for `date()`.
- **Where on 5.11.4:** `tables/src/Columns/Concerns/CanFormatState.php:86,97,116,303,314`
- **Measure:** count `ReflectionFunction` constructions, or `evaluate()` calls with a Closure, per render for 50 rows × 3 `->dateTime()` columns (expected 300) against `->dateTime('Y-m-d H:i')` (expected 150).

---

## Problems the report missed (tables area)
1. **Group-checkbox client cost:** `x-bind:checked` calls `JSON.parse` on the whole group's key list and scans it on every selection change, per group header (`index.blade.php:1249-1262` and the equivalents at 2292 and 2385). See 3.1-a.
2. **The fix suggested for #4 is invalid as written.** `isRecordSelectable()` needs hydrated models, so `toBase()` cannot be used. Lazy evaluation is the real fix.
3. **On S3 a missing image costs 2 requests** (HEAD + LIST) through Flysystem `has()`. See #2.
4. **The default `FILESYSTEM_DISK=local` makes every ImageColumn "private"**, so a signed URL is built per image even with no remote storage. See #2.
5. **The default table makes 2 filtered-query builds per render, not 6–10.** The build count is a feature multiplier, so the benchmark should report it per configuration. See 3.1-c.
