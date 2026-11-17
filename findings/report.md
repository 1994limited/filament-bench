# Filament v4: Performance Scouting Report

**Target:** `filamentphp/filament` on the `4.x` branch, at commit `6035bbc` (16 Nov 2026).
**Method:** 9 parallel scouts, one per area:
1. Table core
2. Table rendering
3. Schemas core
4. Form fields
5. Panels
6. Support
7. Actions, import and export
8. Widgets, notifications, query builder, infolists and plugins
9. Frontend JS and build

Each scout read the code and cited `file:line`. Afterwards I re-read the highest-impact claims myself against the source; those are marked ✅.

**Caveats:**
- This was static review only. There is no `vendor/` directory and nothing was profiled, so the severities are educated estimates.
- A good amount of optimisation is already in place: the TextColumn fast path, cached cell attributes, a per-record action-visibility cache, batched summaries, the component discovery cache, and the visibility cache during render.
- The findings below are what's left. All paths are relative to `packages/`.

---

## 1. Top 20: where to start

| # | Finding | Location | Why it hurts | Sev |
|---|---|---|---|---|
| 1 | ✅ `evaluate()` builds a new `ReflectionFunction` on every closure evaluation, with no metadata cache | `support/src/Concerns/EvaluatesClosures.php:32` (+ `:111`) | Every closure getter (label, hidden, color, url…) runs it, on every render, validation and dehydrate pass. That is rows × columns × 10–30 per table, or components × passes per form. | High |
| 2 | ✅ Image existence check is on by default, so each image costs a `Storage::exists()` | `tables/src/Columns/ImageColumn.php:64,231`, `infolists/src/Components/ImageEntry.php:68,237` | One HEAD request per image per render on S3/R2. Non-`public` disks also presign a `temporaryUrl()` per image. | High |
| 3 | ✅ Widgets poll every **5s** by default | `widgets/src/Concerns/CanPoll.php:7` | Every Stats/Chart widget re-runs its COUNT/SUM queries 12× a minute, per open tab, through the full middleware stack. Neither widget calls `skipRender()` when the data hasn't changed. | High |
| 4 | ✅ The select-all count hydrates the **whole filtered table** when `checkIfRecordIsSelectableUsing` is set | `tables/src/Concerns/HasBulkActions.php:236-248`, called on every render at `tables/resources/views/index.blade.php:163` | `->get()` over all pages, including eager loads and aggregates, just to count. Cost is O(total rows) per request. | High |
| 5 | ✅ `getRedirectUrl()` builds a whole new `NavigationManager` | `panels/src/Panel/Concerns/HasRoutes.php:223` | Re-runs every resource `canAccess()` policy and every navigation badge COUNT. With tenant domains it runs once per tenant in the switcher, and once each for the sidebar and topbar `getHomeUrl()`. | High |
| 6 | Navigation badges are evaluated eagerly rather than as closures | `panels/src/Resources/Resource/Concerns/HasNavigation.php:77`, `panels/src/Pages/Page.php:167`, `panels/src/Resources/Pages/Page.php:152` | One COUNT per resource on every page load, again on each sidebar/topbar refresh, and in record sub-navigation on every `live()` update. | High |
| 7 | ✅ ImportAction materialises the whole CSV and every job inside the HTTP request | `actions/src/Imports/ImportDispatcher.php:29-44` | `collect($generator)` → serialize → base64 for every chunk → a single `Bus::batch()`. A 1M-row file means 10k jobs built in-request: out-of-memory or timeout. | High |
| 8 | ✅ Import relationship lookup cache is cleared on every row | `actions/src/Imports/Importer.php:81-83`, `actions/src/Imports/ImportColumn.php:467-517` | One `first()` per row per relationship column, even when there are only ~10 distinct values. BelongsToMany columns add 1+N `exists()` per row (`:581-621`). | High |
| 9 | Export sorted by a non-key column falls back to OFFSET `chunk()` over `SELECT *` | `actions/src/Exports/Jobs/PrepareCsvExport.php:204-216` | O(n²/chunk) row scans just to pluck keys. Unstable ordering can skip or duplicate rows. | High |
| 10 | Live updates walk the whole schema tree and clear child-schema caches on every layout component | `schemas/src/Concerns/HasState.php:121-152`, `schemas/src/Components/Concerns/HasState.php:223` | O(n) schema rebuilds and hierarchy flushes per keystroke or blur on `->live()` fields. | High |
| 11 | ✅ `getChildSchema()` for the `'default'` key is never cached | `schemas/src/Components/Concerns/HasChildComponents.php:132-134` | Every Section, Tabs or Grid render builds a new Schema and re-parents the children, which flushes the subtree's key and path caches. Tabs calls it about 6 times. | High |
| 12 | `Schema::clearCachedChildSchemas()` rebuilds the whole tree (including cloning every repeater item) in order to clear it | `schemas/src/Concerns/HasComponents.php:519-528` | Triggered by any `$set()` to a path that isn't a field (`Set.php:36-39`), and by table filters. | High |
| 13 | ✅ Relationship Select fill runs `getResults()`, discards the result, and then queries again for the label | `forms/src/Components/Select.php:1332` (+ `:1274,1295,1308,1320`) | 2 queries per Select on edit pages. For BelongsTo the foreign key is already on the record. | High |
| 14 | Select option/label caches are per instance, and Repeater clones each Select | `forms/src/Components/Select.php:99-143,1085,1128` | N repeater items run N identical "load options" queries plus N label queries. CheckboxList already shares its cache across clones by reference. | High |
| 15 | `disableOptionsWhenSelectedInSiblingRepeaterItems()` | `forms/src/Components/Concerns/CanDisableOptionsWhenSelectedInSiblingRepeaterItems.php:17-43` | items × options × items work, plus casts on `$state`, on every render. | High |
| 16 | ✅ Postgres search and text constraints become `lower(col::text) like '%x%'` | `support/src/helpers.php:267-330`, `query-builder/.../TextConstraint/Operators/*.php:72-77` | No index can be used. "Equals" is a `LIKE` without wildcards. Affects table search, Select search, global search and the query builder. | High |
| 17 | ✅ Query-builder date operators use `whereDate`/`whereYear`/`whereMonth` | `query-builder/src/Constraints/DateConstraint/Operators/IsAfterOperator.php:203,217` (+ Before/IsDate/IsYear/IsMonth) | `hasTime` defaults to false, so `date(col) >= ?` is non-sargable and forces a full scan. Rewrite these as ranges. | High |
| 18 | ✅ Echo + Pusher (92 KB) is registered as a `core()` asset on every panel page | `panels/src/FilamentServiceProvider.php:143` | It is downloaded and parsed even when broadcasting is off. It's only used inside `hasBroadcasting()`. | High |
| 19 | ✅ Code editor bundles all 13 CodeMirror languages (1.0 MB minified, ~345 KB gzip) | `forms/resources/js/components/code-editor.js:10-22` | One field uses one language. A lazy-import path already exists (`:184`). | High |
| 20 | ✅ `SpatieTagsInput` suggestions run `Tag::query()->pluck('name')` with no limit | `spatie-laravel-tags-plugin/src/Forms/Components/SpatieTagsInput.php:95-117` | Every tag is put in a `<datalist>` in every Livewire response. With 20k tags, that's on every re-render. | High |

