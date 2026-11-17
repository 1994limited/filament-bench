# Review: actions/import/export, query builder, media-library/tags/settings plugins, frontend JS

Target: Filament **v5.11.4** (a `filamentphp/filament` checkout, HEAD `6427cc1`). Paths are relative to `packages/` unless prefixed `repo:`.
Dependency versions from `composer.lock`: laravel/framework v13.34.0, league/csv 9.28.0. Framework/league sources were read at those tags from GitHub (no `vendor/` and no `node_modules/` in the checkout).
Method: static reading only. Nothing was executed except `wc -c` / `gzip -c | wc -c` on the shipped `dist` files.

---

## 3.7 Actions, import and export

### #7 ImportAction materialises the whole CSV and every job inside the HTTP request
- Report: `collect($generator)` → serialize → base64 per chunk → a single `Bus::batch()`; a 1M-row file builds 10k jobs in the request.
- Verdict: **Confirmed, and worse than stated.** The file is also read in full **twice** in the request, and the database queue inserts every job in **one** multi-row INSERT.
- Where on 5.11.4: `actions/src/ImportAction.php:189-262` (action closure), `actions/src/Imports/ImportDispatcher.php:23-118`, `support/src/ChunkIterator.php`, `actions/src/Imports/Jobs/ImportCsv.php:46-56`
- Evidence, traced in request order:
  1. `ImportAction.php:201-210`: `$csvResults = (new Statement)->process($csvReader); $totalRows = $csvResults->count();`. In league/csv 9.28 `ResultSet::count()` is `iterator_count($this->records)`, so this is **full pass #1** over the file. `maxRows` is only checked after this (`:211-226`).
  2. `:230-238`: the `Import` row is saved.
  3. `:240`: `new ChunkIterator($csvResults->getRecords(), chunkSize: $action->getChunkSize())`. The generator calls `rewind()` and reads again, which is **full pass #2**. The default chunk size is 100 (`:52`).
  4. `ImportDispatcher.php:29-35`:
     ```php
     $importJobs = collect($importChunks)
         ->map(fn (array $importChunk): object => app($job, [
             'import' => $import,
             'rows' => base64_encode(serialize($importChunk)),
     ```
     `collect()` drains the generator into an array of every chunk, and `map` then builds every job object, so all rows are held in memory twice (the raw arrays plus the base64 strings). Each `ImportCsv` constructor also builds an `Importer` (`ImportCsv.php:52-55` → `Import::getImporter()` → `app($importer)`), and that `Importer` is serialised into the job payload along with its `Import` model. The job does not mark it as excluded, and `SerializesModels` only converts top-level models.
  5. `:44 Bus::batch($importJobs->all())…->dispatch()`. In Laravel 13.34 `PendingBatch::dispatch()` stores the batch and calls `Batch::add($jobs)`. That runs `withBatchId` on every job, then inside a transaction `incrementTotalJobs` and `$queue->bulk($jobs)`. With the **database** queue, `DatabaseQueue::bulk()` builds a payload for every job (another `serialize`, then JSON) and runs **one** `insert()` holding all rows, 6 bindings per job. With **Redis**, `bulk()` loops `push()` per job inside a pipeline/transaction.
  - Consequence I derived (not run): one INSERT of `N_jobs × 6` placeholders hits the bind limits. SQLite 3.32+ allows 32,766, so it fails above ~5,461 jobs (~546k rows at chunk 100). Postgres and MySQL native prepares allow 65,535, so they fail above ~10,922 jobs (~1.09M rows). On MySQL the statement can also exceed `max_allowed_packet` (64 MB default) well before that, because the payload holds the whole CSV base64-encoded.
- Measure:
  - For the HTTP request that submits the import modal with 10k / 100k / 1M-row CSVs (≈100 B per row, 3 plain columns): peak memory (`memory_get_peak_usage(true)` in a terminating middleware), wall time, `count(Bus::batch jobs)` (= `ceil(rows/chunkSize)`), and the number and byte size of the queue INSERT statements (`DB::listen`, `strlen($sql)` + `count($bindings)`).
  - Also count bytes read from the CSV stream during the request; expect ≈ 2× the file size.
  - Configuration: `ImportAction::make()->importer(X::class)`, `QUEUE_CONNECTION=database` (and separately `redis`), default `chunkSize(100)`, no `maxRows`.
  - Repeat on SQLite, MySQL and Postgres to find the row count at which the INSERT fails ("too many SQL variables" / "prepared statement contains too many placeholders" / packet too large).
  - Also run with `->maxRows(1000)` on a 1M-row file to show that the full count pass still happens before rejection (see 3.7-g).

### #8 Import relationship lookup cache is cleared on every row
- Report: one `first()` per row per relationship column even with ~10 distinct values; BelongsToMany adds 1+N `exists()` per row.
- Verdict: **Confirmed.** The cache only removes duplicates within a single row: the validation rule closure and `fillRecord` both call `resolveRelatedRecord`, so that pair costs 1 query instead of 2.
- Where on 5.11.4: `actions/src/Imports/Importer.php:76-83`, `actions/src/Imports/ImportColumn.php:413-458` (validation closure), `:467-517` (`resolveRelatedRecord`), `:631-634`
- Evidence:
  ```php
  public function __invoke(array $data): void {
      $this->originalData = $this->data = $data;
      $this->record = null;
      foreach ($this->getCachedColumns() as $column) {
          $column->clearResolvedRelatedRecords();
  ```
  The `Importer` and its cached `ImportColumn`s live for the whole job (`ImportCsv::$importer` is built once in the constructor and reused for every row in `handle()`). So the reset is per row, not per job. `resolveRelatedRecord` → `$relationshipQuery->where(ownerKey, $state)->first()`.
  - Possibly deliberate: a job-level cache would keep a `null` miss for a value that a `beforeSave`/`afterSave` hook creates in a later row of the same chunk. A fix should cache only hits, or invalidate in hooks.
- Measure: SQL queries per imported row.
  - Configuration: importer with `ImportColumn::make('author')->relationship()` (BelongsTo, resolve by key) over 10 distinct author ids, 1,000 rows, `chunkSize(100)`, `QUEUE_CONNECTION=sync`.
  - Expect, per row: 1 `select … limit 1` on authors, 1 `find()` from `resolveRecord`, and 1 insert. Expected author lookups: 1,000 (would be 10 with a job-level cache).
  - Repeat with `relationship(resolveUsing: 'name')` and with `resolveUsing` returning a closure.
  - Assert with `DB::listen` grouped by normalised SQL.

### 3.7-c BelongsToMany 1+N `exists()` per row
- Report: `resolveRelatedRecords` runs `get()` plus one `exists()` per value; compute from the fetched records instead.
- Verdict: **Partly.** The 1 + N(distinct scalar values) queries per row are confirmed, and are also repeated per row because of #8. The proposed fix is not trivially equivalent: the per-value `exists()` checks whether *each* value matched under **database collation** across possibly several OR'd `resolveUsing` columns. Matching records back to values in PHP would differ for case-insensitive or accent-insensitive collations and trimmed values. A single `GROUP BY`/`CASE` query, or PHP matching with the same normalisation, is needed.
- Where on 5.11.4: `actions/src/Imports/ImportColumn.php:522-628` (`get()` at :583, loop at :588-619, `exists()` at :610)
- Evidence: `foreach (collect($values)->uniqueStrict() as $value) { $query = clone $relationshipQuery; $applyLookup($query, [$value]); … $hasRelatedRecord = $query->exists();`. On SQL Server it uses `selectOne … limit 1` instead.
- Measure: queries per row for `ImportColumn::make('tags')->relationship()->multiple(',')` (BelongsToMany) where each row has 5 comma-separated values: expect 1 `select … where id in (…)` + 5 `select exists(…)` per row, then `attach` inserts. Record the count for 1,000 rows (≈6,000 lookup queries) and the wall time of the sync job.

### #9 Export sorted by a non-key column falls back to OFFSET `chunk()` over `SELECT *`
- Report: O(n²/chunk) row scans to pluck keys; unstable ordering can skip or duplicate rows.
- Verdict: **Confirmed for the cost, and it triggers more often than implied. The "unstable ordering" part is mostly Wrong** by default.
  - Any table sort, user-chosen or `defaultSort()`, produces ≥2 ORDER BY terms, because tables append the primary key (`defaultKeySort` is on by default). That always takes the OFFSET branch, even though the order is fully deterministic.
  - Rows are skipped or duplicated only if rows are inserted, deleted or updated between chunk queries (non-snapshot reads), or with `->defaultKeySort(false)`.
  - The chunk size used is `chunkSize` (100), not `chunkKeySize` (1,000).
- Where on 5.11.4: `actions/src/Exports/Jobs/PrepareCsvExport.php:192-232` (OFFSET branch :200-219), `tables/src/Concerns/CanSortRecords.php:155-176` (key tie-breaker), `actions/src/Concerns/CanExportRecords.php:187`
- Evidence:
  ```php
  if ((($baseQueryOrdersCount === 1) && (! in_array($baseQueryOrders[0]['column'] ?? null, [$keyName, $qualifiedKeyName]))) || ($baseQueryOrdersCount > 1)) {
      $baseQuery->chunk($this->chunkSize, fn (Collection $records) => $dispatchRecords(Arr::pluck($records->all(), $keyName)));
  ```
  `$baseQuery = $query->toBase()` keeps the table query's select list (default `*` with any joins and filter/search predicates). Laravel's `chunk()` issues `… order by … limit 100 offset (page-1)*100` until a short page.
- Measure:
  - SQL text, count and rows scanned of the `PrepareCsvExport` job for a 100k-row table: `ExportAction` on a table sorted by `name` (user sort, or `->defaultSort('name')`).
  - Expect ≈1,000 `select * … order by name asc, id asc limit 100 offset k` queries, rows examined ≈ Σk ≈ 5×10⁷. Use MySQL `Handler_read_*`/`Rows_examined` from the slow log; Postgres `EXPLAIN (ANALYZE, BUFFERS)` on the last page; SQLite `EXPLAIN QUERY PLAN` plus wall time.
  - Control: the same export unsorted, which takes the `orderedChunkById` path with `select id … where id > ? limit 1000` (≈100 queries).
  - Also record job wall time against row count (10k/50k/100k) to show superlinear growth.

### 3.7-e ExportCsv eager-loads and aggregates every exporter column, not only the selected ones
- Report: `ExportCsv.php:89-92` applies eager loads and aggregates for all columns.
- Verdict: **Confirmed.**
- Where on 5.11.4: `actions/src/Exports/Jobs/ExportCsv.php:89-94`, `actions/src/Exports/Exporter.php:177-184`
- Evidence: `foreach ($this->exporter->getCachedColumns() as $column) { $column->applyRelationshipAggregates($query); $column->applyEagerLoading($query); }`. `getCachedColumns()` is `static::getColumns()` and is not filtered by `$this->columnMap`.
- Measure: SQL queries per `ExportCsv` job.
  - Configuration: an exporter with 5 columns, each a different relationship (`author.name`) or `counts('comments')`, with only 1 column enabled in the column-mapping modal.
  - Expect 1 main + 5 eager-load queries and 5 aggregate subqueries in the main SELECT, against 1 + 1 when filtered.

### 3.7-f Import modal re-opens and re-sniffs the uploaded file on every Livewire request
- Report: encoding, delimiter and header are re-detected per request; on S3 that's a download each time.
- Verdict: **Confirmed.** It is a partial read rather than a full download each time: 20 lines for encoding, 10 records for delimiter stats, then the header. Each read is a new `fopen` (a new GET on S3).
- Where on 5.11.4: `actions/src/ImportAction.php:103-150` (`afterStateUpdated`), `:157-186` (Fieldset `schema()` closure, which runs on every render while a file is set), `:575-610` (duplicate-header validation rule), `:340-385` (`getUploadedFileStream`), `:387-420` (`detectCsvEncoding`), `:526-534` (`guessCsvDelimiter`)
- Evidence: the Fieldset closure `$csvStream = $this->getUploadedFileStream($csvFile); $csvReader = CsvReader::from($csvStream); … getCsvDelimiter($csvReader) … $csvReader->getHeader();`, with no memoisation keyed on the file.
- Measure: number of `fopen`/stream opens of the temp file (or S3 `GetObject` requests via the AWS SDK history middleware or a MinIO access log) per Livewire round trip, after a CSV is uploaded, while changing a column-mapping Select 5 times. Expect ≥1 per request, plus 1 for each validation pass on submit.