---

## 2. Cross-cutting themes

These patterns show up in several packages, and fixing each one once pays off in many places.

1. **Closure evaluation cost (#1).**
   - Cache parameter descriptors in a `static WeakMap<Closure, array>`.
   - Build `Get`/`Set` with `new` instead of `app()` (`schemas/src/Components/Concerns/HasState.php:854-862`).
   - Memoise state casts (`:94-103`).
   - Default date formats are closures (`tables/src/Columns/Concerns/CanFormatState.php:86,97,303`), so they pay reflection cost per cell just to return a constant.
2. **Remote-storage round trips during render:**
   - ImageColumn and ImageEntry `exists()` (#2).
   - RichEditor `exists()` once per embedded image (`forms/src/Components/Concerns/HasFileAttachments.php:242`), and the disk isn't memoised (`:185`).
   - FileUpload `exists` + `size` + `mimeType` = 3 HEAD requests per file (`forms/src/Components/BaseFileUpload.php:160,184,185`).
   - The media-library upload reads the whole file into memory (`spatie-laravel-media-library-plugin/src/Forms/Components/SpatieMediaLibraryFileUpload.php:141`).
   - **Fix:** default to no check on non-local disks, and memoise per path within the request.
3. **Polling defaults and global middleware:**
   - Widgets poll every 5s (#3).
   - Database notifications poll every 30s and run `count` + a redundant `exists()`, both filtering on JSON `data->format` with no index (`notifications/src/Livewire/DatabaseNotifications.php:125`).
   - ✅ `PublishNotifications` is prepended to the **global** kernel (`notifications/src/NotificationsServiceProvider.php:48`). It `json_decode`s every Livewire response (including big table HTML) and reads the cache every time (`notifications/src/Http/Middleware/PublishNotifications.php:19-44`).
   - `IdentifyTenant` runs 2 queries on every Livewire request (`panels/src/Http/Middleware/IdentifyTenant.php:38-42`).
4. **SQL that defeats indexes:**
   - #16, #17.
   - `SelectFilter` uses `whereHas` even for BelongsTo (`tables/src/Filters/SelectFilter.php:222,264`).
   - Search emits one `whereHas` per column × per word (`tables/src/Columns/Concerns/InteractsWithTableQuery.php:109`).
   - The tenancy global scope uses `whereHas` for many-to-many (`panels/src/Resources/Resource/Concerns/BelongsToTenant.php`).
5. **Caches lost on clone / per instance:**
   - Select caches (#14).
   - `getAlpineComponentSrc()` is resolved per editable cell (`support/src/Assets/AssetManager.php:112-126`).
   - `NumberFormatter` and the CommonMark converter are rebuilt per cell (`tables/src/Columns/Concerns/CanFormatState.php:252,293,420`).
   - `MethodAccess` reflects on every Livewire call (`support/src/Livewire/MethodAccess.php:39-75`).
6. **Per-row/per-cell work that belongs per column or per row:**
   - SelectColumn options are evaluated 3× per cell and the full list is embedded per row.
   - Record-link HTML and record-action JS are computed per cell.
   - The loading spinner SVG is inlined per button.
   - Each dropdown instance gets its own global listeners and observers.
   - Each editable cell registers a global Livewire commit hook.

---

## 3. Findings by area

### 3.1 Tables: query and state (`tables/src`)

#### High
- **Select-all count hydrates the whole table** when records have a selectable check (#4). Fix: compute the count lazily on select-all, or use `toBase()` with a minimal select and no eager loads. `getAllSelectableTableRecordKeys` and `getGroupedSelectableTableRecordKeys` (`HasBulkActions.php:133,215`) have the same `->get()`-everything pattern.
- **Group-header select-all runs a query per group.** It `json_encode`s every key in the group (all pages) into the HTML, at `index.blade.php:1236,2292,2385` → `HasBulkActions.php:191-232`. With a selectable check, that becomes a full `get()` per group. Fix: fetch keys on click.

#### Medium-High
- **`SelectFilter` on a BelongsTo still uses `whereHas` (correlated EXISTS)** at `SelectFilter.php:222,264`. Fix: short-circuit to `whereIn(fk)` / `whereNull(fk)`.

#### Medium
- **Filtered query rebuilt about 6–10× per render.** Deferred filters (the default) also swap the whole filter form's state path twice per call, and clear its caches (`Table/Concerns/HasFilters.php:47,216-237`). Callers include the records fetch, `index.blade.php:64,219`, summaries, group summaries, counts and the trailing-group check. Fix: memoise one base query per request and clone it.
- **Simple/cursor pagination still runs a COUNT** whenever bulk actions exist (`HasBulkActions.php:251-259`, `HasRecords.php:342-349`).
- **Trailing group check fetches a fully hydrated, eager-loaded model with OFFSET** at `CanSummarizeRecords.php:42-61`. Fix: `toBase()->select(group)->value()`.
- **Search emits one `whereHas` per column × per word** (`InteractsWithTableQuery.php:88-128`). Fix: group search columns by relationship.
- **Record resolution for mounted actions** re-applies every filter and relationship aggregate for a PK lookup, on every request, with no memo (`HasRecords.php:214-235`).
- **SelectColumn evaluates `getOptions()` up to 3× per cell** and runs `isOptionDisabled` per option per cell. `rememberOptions` is off unless the column uses a relationship (`SelectColumn.php:382,398,957,126-133,596`).
- **`Count::icons()` summarizer is an N+1:** one `count()` per distinct value, for each summary row (`Columns/Summarizers/Count.php:45-58`).

#### Low
- The summary query is built even when no column has a summarizer (`index.blade.php:64,219`).
- Record locales are re-applied to every record on every `getTableRecords()` call (`HasRecords.php:175-187`).
- Relationship summarizers run one query per summary row (`Summarizer.php:72-168`).
- Cell state cache key goes through Livewire on each `getState()` (`support/src/Concerns/HasCellState.php:618-672`).
- The column manager writes the full column array to the session on **every** boot (`HasColumnManager.php:36-103`).
- Filter indicators run a label query per render (`SelectFilter.php:62-107`).

### 3.2 Tables: rendering (`tables/src/Columns`, `tables/resources`)

#### High
- **ImageColumn existence and presign per image** (#2). `getDiskName` and visibility are evaluated 2–3× per image.
- **SelectColumn embeds the full options list in every row's `x-data`** (`SelectColumn.php:994`) or as `<option>`s (`:1069-1076`), so HTML size is O(rows × options).
- **Searchable SelectColumn builds the full dropdown DOM up front, plus one `document` click listener per cell** (`support/resources/js/utilities/select.js:286,1120`).

#### Medium-High
- **Each editable cell registers its own global `Livewire.hook('commit')`** (`tables/resources/js/utilities/editable-table-column.js:32-68`). 100 rows × 4 editable columns = 400 hooks running on every round trip.

#### Medium
- **`watchForCheckboxClicks` is re-bound for every Livewire `element.init`** (`table.js:92-99,569-585`). The listener is delegated on `$root`, so binding once is enough.
- **New `NumberFormatter` on every money/number cell** (`CanFormatState.php:252,293`).
- **New CommonMark converter per markdown cell,** and `isMarkdown` is evaluated twice (`:389,419-420`).
- **TextColumn drops its optimised path for any `HasIcon` enum,** even when there's no icon (`TextColumn.php:194`). Fix: check `filled($state->getIcon())`.

#### Low-Medium
- **Row-level work repeated in every cell:** `generate_href_html()` with the SPA `Str::is` regex and the inline `Alpine.navigate` handler, `getExtraRecordLinkAttributeBag`, record-action `Js::from`, and `e($column->getLabel())` (`index.blade.php:2580-2598`). Fix: compute once per row or column.
- **The ~300-byte `LOADING_TARGETS` string is imploded per editable control and per row checkbox** (`Table.php:58-79`, `index.blade.php:2528,2655`).
- **Identical icon SVGs are rendered per cell** (IconColumn, and 2–3 per ToggleColumn). Fix: memoise or use a sprite.
- `getAlpineComponentSrc()` per editable cell, `__()` per cell in update messages, and Tippy per tooltip cell.

### 3.3 Schemas core (`schemas/src`, used by both forms and infolists)

#### High
- **Uncached closure reflection** (#1).
- **Default child schemas are not cached** (#11).
- **Live updates walk the whole tree** (#10). A component without a state path reports its container's path, so the ancestor check `startsWith("data.")` matches every layout component.
- **`clearCachedChildSchemas` rebuilds the tree** (#12).

#### Medium-High
- **Repeater/Builder rebuild the template schema per item and re-parent the shared originals** (`HasComponents.php:464-478`, `forms/.../Repeater.php:904-912`). Fix: clone first, then set the container.
- **`$get()` lookup cache is keyed by the calling component** (`Components/Utilities/Get.php:29-38`, `HasComponents.php:283-287`). N fields with `visible(fn (Get $get) => …)` means N full DFS lookups, which is O(n²).
- **`Get`/`Set`/casts built via `app()` (reflection) on every injection and on every `getState()`.**

#### Medium
- **`getState()` does about 7 separate full-tree walks** (validate rules, messages and attributes, before-dehydrate, dehydrate, mutate), and it uses array spreads at every level (`CanBeValidated.php:31-96`).
- **Each hidden or non-dehydrated field scans its whole statePath scope** during dehydration (`Components/Concerns/HasState.php:322-437`). That's O(hidden × n).
- **View-backed components create a closure for every public getter on each render** (`support/src/Components/ComponentManager.php:169-173`).

#### Low
- `md5(json_encode(getState()))` runs per `afterStateUpdated` callback (`HasState.php:212`).
- `(array) $livewire` cast per component in `fillStateWithNull` (`:617`).
- `hydrateState` rewrites the whole container state for every layout component (`:479-491`).
- `flushCachedHierarchy` climbs to the root once per descendant schema.
- `preg_replace` runs on every relative key/path lookup.

### 3.4 Form fields (`forms/src`)

#### High
- **Relationship Select double-query** (#13).
- **Select caches lost per repeater clone** (#14).
- **Sibling-disable is O(items² × options)** (#15).
- **RichEditor checks `exists()` per image on hydrate and on save** (`HasFileAttachments.php:242`).
- **RichEditor builds a new TipTap `Editor` and re-parses the HTML on every `getState()`/cast** (`RichEditor.php:804-810`, `RichEditorStateCast.php:21,70,120`). Custom-block previews render Blade views per set.
- **Builder renders a full block picker (every block, with icons) between every pair of items** (`Builder.php:1928-1943,1446-1660`). 30 items × 40 blocks ≈ 1,200 buttons per response.

#### Medium
- **FileUpload makes 3 HEAD requests per file,** plus one `$getUploadedFiles` round trip per instance on init (`BaseFileUpload.php:160-204`, `file-upload.js:635-650`).
- **Repeater/Builder item labels are computed twice per item.** With `$state`, each one runs a full item dehydrate (`Repeater.php:1674,2304`; `Builder.php:1857,2017`).
- **Relationship Repeater re-queries and re-hydrates its records on every Livewire request** (`Repeater.php:63,1252-1307`).
- **`getOptions()`/`getOptionLabels()` are uncached and re-run in validation.** `modifyQueryUsing` disables caching entirely (`Select.php:1117,1129,1181`; `CheckboxList.php:201,241`).
- **`isOptionDisabled()` does one full `evaluate()` per option per render.**
- **Non-searchable relationship Select (the default) loads the whole table** into a native `<select>` (`Select.php:1073,1151-1155`).

#### Low
- Select casts resolve the relationship on each `getState()` (`Select.php:1747-1771`).
- ModalTableSelect's label cache isn't keyed by state.
- Collapsed repeater items still render their full schema.
- Reorder issues one UPDATE per row.

### 3.5 Panels (`panels/src`)

#### High
- **Eager navigation badges** (#6). Relation-manager tabs already support lazy badges, so navigation could do the same.
- **`getRedirectUrl()` creates a new NavigationManager** (#5). Add a cheap `getFirstNavigationUrl()` that skips badge evaluation, and memoise it per tenant.
- **Tenant list loaded 3–4× per page, never memoised** (`FilamentManager.php:605,648-657`; `Livewire/Concerns/HasTenantMenu.php:43,114-130`). `getUserDefaultTenant()` loads every tenant just to take the first. The switcher renders every tenant with no limit.
- **`ListRecords` per-row `recordAction`/`recordUrl`** clone the whole root ActionGroup and run 3–6 policy checks per row (`Resources/Pages/ListRecords.php:140-226`). Same in `Resources/Concerns/InteractsWithRelationshipTable.php:136,198`.
- **Without `filament:optimize`, discovery runs on every request in the app,** including API and front-end routes: `allFiles` + `class_exists` + reflection (`Panel/Concerns/HasComponents.php:501-560`; panels built eagerly at `FilamentServiceProvider.php:161`).

#### Medium
- **`IdentifyTenant` runs on every Livewire request** (route-binding query + `canAccessTenant`).
- **Tenancy scope uses `whereHas` for many-to-many ownership** on every model query.
- **Sub-navigation is rebuilt (policies + badges) on every Livewire update** (`Pages/Concerns/HasSubNavigation.php:56`).
- **The record is fetched twice per request on record pages:** once by ModelSynth and again by `resolveRecordFromLivewire` with write PDO (`Resources/Pages/Page.php:61-107`). Needs confirming against Livewire internals.
- **Global search:**
  - one sequential query per resource
  - 50 results per resource by default
  - `canView`/`canEdit` policies per result
  - `%term%` per word × attribute
  - **no minimum length** (`Resources/Resource/Concerns/HasGlobalSearch.php:26,36-50,87-161,232-243`)
- **`canAccess()`/`canViewAny()` aren't memoised across the request.** The same policy runs in navigation, sub-navigation, search and parent checks.

#### Low
- `NavigationManager::get()` clones and serialize-regroups on every call. Collapsible sidebar groups render badges twice.
- `HasAuthorization.php:88` creates a new `ReflectionMethod` per `canView`/`canEdit`/`canCreate` (confirmed; micro).

### 3.6 Support (`support/src`, `support/resources`)

#### High
- **Each `filamentDropdown` instance gets its own global listeners and observers.** One per table row's action group:
  - `resize` and `livewire:navigate` listeners
  - 2 subtree MutationObservers
  - 1 ResizeObserver

  (`resources/js/components/dropdown.js:78-79,144-200`). Every morph fires all of them. Fix: one delegated listener, with setup deferred until first open.

#### Medium
- **`getAlpineComponentSrc()` isn't memoised:** `Arr::flatten`, `asset()` and `InstalledVersions` (+ sha256 on dev) run per call (`Assets/AssetManager.php:112-126`, `Asset.php:65-106`).
- **The loading-spinner SVG (~600 B) is inlined in every button, badge and dropdown item that has `wire:click`** (`View/DefaultLoadingIndicator.php`). 50 rows × 4 actions ≈ 120 KB per response. Use an SVG `<symbol>` or a CSS spinner.
- **`generate_href_html()`** runs a per-URL SPA regex, `parse_url` ×2 and host parsing, and emits about 190 B of inline JS (`helpers.php:143-185`).
- **Anonymous `<x-filament::*>` components get Laravel's Collection-based attribute bag,** not Filament's optimised one (`View/ComponentAttributeBag.php`).
- **`Str::markdown` builds a new converter per call** (`Markdown.php:26-31`).
- **The core JS bundle (196 KB, ~66 KB gzip) eagerly ships SortableJS, tippy, floating-ui and js-md5** (`resources/js/index.js`). Sortable and md5 could be lazy.

#### Low
- `filter()`/`whereStartsWith()` allocate Collections per control.
- `ColorManager` `serialize()`s cache keys (`Colors/ColorManager.php:119,168`).
- `MethodAccess` reflects on every Livewire call.
- `copyable.js` adds a MutationObserver per cell.
- `modal.js` keeps document listeners registered while closed.
- Render hooks allocate even when none are registered.

### 3.7 Actions, import and export (`actions/src`)

#### High
- **Import dispatch runs in the request** (#7). Fix: mirror the export design (a prepare job that streams and calls `batch()->add()`).
- **Per-row relationship lookups** (#8).
- **BelongsToMany 1+N `exists()`** (`ImportColumn.php:581-621`). Compute from the records already fetched.
- **OFFSET export chunking** (#9). Select only key and order columns, use `chunkKeySize`, or use keyset pagination.

#### Medium
- **ExportCsv eager-loads and aggregates every exporter column,** not just the selected ones (`Exports/Jobs/ExportCsv.php:89-92`).
- **The import modal re-opens and re-sniffs the uploaded file on every Livewire request:** encoding, delimiter and header (`ImportAction.php:103-208,575-610`). On S3 that's a download each time.
- **Row count reads the whole file in-request,** even when `maxRows` is going to reject it (`ImportAction.php:208-226`).
- **Authorization tooltip path runs the policy up to 3× per action per render, outside the visibility cache** (`Concerns/HasTooltip.php:21-27`, `Concerns/CanBeAuthorized.php:119-168,248`).
- **Per-row `find()` with validation rules rebuilt per row,** and no prefetch hook (`Importer.php:176-225`).
- **Failed rows are inserted one at a time** (`Jobs/ImportCsv.php:139`).

#### Low
- 3 locked UPDATEs per chunk to clamp counters.
- XLSX download can rebuild synchronously in-request.
- Failed-rows CSV is fully built before streaming, and its flush condition is inverted (`Downloaders/CsvDownloader.php:16-27`).
- Each export chunk re-runs the original `LIKE`/where predicates.
- `wire:key` md5 of the serialized context.

### 3.8 Widgets, notifications, query builder, infolists, plugins

#### Widgets
- **5s default polling** (#3).
- **ChartWidget's `updateChartData()` never calls `skipRender()`,** so each poll re-renders the whole view and re-hashes the dataset twice (`ChartWidget.php:121-136`).
- **StatsOverview polls `$refresh`:** it rebuilds every Stat and md5s every sparkline.
- **Each lazy widget is a separate request** (needs checking against Livewire isolation).
- **Page filters re-render every widget at once.**

#### Notifications
- **JSON-path unread count + redundant `exists()`,** every 30s per tab (`DatabaseNotifications.php:125`, view `:10-11`). The poll still runs even when Echo is configured.
- **The open list re-renders and re-hydrates all 50 notifications every poll.**
- **Global `PublishNotifications` middleware** (theme 3).
- **`sendToDatabase()`/`broadcast()` rebuild the payload per recipient,** and fire the "sent" event before the queued insert (`Notification.php:177-207`).

#### Query builder
- **Non-sargable date operators** (#17).
- **Postgres `lower(::text)` text operators,** and Equals implemented as `LIKE` (#16).
- **Relationship-rule indicator runs a query per render** with a needless `orderBy` (`IsRelatedToOperator.php:68-84,295-297`).
- **The rule-limit check recounts the whole tree inside every action closure** (`RuleBuilder.php:275-307`).

#### Infolists
- **`RepeatableEntry` over a relationship is N+1.** Nothing in infolists eager-loads child-entry relationships (`RepeatableEntry.php:85-112`).
- **Entry state is never memoised.** It's re-resolved for every `$state`-injected closure (`Entry.php:93-96`, `schemas/.../HasState.php:1008-1035`).
- **ImageEntry `exists()` default** (#2), and the default-image URL is computed for every image (`ImageEntry.php:570`).

#### Media library plugin
- **O(n²) uuid scan per image,** ignoring the Media model it already has (`SpatieMediaLibraryImageEntry.php:111`, `SpatieMediaLibraryImageColumn.php:109`).
- **The column eager-loads every media row, not only its collection** (`SpatieMediaLibraryImageColumn.php:186-202`).
- **The upload reads the whole file into memory via `addMediaFromString($file->get())`** (`SpatieMediaLibraryFileUpload.php:141`). Use streams instead.
- **`load('media')` runs per field on fill.** `setNewOrder` runs even when the order hasn't changed.

#### Tags plugin
- **The input loads every tag** (#20).
- **`load('tags')` runs on every fill.**
- **One `findFromString` query per tag on save.**
- **`SpatieTagsEntry` doesn't cache state or eager-load.**

#### Settings plugin
- Clean.

### 3.9 Frontend JS and build

#### Largest bundles

| Bundle | Minified | Gzip | Loaded |
|---|---|---|---|
| forms code-editor.js | 1.0 MB | ~345 KB | when a code editor is present |
| panels theme.css | 694 KB | ~69 KB | every page |
| forms rich-editor.js | 555 KB | ~175 KB | when a rich editor is present |
| forms markdown-editor.js | 524 KB | ~168 KB | when a markdown editor is present |
| forms file-upload.js | 376 KB | | when a file upload is present |
| widgets chart.js | 287 KB | ~95 KB | when a chart is present |
| support index.js | 196 KB | ~66 KB | every page |
| panels echo.js | 92 KB | | **every page** |

The build (`bin/build.js`) produces one IIFE per component, with no ESM and no `splitting`, so shared dependencies (e.g. the select utility and Sortable) are duplicated across bundles. Every `x-load` uses the eager strategy, so each instance initialises at once, even inside collapsed sections, hidden tabs and repeater items.

#### High
- **Echo runs on every page** (#18). Make it `loadedOnRequest()` and emit it only when `hasBroadcasting()`.
- **Code editor ships every language** (#19).
- **FileUpload re-downloads every existing file with `cache: 'no-store'` on every page load** (`file-upload.js:300`). It always fetches the original, never a thumbnail.

#### Medium-High
- **The Tabs MutationObserver watches the entire tab contents** (subtree + characterData), runs one `querySelector` per mutation record, and reassigns `availableTabs` (`schemas/resources/js/components/tabs.js:120-142`). Typing in a RichEditor inside a tab triggers it on every keystroke.

#### Medium
- **Markdown editor eagerly loads about 35 CodeMirror 5 modes** (`markdown-editor.js:6-43`).
- **FileUpload bundles about 45 FilePond locales and the full `mime` database** (`file-upload.js:3,1179-1221`).
- **Any FileUpload resize dispatches a global `visibilitychange`,** which wakes every FilePond. With N uploads that's O(N²) work (`file-upload.js:149-157`).
- **RichEditor:**
  - On every keystroke: `getJSON()` + 2× `JSON.stringify` (one of them through the reactive proxy).
  - On every transaction: every toolbar `isActive` is re-evaluated (`rich-editor.js:407-449`).
  - No `x-load="visible"`.
- **CheckboxList search** reads `innerText` about 4× per option and interleaves `x-show` writes, which causes layout thrash (`checkbox-list.js:49-115`).
- **DateTimePicker re-parses the disabled-dates JSON about 84× per calendar render** (`date-time-picker.js:315-330`), and bundles 51 dayjs locales.

#### Low
- **Select local search** re-normalises every option per keystroke and deep-clones when the query is cleared (`support/resources/js/utilities/select.js:1757,1965-2012`).
- **The select utility is bundled twice** (forms + tables).
- **EasyMDE `handleImages`** forces layout on every update.
- **The unsaved-changes alert** md5s the whole `$wire.data` on every navigation.

---

## 4. Suggested plan of attack

Order is by impact against effort.

### Quick wins: small diffs, large effect
- Defaults:
  - Widget polling 5s → `null` or 60s.
  - `checkFileExistence` off for non-local disks.
  - Echo becomes `loadedOnRequest()`.
- Navigation badges as closures.
- `getRedirectUrl()` should not create a new NavigationManager.
- Unread count short-circuits `exists()`.
- `skipRender()` in `ChartWidget::updateChartData()`.
- Use the `getResults()` result in Select fill.
- `ImportColumn` cache scoped to the job, not the row.
- Filter ExportCsv eager loads to the selected columns.
- `FailedImportRow::insert()` in bulk.

### Core memoisation: one PR each, touches everything
1. Reflection cache in `EvaluatesClosures`.
2. Cache `getChildSchema('default')`, and stop `clearCachedChildSchemas` rebuilding the tree.
3. Prune the `callAfterStateUpdated` walk to true ancestors.
4. A `$get` lookup cache that isn't keyed by caller.
5. A shared Select option/label cache across clones, plus a label prefetch.
6. Memoise `getAlpineComponentSrc`, the `NumberFormatter`, the CommonMark converter and `hasSpaMode`.

### SQL shape
1. Range-based date operators.
2. Use `ilike` instead of `lower(::text)` on Postgres; make Equals use `=`.
3. A BelongsTo short-circuit in `SelectFilter`.
4. Group search `whereHas` by relationship.
5. Keyset or key-only export chunking.
6. Lazy select-all counts and keys.

### Frontend payload and initialisation
1. Lazy CodeMirror languages and modes, and lazy FilePond locales.
2. Use `x-load="visible"` for heavy editors.
3. Shared SelectColumn options.
4. One spinner symbol.
5. Lazy dropdown setup.
6. A single commit hook for editable cells.
7. Scope the Tabs observer to its headers.

### Validate first
Before any of this, profile a representative app: a 50-row table with image, select and toggle columns; an edit page with a Repeater of relationship Selects plus RichEditor; a tenant panel with badges. Use SPX/Xdebug plus a query log, and count `ReflectionFunction`, `Schema::make` and `flushCachedHierarchy` calls along with Storage calls. That turns the estimated severities into measured numbers and gives each PR a before/after.