### 3.7-g Row count reads the whole file in-request, even when `maxRows` will reject it
- Report: `ImportAction.php:208-226`.
- Verdict: **Confirmed** (see #7 step 1).
- Where on 5.11.4: `actions/src/ImportAction.php:208-226`
- Evidence: `$totalRows = $csvResults->count(); $maxRows = $action->getMaxRows() ?? $totalRows; if ($maxRows < $totalRows) { … failure … }`
- Measure: time and peak memory of the submit request for `->maxRows(1000)` with a 1M-row file. Expect O(file) time; peak memory stays low because `iterator_count` streams. Control: a 1,001-row file.

### 3.7-h Authorization tooltip runs the policy up to 3× per action per render, outside the visibility cache
- Report: `HasTooltip.php:21-27`, `CanBeAuthorized.php:119-168,248`.
- Verdict: **Confirmed, but only when `->authorizationTooltip()` (or `authorizationNotification()`) is enabled.** Both default to `false` (`CanBeAuthorized.php:25-27`).
  - In a table, `isAuthorized()` and `isAuthorizedOrNotHiddenWhenUnauthorized()` are cached per record, but `getAuthorizationResponse()` is never cached.
  - Path per render: `isHidden` → `isAuthorizedOrNotHiddenWhenUnauthorized` → `getAuthorizationResponse` (1); `isDisabled` → `isAuthorized` → `Gate::check` (2); `getTooltip` → `getAuthorizationResponseWithMessage` → `getAuthorizationResponse` (3).
  - Outside tables (`! $this->hasTable()`) none of these are cached.
- Where on 5.11.4: `actions/src/Concerns/HasTooltip.php:19-30`, `actions/src/Concerns/CanBeAuthorized.php:91-102,130-173,175-198,234-260`, `actions/src/Concerns/CanBeDisabled.php:18-45`, `actions/src/Action.php:1024,1052`
- Evidence: `if ($this->hasAuthorizationTooltip() && ($response = $this->getAuthorizationResponseWithMessage())->denied() && …)`
- Measure: policy method invocations per table render (a counter in the policy method) for 50 rows × `EditAction::make()->authorizationTooltip()` with a policy that denies. Expect ≈150 against 50 without the tooltip. Also record queries if the policy queries.

### 3.7-i Per-row `find()`, validation rules rebuilt per row, no prefetch hook
- Report: `Importer.php:176-225`.
- Verdict: **Confirmed.**
- Where on 5.11.4: `actions/src/Imports/Importer.php:175-184` (`resolveRecord` → `find`), `:189-226` (`validateData` / `getValidationRules`), `actions/src/Imports/Jobs/ImportCsv.php:95-112` (per-row loop with no chunk-level hook)
- Evidence: `return static::getModel()::find($this->data[$keyColumnName]);`. `getValidationRules()` re-evaluates `$column->getDataValidationRules()` (closures included) for every row. The only hooks are row-level (`beforeValidate` … `afterSave`); there is no hook that receives the chunk.
- Measure: queries per row and time per 1,000 rows for an importer with 10 columns with rules and the default `resolveRecord`. Count the `select … where id = ? limit 1` per row and `evaluate()` calls in `ImportColumn` (wrap with a counter or SPX).

### 3.7-j Failed rows inserted one at a time
- Report: `Jobs/ImportCsv.php:139`.
- Verdict: **Confirmed.**
- Where on 5.11.4: `actions/src/Imports/Jobs/ImportCsv.php:139`
- Evidence: `$this->import->failedRows()->createMany($this->failedRows);`. Laravel's `HasOneOrMany::createMany` loops `create()`, so it issues one INSERT per failed row.
- Measure: INSERT count into `failed_import_rows` per chunk when all 100 rows fail validation. Expect 100, against 1 with `insert()`.

### 3.7-k (Low) 3 locked UPDATEs per chunk to clamp counters
- Verdict: **Confirmed.** This applies to both import and export.
- Where: `actions/src/Imports/Jobs/ImportCsv.php:114-137`, `actions/src/Exports/Jobs/ExportCsv.php:108-131`
- Evidence: an increment UPDATE, then two `whereColumn('x_rows','>','total_rows')->lockForUpdate()->update(...)`.
- Measure: UPDATE statements on `imports`/`exports` per chunk (expect 3). Lock wait time with 8 concurrent workers on MySQL (`performance_schema.data_lock_waits`).

### 3.7-l (Low) XLSX download can rebuild synchronously in-request
- Verdict: **Partly.** With the default formats (CSV + XLSX), `CreateXlsxFile` is queued at the end of the chain (`ExportDispatcher.php:81`). The in-request rebuild happens only when the `.xlsx` is missing: the download is clicked before that job runs, the job failed, or the file was cleaned up.
- Where: `actions/src/Exports/Downloaders/XlsxDownloader.php:14-46`, `actions/src/Exports/ExportDispatcher.php:50-82`
- Evidence: `if ($disk->exists($filePath = …$fileName)) { return $disk->download(...) } … streamDownload(fn () => app(XlsxExportContentGenerator::class)($export, $writer))`
- Measure: time and peak memory of `GET /filament/exports/{id}/download?format=xlsx` for a 100k-row export with the `.xlsx` deleted, against present.

### 3.7-m (Low) Failed-rows CSV fully built before streaming; flush condition inverted
- Verdict: **Confirmed.** The CSV is built into an `SplTempFileObject`, which spills to disk after 2 MB, so not all of it is held in RAM. The DB read is `lazyById(100)`. `if ($offset % 1000) flush();` flushes on almost every 1 KB chunk and skips multiples of 1000. The effect is extra flushes, not missing ones.
- Where: `actions/src/Imports/Downloaders/CsvDownloader.php:14-34`, `actions/src/Imports/ContentGenerators/CsvImportFailureContentGenerator.php:48-61`
- Measure: time to first byte (TTFB) and total time of the failed-rows download for 100k failed rows; TTFB ≈ total build time.

### 3.7-n (Low) Each export chunk re-runs the original predicates
- Verdict: **Confirmed.** Cost is usually small because the planner drives from the `whereIn(key)`, but `whereHas`/search subqueries are evaluated for those 100 rows.
- Where: `actions/src/Exports/Jobs/ExportCsv.php:83-94`
- Evidence: `$query = EloquentSerializeFacade::unserialize($this->query); … $query->find($this->records)`
- Measure: SQL text and EXPLAIN of one `ExportCsv` query for an export from a table with search `foo` across 3 relationship columns (expect `where id in (…) and (exists(…) or …)`).

### 3.7-o (Low) `wire:key` md5 of the serialized context
- Verdict: **Confirmed, negligible.** `getContext()` is a small array.
- Where: `actions/src/Action.php:377-392`
- Evidence: `$key = md5(serialize($this->getContext()));`
- Measure: not worth benchmarking separately. At most, count of `getLivewireKey()` calls per table render.

---

## 3.8 Query builder

### #17 Query-builder date operators use `whereDate`/`whereYear`/`whereMonth`
- Report: `hasTime` defaults to false, so `date(col) >= ?` is non-sargable; rewrite as ranges.
- Verdict: **Confirmed, with three corrections:**
  1. `IsDate`, `IsMonth` and `IsYear` *always* wrap the column, whatever `hasTime` is. `IsAfter`/`IsBefore` wrap only when `hasTime` is false, or in relative mode with a day-or-coarser preset.
  2. **`IsMonth` cannot be rewritten as a range**, because it means "month M of any year". Only `IsAfter`/`IsBefore`/`IsDate`/`IsYear` can.
  3. On Postgres a `date`-typed column makes `col::date` a no-op cast, which the planner elides, so an index is still usable there. The problem is `timestamp` columns. Expression indexes (`(created_at::date)`, `((date(created_at)))` in MySQL 8.0.13+) would also rescue it.
- Where on 5.11.4: `query-builder/src/Constraints/DateConstraint.php:20-32` (`$hasTime = false`), `DateConstraint/Operators/IsAfterOperator.php:189-218`, `IsBeforeOperator.php:189-218`, `IsDateOperator.php:54-64`, `IsMonthOperator.php:66-76`, `IsYearOperator.php:54-64`, `query-builder/src/Constraints/Operators/Operator.php:124-160` (qualifies the column, or wraps it in `whereHas` for relationship attributes)
- Evidence: `return $query->whereDate($qualifiedColumn, $this->isInverse() ? '<' : '>=', $date);`
- Exact SQL from the Laravel 13.34 grammars, for column `posts.created_at`:

  | Operator (direct / inverse) | SQLite | MySQL | Postgres |
  |---|---|---|---|
  | IsAfter, `hasTime=false` | `strftime('%Y-%m-%d', "posts"."created_at") >= cast(? as text)` / `<` | ``date(`posts`.`created_at`) >= ?`` / `<` | `"posts"."created_at"::date >= ?` / `<` |
  | IsBefore, `hasTime=false` | `… <= cast(? as text)` / `>` | `date(…) <= ?` / `>` | `…::date <= ?` / `>` |
  | IsDate | `strftime('%Y-%m-%d', …) = cast(? as text)` / `!=` | `date(…) = ?` / `!=` | `…::date = ?` / `!=` |
  | IsMonth (binding zero-padded `'03'`) | `strftime('%m', …) = cast(? as text)` / `!=` | `month(…) = ?` / `!=` | `extract(month from "posts"."created_at") = ?` / `!=` |
  | IsYear | `strftime('%Y', …) = cast(? as text)` / `!=` | `year(…) = ?` / `!=` | `extract(year from "posts"."created_at") = ?` / `!=` |
  | IsAfter/IsBefore with `->time()` (absolute) | `"posts"."created_at" >= ?` (sargable) | same | same |

  Relative mode: presets of a day or more (`past_week`, `this_month`, custom with days, …) still go through `whereDate` even with `->time()`. Only `*_minute`/`*_hour` and custom second/minute/hour units use plain `where` (`IsAfterOperator.php:196-204`, `isTimeBasedFilter`).
- Measure:
  - Assert on the generated SQL (`$query->toRawSql()`) for each operator × driver × `hasTime`.
  - Run EXPLAIN on a 1M-row `posts` table with `index(created_at)` (`timestamp`/`datetime` type): SQLite `EXPLAIN QUERY PLAN` (expect `SCAN posts` against `SEARCH posts USING INDEX … (created_at>?)` for a range); MySQL `EXPLAIN` `type=ALL` against `range`; Postgres `EXPLAIN (ANALYZE)` `Seq Scan` against `Index Scan`/`Bitmap Index Scan`, plus execution time.
  - Configuration: `QueryBuilder::make()->constraints([DateConstraint::make('created_at')])`, rule `IsAfter` date `2025-06-01`, selective (≈1% of rows).
  - Control: `DateConstraint::make('created_at')->time()`.
  - Also test a `date`-typed column on Postgres, where the index is expected to be used.

### #16 Postgres search and text constraints become `lower(col::text) like '%x%'`; Equals is a LIKE without wildcards
- Report: no index can be used; Equals is `LIKE`; affects table search, Select search, global search and the query builder.
- Verdict: **Partly.** The SQL shape is confirmed for the query builder, and for helpers on pgsql. Two parts are overstated or wrong:
  - **"No index can be used"** is overstated. A plain btree on `col` cannot be used on Postgres, but an expression index on `lower(col::text)` (with `text_pattern_ops` / C collation) serves Equals/StartsWith, and `pg_trgm` GIN on `lower(col::text)` serves Contains/EndsWith. On MySQL, Equals (`LIKE 'x'`) and StartsWith (`LIKE 'x%'`) *can* use a btree range on `col`.
  - The report's proposed fix (`ilike`) **does not make a plain index usable either**. Only `=` (Equals, with changed case semantics or a CI collation) or trigram/expression indexes do.
- Where on 5.11.4: `query-builder/src/Constraints/TextConstraint/Operators/{Equals,Contains,StartsWith,EndsWith}Operator.php:60-85` (pgsql branch :72-77), `support/src/helpers.php:267-334` (`generate_search_column_expression`), `:355-365` (`generate_search_pattern`), `:371-377` (`apply_search_constraint`)
- Evidence:
  ```php
  if ($databaseConnection->getDriverName() === 'pgsql') {
      $qualifiedColumn = new Expression("lower({$qualifiedColumn}{$jsonPath}::text)");
      $text = Str::lower($text);
  }
  return apply_search_constraint($query, $qualifiedColumn, generate_search_pattern($text, hasLeadingWildcard: false, hasTrailingWildcard: false), isInverse: $this->isInverse());
  // apply_search_constraint: $query->whereRaw("{$column} {$operator} ? escape '!'", [$pattern], $boolean)
  ```
- Exact SQL, column `posts.title`, input `Foo`. The pattern escapes `! [ % _` with `!`. Inverse uses `not like`.

  | Operator | SQLite | MySQL | Postgres |
  |---|---|---|---|
  | Equals | `"posts"."title" like ? escape '!'` binding `Foo` | ``…`posts`.`title` like ? escape '!'`` binding `Foo` | `lower("posts"."title"::text) like ? escape '!'` binding `foo` |
  | Contains | same, binding `%Foo%` | same, `%Foo%` | same, `%foo%` |
  | StartsWith | `Foo%` | `Foo%` | `foo%` |
  | EndsWith | `%Foo` | `%Foo` | `%foo` |

  Table search (helpers, not the query builder): pgsql → `lower("posts"."title"::text) like ? escape '!'`. MySQL/SQLite → plain `col like ?`, unless `isSearchForcedCaseInsensitive` (then `lower(col)`) or `search_collation` is configured (then `col collate X`).
- Index applicability (from engine docs, to be confirmed by EXPLAIN):
  - **SQLite**: the LIKE optimisation needs the index to be `COLLATE NOCASE` (with `case_sensitive_like` off). A default BINARY index is not used for Equals/StartsWith.
  - **MySQL**: Equals/StartsWith use a `range` on `index(title)`; Contains/EndsWith scan.
  - **Postgres**: plain `index(title)` is never used; `index(lower(title::text) text_pattern_ops)` is used for Equals/StartsWith.
- Measure:
  - `toRawSql()` per operator × driver.
  - EXPLAIN plans on 1M rows with (a) a plain `index(title)`, (b) the engine's best index (SQLite `COLLATE NOCASE`; PG expression index + `pg_trgm` GIN; MySQL plain), for Equals and StartsWith (selective value) and Contains.
  - Record plan type and execution time.
  - Configuration: `QueryBuilder::make()->constraints([TextConstraint::make('title')])`.

### 3.8-qb-c Relationship-rule indicator runs a query per render, with a needless `orderBy`
- Verdict: **Confirmed.** One query per active `IsRelatedTo` rule per table render, because filter indicators are rebuilt on every render. `getRelationshipQuery()` adds `orderBy(title)` when there is none, which is useless before `whereKey()->pluck()`.
- Where on 5.11.4: `query-builder/src/Constraints/RelationshipConstraint/Operators/IsRelatedToOperator.php:68-104` (query at :74-83), `:283-300` (`orderBy` at :295-297); called from `tables/src/Filters/QueryBuilder.php:76-84,93-145`
- Evidence: `$values = $relationshipQuery->…->whereKey($values)->pluck($relationshipQuery->qualifyColumn($this->getTitleAttribute()))`
- Measure: queries per table re-render (e.g. clicking pagination) with 3 applied `RelationshipConstraint::make('author')->selectable(IsRelatedToOperator::make()->titleAttribute('name'))` rules. Expect 3 `select name from authors where id in (…) order by name` per request.

### 3.8-qb-d Rule-limit check recounts the whole tree inside every action closure
- Verdict: **Confirmed but negligible.** It only runs when `maxRules()` is set (`getMaxRules() === null` returns early). Each add-rule / add-OR action (×2 closures, `disabled` + `tooltip`) and `getBlockPickerBlocks()` walks the root raw state. Cost is O(actions × rules) array walking, bounded by `maxRules`.
- Where: `query-builder/src/Forms/Components/RuleBuilder.php:101-159` (closures), `:256-316`
- Measure: `countTreeRules` calls and time per render of the filter form with `QueryBuilder::make()->maxRules(50)` and 50 rules, 5 OR groups. Expect a few hundred calls and sub-ms time, so likely not worth a PR.

---

## 3.8 Media library plugin

### 3.8-ml-a O(n²) uuid scan per image, ignoring the Media model it already has
- Verdict: **Confirmed.** `getImageUrl()` receives `$relatedRecord`, which is the `Media` model from `cacheRelatedRecords`, but ignores it and linearly scans all of the record's media with two `strtolower` calls per item. The same pattern is in the infolist entry and in `SpatieMediaLibraryFileUpload::getUploadedFileUsing` (:91).
- Where: `spatie-laravel-media-library-plugin/src/Tables/Columns/SpatieMediaLibraryImageColumn.php:94-131` (scan :109), `Infolists/Components/SpatieMediaLibraryImageEntry.php:92-111`, `Forms/Components/SpatieMediaLibraryFileUpload.php:91`
- Evidence: `$media = $record->getRelationValue('media')->first(static fn (Media $media): bool => ($state !== null) && (strtolower($media->uuid) === strtolower($state)));`
- Measure: CPU only, with no extra queries. Count closure invocations per table render for 50 rows × 20 media each with `->stacked()`: expect ≈50×Σ(1..20) ≈ 10k against 1k. In practice this is µs-level; benchmark render time with SPX.

### 3.8-ml-b Column eager-loads every media row, not only its collection
- Verdict: **Confirmed.** A naive fix (constraining the eager load by collection) breaks when two columns on the same table use different collections, because they share the `media` relation key in `with()` and the last constraint wins. A fix must union the collections across columns.
- Where: `…/Tables/Columns/SpatieMediaLibraryImageColumn.php:186-202`; the PHP filter is at `:153-163`
- Evidence: `return $query->with(['media' => $modifyMediaQuery]);` where `$modifyMediaQuery = fn ($query) => $query->ordered();`
- Measure: rows hydrated from `media` per page (`DB::listen` + `count($result)` or `Model::retrieved` event counter). Configuration: 50 records, each with 1 `avatar` + 30 `gallery` media, and a column `SpatieMediaLibraryImageColumn::make('avatar')->collection('avatar')`. Expect 1,550 hydrated against 50 needed.

### 3.8-ml-c (theme 2 item) Upload reads the whole file into memory via `addMediaFromString($file->get())`
- Verdict: **Confirmed.** The same pattern is in the RichEditor attachment provider (`SpatieMediaLibraryFileAttachmentProvider.php:152`). This only matters above Livewire's default temporary-upload limit (`max:12288`, 12 MB) when that is raised. On S3 temp storage, `get()` downloads the whole object into a PHP string; Spatie then writes it to a temp file and copies it to the target disk.
- Where: `spatie-laravel-media-library-plugin/src/Forms/Components/SpatieMediaLibraryFileUpload.php:127-157` (:141)
- Evidence: `$mediaAdder = $record->addMediaFromString($file->get());`
- Measure: peak memory (`memory_get_peak_usage(true)`) of the Livewire request that saves the form, for 10 / 100 / 500 MB uploads. Expect peak ≥ file size. Also find the file size at which `memory_limit=256M` fails.
  - Configuration: `SpatieMediaLibraryFileUpload::make('file')->maxSize(1024000)`, `livewire.temporary_file_upload.rules = ['file','max:1024000']`, local and S3 temp disks.
  - Control: the same file through core `FileUpload` (stream move).

### 3.8-ml-d `load('media')` per field on fill; `setNewOrder` runs even if the order hasn't changed
- Verdict: **Confirmed.**
  - `load()` always re-queries, unlike `loadMissing`, so N fields on one form mean N identical `select * from media where model_id in (?)` queries.
  - `setNewOrder` runs on every save, but only for `->reorderable()` fields (`BaseFileUpload::$isReorderable = false` by default, gated at `BaseFileUpload.php:1040`). It is preceded by an extra `pluck` query. Spatie's `setNewOrder` issues one UPDATE per id.
- Where: `…/Forms/Components/SpatieMediaLibraryFileUpload.php:58-77` (load at :60), `:159-196` (`setNewOrder` at :194)
- Measure:
  - Queries on edit-page mount with 4 `SpatieMediaLibraryFileUpload` fields (different collections): expect 4 media SELECTs.
  - Queries on save with one `->reorderable()->multiple()` field holding 10 files, unchanged: expect 1 SELECT + 10 UPDATEs.

---

## 3.8 Tags plugin

### #20 `SpatieTagsInput` suggestions run `Tag::query()->pluck('name')` with no limit
- Verdict: **Confirmed.** It runs whenever the field renders (every Livewire response that re-renders the form, not only on mount). It emits a `<template x-bind:key x-if>` + `<option>` per tag, with `Js::from` twice per tag, so ~150–250 B of HTML per tag. A filter only applies when a type is set.
- Where: `spatie-laravel-tags-plugin/src/Forms/Components/SpatieTagsInput.php:96-118`, `forms/src/Components/TagsInput.php:313-322`
- Evidence: `$query = $tagClass::query(); … return $query->pluck('name')->all();` and `<?php foreach ($this->getSuggestions() as $suggestion) { ?> <template x-bind:key="<?= Js::from($suggestion) ?>" x-if="! (state?.includes(<?= Js::from($suggestion) ?>) ?? true)">`
- Measure: on an edit page with `SpatieTagsInput::make('tags')` and 20k rows in `tags`, record the rows returned by the tags query per Livewire request, the response size (bytes of `effects.html`), and server render time, for both the initial load and a `live()` sibling field update. Also count browser DOM nodes (20k `<template>` elements).

### 3.8-tag-b `load('tags')` runs on every fill
- Verdict: **Confirmed, minor.** It runs once per fill (mount, or refill after save), not per render. It re-queries even when already loaded.
- Where: `…/Forms/Components/SpatieTagsInput.php:21-36` (:27)
- Measure: tags queries on mount with 2 `SpatieTagsInput` fields (different types): expect 2 identical relationship loads.

### 3.8-tag-c One `findFromString` query per tag on save
- Verdict: **Confirmed** for the any-type path, which is Filament's code: `findFromStringOfAnyType` (+ `create`) per tag. The typed path calls Spatie's `syncTagsWithType`, whose per-tag lookups are Spatie's own (not verified, no vendor).
- Where: `…/Forms/Components/SpatieTagsInput.php:62-87` (:73)
- Measure: queries on save with 20 tags entered, all existing: expect ≥20 SELECTs + 1 sync.

### 3.8-tag-d `SpatieTagsEntry` doesn't cache state or eager-load
- Verdict: **Partly.** `getState()` has no `cacheState()` (the column version does), so it recomputes on each call, which is CPU only. The relation is lazy-loaded once per record and then cached on the model, so in a single-record infolist that is 1 query. Real N+1 only happens inside `RepeatableEntry` over many records, or `->state(relationship)`.
- Where: `spatie-laravel-tags-plugin/src/Infolists/Components/SpatieTagsEntry.php:27-77`; compare `Tables/Columns/SpatieTagsColumn.php:29-31,100-110`
- Measure: `getState()` call count per render (expect several, from badge/format/empty checks) and tag queries for a `RepeatableEntry::make('posts')->schema([SpatieTagsEntry::make('tags')])` over 50 posts (expect 50 queries).

---

## 3.8 Settings plugin

### 3.8-set-a "Clean"
- Verdict: **Confirmed, nothing found.** `SettingsPage` does one `app(Settings)` on mount and fill, and `fill()` + `save()` on save. Spatie's own repository/query costs were not checked because there is no vendor.
- Where: `spatie-laravel-settings-plugin/src/Pages/SettingsPage.php:38-51,66-88`
- Measure: optional baseline, queries on mount and save of a settings page with 20 properties.

---

## 3.9 Frontend JS and build

### 3.9-tbl Largest bundles: measured on 5.11.4 (`wc -c`, `gzip -c | wc -c`)

| Bundle | Minified (B) | Gzip -6 (B) | Report | Loaded |
|---|---|---|---|---|
| forms/dist/components/code-editor.js | 986,231 | 336,662 | 1.0 MB / ~345 KB, approx. OK | when a CodeEditor is present (x-load) |
| panels/dist/theme.css | 693,488 | 69,418 | matches | every panel page |
| forms/dist/components/rich-editor.js | 554,612 | 174,733 | matches | RichEditor present |
| forms/dist/components/markdown-editor.js | 524,247 | 167,942 | matches | MarkdownEditor present |
| forms/dist/components/file-upload.js | 375,690 | 102,193 | 376 KB, OK | FileUpload present |
| widgets/dist/components/chart.js | 286,688 | 95,168 | matches | ChartWidget present |
| support/dist/index.js | 196,341 | 65,788 | matches | every page |
| widgets/dist/components/stats-overview/stat/chart.js | 149,101 | 52,502 | **missing from report** (second copy of Chart.js 4.5.1) | Stat with `->chart()` |
| forms/dist/components/date-time-picker.js | 147,847 | 38,032 | missing | DateTimePicker `native(false)` |
| tables/dist/components/columns/select.js | 92,568 | 27,370 | missing | SelectColumn (non-native) |
| panels/dist/echo.js | 91,939 | 24,236 | 92 KB, OK | **every panel page** |
| forms/dist/components/select.js | 89,392 | 26,380 | missing | Select (non-native) |
| forms/dist/index.css | 39,334 | 8,549 | – | – |
| schemas/dist/components/tabs.js | 31,779 | 10,871 | – | Tabs |

- Build claim, `repo:bin/build.js`. **Partly wrong:** only the core `*/resources/js/index.js` and `echo.js` are IIFEs (`platform: 'browser'`). Component bundles use `platform: 'neutral'` with no `format`, so esbuild emits **ESM** (e.g. `forms/dist/components/tags-input.js` ends `export{r as default};`); async-alpine dynamically imports them. "No splitting" is **true**: each entry is built separately, so shared dependencies are duplicated:
  - the Select utility + SortableJS in both `forms/…/select.js` and `tables/…/columns/select.js` (both contain `fi-select-input-search-ctn` and 28 `Sortable` references);
  - SortableJS again in `support/dist/index.js`;
  - Chart.js in both widget bundles.
- "Every `x-load` uses the eager strategy": **Confirmed.** No `x-load="<strategy>"` exists in any PHP or Blade file; all are bare `x-load` (async-alpine default = eager). FileUpload does defer FilePond *initialisation* until visible with its own Resize/IntersectionObserver (`file-upload.js:136-160`), but the bundle still downloads eagerly.
- Measure: bytes per dist file (above), plus network bytes and script evaluation time on a page with a CodeEditor inside a collapsed Section / hidden Tab (expect the bundle fetched and initialised at load).

### #18 Echo + Pusher registered as a `core()` asset on every panel page
- Verdict: **Confirmed.** Panels likely covers this too.
- Where: `panels/src/FilamentServiceProvider.php:143`, `panels/resources/views/components/layout/base.blade.php:153-163`
- Evidence: `Js::make('echo', __DIR__ . '/../dist/echo.js')->core(),`. Only `new window.EchoFactory(...)` is gated by `filament()->hasBroadcasting()`.
- Measure: presence of `echo.js` `<script>` and its bytes (91,939 / 24,236 gz) on a panel page with broadcasting off; parse/eval time in a Performance trace.

### #19 Code editor bundles all 13 CodeMirror languages
- Report: 1.0 MB / ~345 KB; "a lazy-import path already exists (`:184`)".
- Verdict: **Partly.** 13 static language imports are confirmed. **The "lazy-import path already exists" claim is wrong**: there is no `import()` in `code-editor.js` (186 lines; `:184` is inside `destroy()`). Languages are chosen from a static object map. The only laziness is that `x-load` fetches the whole bundle when a CodeEditor is on the page. Lazy languages would need `splitting: true` + `outdir` in `bin/build.js` (esbuild only splits ESM with an outdir).
- Where: `forms/resources/js/components/code-editor.js:8-20,148-170`, `forms/src/Components/CodeEditor.php:62`
- Evidence: `import { cpp } from '@codemirror/lang-cpp'` … `import { yaml } from '@codemirror/lang-yaml'`; `const extensions = { cpp, css, go, … }; return extensions[language]?.() || null`
- Measure: bytes of `code-editor.js` (986,231 / 336,662 gz); whether any `import(` / extra chunk files exist under `forms/dist/components/` (none on 5.11.4); browser script evaluation time and JS heap for a page with `CodeEditor::make('x')->language(Language::Json)`.

### 3.9-c FileUpload re-downloads every existing file with `cache: 'no-store'` on every page load
- Verdict: **Partly.** It fetches every existing file on init with `no-store`, which bypasses the HTTP cache. "Never a thumbnail" is **not true for the Spatie plugin**, which serves the conversion URL when `->conversion()` is set (`SpatieMediaLibraryFileUpload.php:107-110`). Core `FileUpload` fetches the stored original.
- Where: `forms/resources/js/components/file-upload.js:296-307`
- Evidence: `let response = await fetch(source, { cache: 'no-store' }); let blob = await response.blob()`
- Measure: requests and bytes for file URLs on an edit page with `FileUpload::make('images')->multiple()->image()` holding 10 × 3 MB images, on the first and second visit (expect 30 MB both times; request `Cache-Control: no-cache` / no 304).

### 3.9-d Tabs MutationObserver watches the entire tab contents
- Verdict: **Confirmed.** The callback is rAF-coalesced, so `update()` runs at most once per frame. The per-record `querySelector` in the callback runs for every mutation record that is not in the tablist, which covers all content mutations.
- Where: `schemas/resources/js/components/tabs.js:120-142`, `:614-632` (`update` reassigns `this.availableTabs = tabs`)
- Evidence: `this.observer.observe(this.root, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: [...] })`, callback `records.some(record => … this.getHeaderElement().querySelector(':scope > [x-ref=tablist]').contains(record.target))`
- Measure (browser): MutationObserver callback invocations, `querySelector` calls, and `update()` calls per 100 keystrokes in a `RichEditor` inside `Tabs::make()->tabs([...])`. Measure by wrapping the methods on the Alpine data object via `Alpine.$data(el)`, or with a Performance trace.

### 3.9-e Markdown editor eagerly loads about 35 CodeMirror 5 modes
- Verdict: **Confirmed (30 modes**, not ~35, plus 5 addons).
- Where: `forms/resources/js/components/markdown-editor.js:6-43`
- Measure: bundle bytes (524,247 / 167,942 gz); `grep -c "require('codemirror/mode"` = 30.

### 3.9-f FileUpload bundles about 45 FilePond locales and the full `mime` database
- Verdict: **Confirmed (41 locales).**
- Where: `forms/resources/js/components/file-upload.js:3` (`import mime from 'mime'`), `:1141-1181` (locale imports)
- Measure: bundle bytes (375,690 / 102,193 gz); bytes attributable to locales/mime in an esbuild metafile (needs a rebuild with `--metafile`).

### 3.9-g Any FileUpload resize dispatches a global `visibilitychange`
- Verdict: **Confirmed.** Each FilePond instance listens to `document` `visibilitychange` (seen in the dist code: `document.addEventListener("visibilitychange",n)` → `dispatch("KICK")`, plus the painter loop). Every resize/intersection of any upload dispatches one global event, so a window resize with N uploads gives N events × N listeners.
- Where: `forms/resources/js/components/file-upload.js:136-160`
- Evidence: `requestAnimationFrame(() => document.dispatchEvent(new Event('visibilitychange')))`
- Measure (browser): count of `visibilitychange` events (listener on `document`) and total listener time during one window resize with 1 / 10 / 30 `FileUpload` fields (e.g. a Repeater of 30 items).

### 3.9-h RichEditor per-keystroke work, toolbar `isActive` per transaction, no `x-load="visible"`
- Verdict: **Confirmed.**
- Where: `forms/resources/js/components/rich-editor.js:412-447`, `forms/src/Components/RichEditor/RichEditorTool.php:207-212`, `forms/src/Components/RichEditor.php:1767`
- Evidence: `const content = editor.getJSON(); if (JSON.stringify(content) !== JSON.stringify(this.state))` (`this.state` is Alpine-reactive). `editor.on('transaction', () => { this.editorUpdatedAt = Date.now() })`; each tool's active expression is `editorUpdatedAt && $getEditor()?.isActive(...)`.
- Measure (browser): scripting time per keystroke (Performance trace, 100 keystrokes) in a RichEditor with a 50 KB document and the default toolbar (~20 tools); count of `isActive` calls per keystroke (wrap `editor.isActive`).

### 3.9-i CheckboxList search reads `innerText` ~4× per option and interleaves `x-show` writes
- Verdict: **Confirmed.** `matchesSearch` reads label and description `innerText` (2 reads). It is called once by `updateVisibleCheckboxListOptions` and again by each option's `x-show="matchesSearch($el)"`, so 4 forced-layout reads per option per search change. Search input is debounced.
- Where: `forms/resources/js/components/checkbox-list.js:96-121`, `forms/src/Components/CheckboxList.php:591,630`
- Measure (browser): time per search update and "Forced reflow" warnings in a Performance trace for `CheckboxList::make()->searchable()` with 2,000 options.

### 3.9-j DateTimePicker re-parses disabled-dates JSON ~84× per calendar render; 51 dayjs locales
- Verdict: **Confirmed**, but only for `->native(false)`. The default is native (`CanBeNative::$isNative = true`), and the JS picker is not used then. The ref is always rendered (`DateTimePicker.php:247`), so parsing happens even with no disabled dates. The template calls `dayIsDisabled(day)` twice per cell (`:318,327`) × 42 cells = 84 `JSON.parse` calls. There are 51 dayjs locale `require`s (`date-time-picker.js:959+`).
- Where: `forms/resources/js/components/date-time-picker.js:315-330,361-364`
- Measure (browser): `JSON.parse` calls (wrap it) and scripting time per month navigation for `DateTimePicker::make('d')->native(false)->disabledDates([...365 dates])`.

### 3.9-k (Low) Select local search re-normalises every option per keystroke; deep-clones when cleared
- Verdict: **Confirmed.**
- Where: `support/resources/js/utilities/select.js:1996-2043` (`filterOptions` → `normalizeSearchString` per option), `:1776` (`JSON.parse(JSON.stringify(this.originalOptions))` on an empty query; also `:101`, `:1439`)
- Measure (browser): time per keystroke with `Select::make()->searchable()->options(5000 items)`.

### 3.9-l (Low) Select utility bundled twice
- Verdict: **Confirmed.** See 3.9-tbl: forms 89,392 B and tables 92,568 B, both with Sortable.
- Measure: total bytes downloaded on a page that has both a non-native form Select and a searchable SelectColumn (expect both bundles).

### 3.9-m (Low) EasyMDE `handleImages` forces layout on every update
- Verdict: **Confirmed**, proportional to the number of image markers. Filament sets `previewImagesInEditor: true` (`markdown-editor.js:152`).
- Where: `forms/resources/js/components/markdown-editor/EasyMDE.js:2618-2677`
- Measure (browser): forced reflows per keystroke in a MarkdownEditor containing 20 `![](…)` lines.

### 3.9-n (Low) Unsaved-changes alert md5s the whole `$wire.data` on every navigation
- Verdict: **Confirmed.** It runs per `beforeunload` / `livewire:navigate`, not per keystroke.
- Where: `panels/resources/js/unsaved-changes-alert.js:1-76`
- Measure: time of the navigate handler for a form with 1 MB of `data` (e.g. a large Repeater).

---

## Runtime JS bullets from 3.2 / 3.6

### 3.2-c Searchable SelectColumn builds the full dropdown DOM up front, plus one `document` click listener per cell
- Verdict: **Confirmed.** It applies when the column is searchable or `native(false)` (`$isNative = ! areOptionsSearchable() && isNative()`, `SelectColumn.php:955`).
- Where: `support/resources/js/utilities/select.js:286` (`renderOptions()` in setup, before any open), `:1117-1120`
- Evidence: `document.addEventListener('click', this.documentClickListener)`
- Measure (browser): count of `li[role=option]` nodes and of `document` click listeners (CDP `DOMDebugger.getEventListeners(document)`) after loading 50 rows × `SelectColumn::make('status')->searchable()->options(200)`. Expect 10k `li`s and 50 listeners.

### 3.2-d Each editable cell registers its own global Livewire commit hook
- Report: `Livewire.hook('commit')` per cell; 100 × 4 = 400 hooks per round trip.
- Verdict: **Confirmed (API changed, pattern unchanged).** On 5.11.4 (Livewire 4) each cell calls `Livewire.interceptMessage(...)` instead of `Livewire.hook('commit')`. Every interceptor runs for every message. For the table's own component, all of them pass the id check and register `onSuccess` → `onMorphed` → `$nextTick` → `syncServerState()`. A save also temporarily adds a `Livewire.interceptRequest` that `JSON.parse`s each component snapshot.
- Where: `tables/resources/js/utilities/editable-table-column.js:31-66` (and `:85-117`); used by TextInput/Toggle/Checkbox/Select columns
- Evidence: `this.unsubscribeLivewireHook = Livewire.interceptMessage(({ message, onSuccess }) => { if (message.component.id !== this.$wire.$id) return …`
- Measure (browser): number of `Livewire.interceptMessage` registrations after load (wrap it before Livewire starts). Expect rows × editable columns, e.g. 400 for 100 rows × `TextInputColumn`/`ToggleColumn`/`CheckboxColumn`/`SelectColumn`. Also record scripting time in the interceptor and onMorphed phase per table round trip (pagination click).

### 3.2-e `watchForCheckboxClicks` re-bound on every Livewire `element.init`
- Verdict: **Confirmed, low cost.** An AbortController prevents listener leaks; the cost is one abort + `addEventListener` per element initialised in the table component.
- Where: `tables/resources/js/components/table.js:88-97,510-526`
- Measure (browser): `watchForCheckboxClicks` call count per page change on a 50-row table with bulk actions (wrap the method on `Alpine.$data`). Expect ≈ number of new elements.

### 3.6-a Each `filamentDropdown` instance gets its own global listeners and observers
- Verdict: **Confirmed, with a small correction.** It is **one** MutationObserver with two `observe()` targets (panel + trigger container, both subtree for menus), plus one ResizeObserver, plus `document` `livewire:navigate` and `window` `resize` listeners. All of these are set up in `init()`, not on first open.
- Where: `support/resources/js/components/dropdown.js:66-111,113-201`
- Measure (browser): `window` resize listener count (CDP `getEventListeners(window)`), observer callback invocations per morph, and time to dispatch one `resize`, on a 50-row table with `ActionGroup::make([...3 actions])` per row. Expect ≥50 resize listeners.

### 3.6-g Core bundle eagerly ships SortableJS, tippy, floating-ui, js-md5
- Verdict: **Confirmed.** It is imported and registered at `alpine:init`; `window.jsMd5` is used only by the unsaved-changes alert.
- Where: `support/resources/js/index.js:1-34`; `support/dist/index.js` = 196,341 / 65,788 gz
- Measure: bytes; parse and eval time of `support/dist/index.js` on a blank panel page.

### 3.6-k / 3.6-l (Low) `copyable.js` MutationObserver per cell; `modal.js` document listeners while closed
- Verdict: **Confirmed.** Each copyable observes its `.fi-ta-col`/`.fi-in-entry` subtree. Each modal registers capture `mousedown`/`mouseup` on `document` at init, open or not.
- Where: `support/resources/js/components/copyable.js:60-90`, `support/resources/js/components/modal.js:85-150`
- Measure (browser): observer count and `document` mousedown listeners with 50 rows × `TextColumn::make()->copyable()` and 10 row-action modals.

---

## Not in the report: noticed while reviewing
- **Import bulk INSERT limits (#7):** the database queue puts all import jobs in one INSERT. On SQLite this fails above ~546k rows at chunk 100; MySQL/Postgres fail at ~1.09M rows, or earlier on `max_allowed_packet`. Derived from code; needs a run to confirm.
- **Import job payload bloat:** each `ImportCsv` payload also serialises its `Importer` (with the `Import` model, columnMap and options), and the rows are serialised twice (the base64 inner string, then the queue payload).
- **Unverified, possible Postgres bug:** text operators with a JSON attribute build `lower("t"."data"->key::text)` with an unquoted key (`EqualsOperator.php:73-75`), which looks like invalid SQL. Worth one test.
