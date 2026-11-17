# Review: Schemas core (3.3), Form fields (3.4), Infolists (3.8), themes 1 and 2 (forms parts)

Checked against Filament **v5.11.4** (a `filamentphp/filament` checkout, HEAD `6427cc1`). All paths are relative to `packages/`.

**Key fact first:** `git diff 6035bbc HEAD` over `schemas/src`, `forms/src`, `infolists/src` and `support/src/Concerns/EvaluatesClosures.php` shows no change to any performance-relevant code. The only changes are CodeEditor language typing, the new `JsField`/`JsComponent`/`HasJsRenderer`, make-commands, and a `$wire;` prefix in `afterStateUpdatedJs`. So **none of these findings is fixed in 5.x**, and the report's line numbers still match 5.11.4 almost exactly. The verdicts below rest on the code itself, not on drift.

Nothing was run. This is a static review, like the report.

## Counting without patching Filament

Most framework objects in these packages are built through the Laravel container, so you can count them with `app()->resolving(...)` from a service provider in the benchmark app:

- `Schema::make()` uses `app(static::class, …)` (`schemas/src/Schema.php:72-78`). `app()->resolving(\Filament\Schemas\Schema::class, fn () => $n++)` counts every Schema **built** (clones made with `getClone()` are not counted).
- `Get` and `Set` come from `app(Get::class, …)` (`schemas/src/Components/Concerns/HasState.php:854-862`), so you can count them with `resolving(Get::class)` and `resolving(Set::class)`.
- State casts come from `app(XStateCast::class, …)`. Laravel's resolving callbacks match with `instanceof`, so `resolving(\Filament\Schemas\Components\StateCasts\Contracts\StateCast::class)` counts every cast object.
- Every `Field::make`, `Section::make` and similar call goes through `app()` too, so you can count component constructions as well (clones excluded).
- `ReflectionFunction` cannot be hooked this way. Use an Xdebug function trace (`xdebug.mode=trace`, `xdebug.collect_params=0`) and count `ReflectionFunction->__construct` lines, or count with an Xdebug or SPX profile. The other option is A/B timing: the same form with closures versus with literal values.
- Use `DB::listen` or a query log for SQL. For Storage, wrap the disk with a decorator or `Storage::fake()` plus a counting adapter. On S3, the request count is visible in the Flysystem/Guzzle middleware or MinIO logs.

---

### #1 `evaluate()` builds a new ReflectionFunction per call
- **Report:** every closure evaluation runs `new ReflectionFunction($value)` with no metadata cache.
- **Verdict:** **Confirmed.** There is no cache anywhere: no static map, no WeakMap, no parameter cache. No subclass overrides `evaluate()`. The `resolveDefaultClosureDependencyForEvaluationBy*` overrides only add injections, and they add cost per parameter on top.
- **Where on 5.11.4:** `support/src/Concerns/EvaluatesClosures.php:24-37` (`:32`), and again at `:111` in `evaluationValueIsFunctionAndHasParameter()`.
- **Evidence:**
  ```php
  if (! $value instanceof Closure) { return $value; }
  foreach ((new ReflectionFunction($value))->getParameters() as $parameter) {
      $dependencies[] = $this->resolveClosureDependencyForEvaluation($parameter, $namedInjections, $typedInjections);
  ```
  Each parameter also costs `getType()` and `getDeclaringClass()` (`:136-165`), then a `match` in `schemas/src/Components/Component.php:110-123`. For `$get`, `$set` and `$state` that builds a `Get` or `Set` via `app()`, or runs `getState()` with casts. A parameter that is typed and unresolved falls through to `app()->make($type)` (`:85-90`).
  How many closures get evaluated: non-closure values return at the `instanceof` check, so the cost is only for closures. Wiring done by the framework adds several per component even before any user closures:
  - every Field has an `afterLabel` closure from `setUpHint()` (`forms/src/Components/Concerns/HasHint.php:34`). It is evaluated on **every** `getChildSchemas()` walk (see N-1).
  - `Select::setUp()` adds `transformOptionsForJs`, `placeholder` and two `suffixActions` closures (`forms/src/Components/Select.php:155-174`).
  - `relationship()` adds `options`, `getOptionLabelUsing`, `getSelectedRecordUsing`, `getOptionLabelsUsing`, `loadStateFromRelationshipsUsing`, `saveRelationshipsUsing` and `dehydrated` (`:798-940`).
  - By static count, a searchable relationship Select evaluates about 6–8 closures per render. A plain TextInput evaluates about 1–2, plus the user's closures. Each tree walk adds one more per Field for `after_label`.
- **Measure:** (a) An Xdebug trace of one Livewire update on a form with N=100 `TextInput::make("f$i")->label(fn () => "L$i")->visible(fn (Get $get) => true)`. Count `ReflectionFunction->__construct` and expect hundreds to thousands, roughly N × (closures per field) × (walks per request). (b) A/B timing: the same form with `->label("L$i")->visible(true)`. The difference divided by the number of evaluations gives the cost per evaluation. (c) A micro-benchmark: 1e5 calls of `$component->evaluate(fn (Get $get, $state) => 1)` against `fn () => 1`, which isolates reflection from injection cost.

### #11 `getChildSchema()` for `'default'` is never cached
- **Report:** every Section, Tabs or Grid render builds a new Schema and re-parents the children, which flushes the subtree's key and path caches. Tabs calls it about 6 times.
- **Verdict:** **Confirmed** for the core claim. **Overstated** for Tabs: about 2–3 calls per Tabs render, plus 1 per Tab.
- **Where on 5.11.4:** `schemas/src/Components/Concerns/HasChildComponents.php:116-183` (`$isCacheable = ($key !== 'default') && …` at `:132-134`). Render-time callers:
  - `Section.php:336`, `Grid.php:41`, `Group.php:48`, `Fieldset.php:72`, `Flex.php:74`, `FusedGroup.php:233,298`, `Form.php:108`, `Wizard/Step.php:207`, `Tabs/Tab.php:229`.
  - `Tabs.php:153` (only when the tab is persisted in the query string), `:299`, `:1009` (`hasLazyLoadedBadges`), and `:667` on the Livewire-property path.
  - `Builder.php:1016` (`getBlocks()`).
- **Evidence:** `getChildSchema()` with no key goes to `getDefaultChildComponents()` and then `makeChildSchema()`, which is `Schema::make()` via `app()`. The new Schema's `getComponents()` calls `$component->container($this)`. That container differs from the cached one, so it runs `flushCachedHierarchy()` (`BelongsToContainer.php:15-31`), which recurses into the child schemas. The cached path (`getCachedDefaultChildSchemas()`) exists and is used by tree walks, but render bypasses it. Without a `livewireProperty`, every Tab is "active" (`Tab.php:209`), so every Tab panel renders its schema.
- **Measure:** the count of `Schema` resolutions (`app()->resolving(Schema::class)`) during one render of a page with 5 `Section`s, plus one `Tabs` with 3 `Tab`s, each holding 4 TextInputs. Expect about 5 + 2 + 3 = 10 extra `Schema::make` per render beyond the cached tree, and the same again on every Livewire update. Also time the render with 50 Sections against 50 Groups-in-a-cached-container. Triggered by plain layout components; no special configuration is needed.

### #10 Live updates walk the whole schema tree and clear child-schema caches on every layout component
- **Report:** a component without a state path reports its container's path, so `startsWith("data.")` matches every layout component, and each one clears its child schemas.
- **Verdict:** **Confirmed**, with one nuance. The walk returns early once the target field is found, so the layout components **visited before the target in depth-first order** are hit, not literally all of them. Every ancestor Repeater is hit as well, and each one rebuilds all its item clones.
- **Where on 5.11.4:** `schemas/src/Concerns/HasState.php:121-152`. Called for every cached schema from `schemas/src/Concerns/InteractsWithSchemas.php:315-324` (`updatedInteractsWithSchemas`). The per-component hooks are at `schemas/src/Components/Concerns/HasState.php:178-224` (`clearCachedChildSchemas()` at `:223`). The state path fallback is at `:750-772`.
- **Evidence:**
  ```php
  if (str($path)->startsWith("{$componentStatePath}.")) {
      $component->callAfterStateUpdated(shouldBubbleToParents: false);   // Section's path === "data"
  }
  foreach ($component->getChildSchemas() as $childSchema) { … }        // rebuilds what was just cleared
  ```
  Livewire fires `updated` once per property sent in the request, so a request carrying K deferred (non-live) field changes runs K walks. It also runs once per cached schema on the page.
- **Measure:** the time and `Schema` resolution count of the Livewire update after typing in one `TextInput::make('last')->live()` that is the **last** field of a form with S=20 `Section`s × 10 TextInputs, compared with the same field placed **first**. Expect the cost to grow with S when the field is last and stay flat when it is first. Second case: the field inside a `Repeater` of 20 items (`->live()` inside the item). Expect about 20 `Schema` resolutions per keystroke from rebuilding the items. Third case: a form with 50 deferred fields edited and then one `->live()` blur. Expect about 51 walks; time it.

### #12 `Schema::clearCachedChildSchemas()` rebuilds the tree to clear it
- **Report:** it calls `getChildSchemas()` on every component after clearing it, which re-clones repeater items. Triggered by `$set()` to a path that is not a field, and by table filters.
- **Verdict:** **Confirmed.** The tree it rebuilds stays cached afterwards, so the result is effectively "rebuild everything".
- **Where on 5.11.4:** `schemas/src/Concerns/HasComponents.php:519-528`. Triggers: `schemas/src/Components/Utilities/Set.php:35-39` and `tables/src/Table/Concerns/HasFilters.php:231-237` (`setFiltersFormStatePath`, run twice per `withAppliedFiltersFormState` call with deferred filters).
- **Evidence:**
  ```php
  foreach ($this->getComponents(withActions: false, withHidden: true) as $component) {
      $component->clearCachedChildSchemas();
      foreach ($component->getChildSchemas(withHidden: true) as $childSchema) { $childSchema->clearCachedChildSchemas(); }
  ```
  For a Repeater, `getChildSchemas()` runs `getDefaultChildSchemas()`: one `Schema::make` plus a deep `getClone()` per item (`forms/src/Components/Repeater.php:890-914`). Nested repeaters multiply.
- **Measure:** `Schema` resolutions and time for one `afterStateUpdated(fn (Set $set) => $set('not_a_field', 1))` on a `->live()` field, in a form with a Repeater of N=20 items × 5 fields. Expect at least 20 extra `Schema::make` plus 20 deep clones, compared with `$set('existing_field', 1)`, which expects 0. With a nested Repeater (20 × 10), expect at least 220.

### 3.3-a Repeater/Builder rebuild the template schema per item and re-parent the shared originals
- **Report:** `getDefaultChildSchemas()` builds a fresh template Schema per item, then `cloneComponents()` re-parents the originals before cloning.
- **Verdict:** **Confirmed.** The extra cost is small next to the deep clone that is unavoidable anyway. It also leaves every original template component's container pointing at the last item's clone schema.
- **Where on 5.11.4:** `forms/src/Components/Repeater.php:890-914`, `forms/src/Components/Builder.php:1032-1056` (which also calls `getBlocks()`, another uncached `getChildSchema()`), `schemas/src/Concerns/HasComponents.php:464-478`.
- **Evidence:** `$this->getChildSchema()->statePath($itemKey)->…->getClone()` per item. In `cloneComponents()`: `$component->container($this)->getClone()`, where `$this` is the clone. That re-parents the original and calls `flushCachedHierarchy()` on it once per item.
- **Measure:** `Schema` resolutions while building a Repeater with N items. Expect N (one per item) where 1 is needed. Also measure the render time of a Repeater with 50 items × 10 fields.

### 3.3-b `$get()` lookup cache is keyed by the calling component
- **Report:** N fields with `visible(fn (Get $get) => …)` means N separate lookups, which is O(n²).
- **Verdict:** **Confirmed**, with a refinement. Each lookup is not a full DFS: it prunes components whose own state path is not a prefix. It does iterate every component of each container it visits, and every layout component matches (see #10), so a flat or Section-only form is O(N) per caller and O(N²) in total.
- **Where on 5.11.4:** `schemas/src/Components/Utilities/Get.php:23-49` (a static caller stack is passed as the skip list), `schemas/src/Concerns/HasComponents.php:240-314` (`$cacheKey = implode('-', $skipIds)` at `:283-287`).
- **Evidence:** `static::$skipComponentsChildSchemasWhileSearching[] = $this->component;` is passed as `skipComponentsChildContainersWhileSearching`, so the cache bucket is per caller. See also N-2: render-time rebuilds wipe this cache.
- **Measure:** the initial render and one Livewire update of a form with N ∈ {50, 100, 200} `TextInput`s each `->visible(fn (Get $get) => filled($get('toggle')))`, plus one `Toggle::make('toggle')`. Compare time with `->visible(true)`. Expect super-linear growth, with time roughly 4× when N doubles. To confirm, a profile should show `getComponentByStatePath` called about N times per pass, each scanning about N components.

### 3.3-c `Get`/`Set`/casts built via `app()` on every injection and every `getState()`
- **Report:** container resolution (with reflection) per injection and per cast.
- **Verdict:** **Confirmed.** It still uses `app()`, and each `getState()` and `state()` call rebuilds the cast list. The reflection cost lives inside Laravel's `Container::build()` (a `ReflectionClass` per build, plus the resolving-callback scan). Laravel's source is not in this checkout, so that part is from knowledge of Laravel 11/12, not verified here.
- **Where on 5.11.4:** `schemas/src/Components/Concerns/HasState.php:854-862` (Get/Set), `:94-103` (`getStateCasts()`, called from `getState()` `:977-986`, `state()` `:663-672`, `getStateToDehydrate()` `:300-317`, and `getOldState()`). Cast factories: `forms/src/Components/Field.php:105-128`, `Select.php:1747-1771`, `Toggle.php:134`, `TextInput.php:310`, `DateTimePicker.php:373`, `RichEditor.php:753-759`, and others.
- **Evidence:** `return app(Get::class, ['component' => $this]);` and `return [app(OptionStateCast::class, ['isNullable' => true, 'shouldCastToInteger' => …])];`. View-backed components also build a `Get` on every render via `getExtraViewData()` (`Component.php:149-156`).
- **Measure:** count `resolving(Get::class)`, `resolving(Set::class)` and `resolving(StateCast::class)` during one `getState()` (save) of a form with 100 `Select::make()->options([...])` fields. Expect at least 100 casts per pass across about 3–4 passes, so 300–400 or more casts. Compare the time of 1e5 `$field->getState()` calls with `new` against `app()` by subclassing in the app.

### 3.3-d `getState()` does about 7 separate full-tree walks, with array spreads at every level
- **Report:** validate rules, messages and attributes, before-dehydrate, dehydrate and mutate are separate walks, and each level does `[...$a, ...$b]`.
- **Verdict:** **Confirmed.** The count is actually higher. In `Schema::getState()` (`schemas/src/Concerns/HasState.php:587-643`): the hidden pre-filter, `getValidationRules`, `mutateStateForValidation` (via Livewire `prepareForValidation`), `getValidationMessages`, `getValidationAttributes`, `callBeforeStateDehydrated`, `saveRelationships`, `loadStateFromRelationships`, `dehydrateState` and `mutateDehydratedState`. That is about 9–10 walks. `withVisibilityCache` removes repeated `isHidden()` evaluations, but each walk still calls `getChildSchemas()` (and `after_label` closures, see N-1).
- **Where on 5.11.4:** `schemas/src/Concerns/CanBeValidated.php:13-104` (spreads at `:31-34`, `:62-65`, `:93-96`), `schemas/src/Concerns/HasState.php:283-372`.
- **Measure:** the time of a save request (`$this->form->getState()`) on a form with 10 Sections × 20 fields, and the `getChildSchemas` call count from a profile. Expect about 10 × (number of components) calls. Spread cost shows up as `array` copying growing with depth: nest 5 Groups deep against flat.

### 3.3-e Each hidden or non-dehydrated field scans its whole state-path scope during dehydration
- **Report:** O(hidden × n).
- **Verdict:** **Partly.** The first check, `getRootContainer()->hasDehydratedComponent()`, is already indexed by state path, cached per dehydrate pass, and pruned to the branch (`schemas/src/Concerns/HasState.php:177-275`, `getComponentsIndexedByStatePath`, `dehydratedComponentsCache`). The second step, `hasComponentWithStatePath()` → `searchOtherComponentWithStatePath()`, is still a linear scan of the scope for each such field, so O(hidden × n) remains there.
- **Where on 5.11.4:** `schemas/src/Components/Concerns/HasState.php:322-460` (`:385-433` for the scan).
- **Measure:** the `getState()` time on a form with N=200 fields, of which H ∈ {0, 50, 100, 200} are `->hidden()` (not `dehydratedWhenHidden`), all in one Section. Expect time to grow with H × N. A profile shows `searchOtherComponentWithStatePath` called H times, each iterating about N components.

### 3.3-f View-backed components create a closure for every public getter on each render
- **Report:** `extractPublicMethods()` makes `$component->$method(...)` for every public method.
- **Verdict:** **Partly.** The behaviour is true, but it is memoised per component instance per view (`$this->viewInstances[$view] ??=`), and it only applies to components **without** `HasEmbeddedView` or with a published view override. On 5.11.4 nearly every built-in field, layout and entry is an embedded view. The view-backed ones are `ViewField`, `View`, `Placeholder`, `MorphToSelect`, `EmbeddedTable`, `ViewEntry`, and custom or third-party components. The method name list is cached per class, and fluent setters are filtered out for `Filament\` classes.
- **Where on 5.11.4:** `support/src/Components/ComponentManager.php:138-175`, `support/src/Components/ViewComponent.php:224-238`.
- **Measure:** the render time of 100 `ViewField::make("f$i")->view('x')` (trivial view) against 100 `TextInput`s. Expect the ViewFields to be noticeably slower, with about 150–300 closures each. Only relevant to apps with custom view components.

### 3.3-g `md5(json_encode(getState()))` per `afterStateUpdated` callback
- **Verdict:** **Confirmed** (Low). It is significant only for components with large state that have hooks, such as a Repeater or Builder with `afterStateUpdated`, or a layout component with hooks (whose `getState()` is the whole form data).
- **Where on 5.11.4:** `schemas/src/Components/Concerns/HasState.php:209-224` (`:212`).
- **Measure:** the time of a Repeater (100 items × 10 fields) item add with `->afterStateUpdated(fn () => null)` set three times, against none.

### 3.3-h `(array) $livewire` cast per component in `fillStateWithNull`
- **Verdict:** **Confirmed** (Low). It copies the Livewire object's property table once per component per fill. The values are refcounted, so it is cheap.
- **Where on 5.11.4:** `schemas/src/Components/Concerns/HasState.php:614-625` (`:617`).
- **Measure:** the time of `form->fill()` with 500 fields. Expect only a small share of total time; micro-benchmark only.

### 3.3-i `hydrateState` rewrites the whole container state for every layout component
- **Verdict:** **Confirmed** (Low–Medium). This happens on edit fills, where `$hydratedDefaultState === null`. A layout component's `getRawState()` is the whole `data` array, because its state path is the container's path. It is `collect()->toArray()`'d and written back with `rawState()`, which also calls `clearCachedChildSchemas()`.
- **Where on 5.11.4:** `schemas/src/Components/Concerns/HasState.php:479-491`, `:676-689`.
- **Measure:** the mount time of an edit page with S ∈ {5, 50} Sections over a record with a large JSON/array attribute (e.g. 1 MB of nested array). Expect time to grow with S × data size; create pages are unaffected.

### 3.3-j `flushCachedHierarchy` climbs to the root once per descendant schema
- **Verdict:** **Confirmed** (Low). `Schema::flushCachedHierarchy()` calls `clearCachedComponentsByStatePath()`, which walks up to the root (`HasComponents.php:425-438`), and it recurses down through components and their child schemas.
- **Where on 5.11.4:** `schemas/src/Concerns/HasComponents.php:494-517`, `schemas/src/Components/Concerns/HasChildComponents.php:394-419`.
- **Measure:** profile call counts of `clearCachedComponentsByStatePath` during one render of a form nested 6 Groups deep. Expect about schemas × depth.

### 3.3-k `preg_replace` on every relative key and path lookup
- **Verdict:** **Confirmed** (Low). Absolute keys and paths are cached; relative ones (`isAbsolute: false`) and first computations run `preg_replace` every time. This is a security sanitiser and is cheap.
- **Where on 5.11.4:** `schemas/src/Components/Concerns/HasState.php:750-756`, `schemas/src/Components/Concerns/HasKey.php:29`, `schemas/src/Concerns/HasKey.php:26-37`, `schemas/src/Concerns/HasState.php:699-704`.
- **Measure:** `preg_replace` call count in an Xdebug trace of one render. Expect a few times the number of components; this is not worth its own benchmark.

---

### #13 Relationship Select fill runs `getResults()`, discards the result, then queries again for the label
- **Report:** 2 queries per Select on edit pages, although for BelongsTo the foreign key is already on the record.
- **Verdict:** **Partly; wrong for the common BelongsTo case.**
  - `fillStateFromRelationship()` returns immediately when the state is already filled (`if (filled($this->getState())) return;`). On EditRecord, the form is filled from `$record->attributesToArray()` (`panels/src/Resources/Pages/EditRecord.php:129-138`). So `Select::make('author_id')->relationship('author', 'name')` runs **0** fill queries. With a null FK, Laravel's `BelongsTo::getResults()` also skips the query.
  - Both fill and label also have a `relationLoaded()` fast path (`Select.php:1196-1260`, `:839-857`, `:879-891`), so eager loading in `getEloquentQuery()` brings them to 0.
  - The claim **is true** for `BelongsToMany` and `HasMany` multiple selects. They are not in `attributesToArray()`, so fill runs `getResults()`, keeps only the keys, and render runs a second `whereIn` for labels. It is also true for a BelongsTo Select named after the relation (`Select::make('author')->relationship()`).
  - The real BelongsTo double query is `->searchable()->preload()`. The options pluck already contains the selected label, but `resolveOptionLabel()` always prefers `getOptionLabelUsing` and calls `getSelectedRecord()`, which runs `first()`.
- **Where on 5.11.4:** `forms/src/Components/Select.php:1190-1342` (fill; BelongsToMany `getResults()` at `:1272-1289`, BelongsTo at `:1332-1341`), `:572-624` (label resolution), `:839-877` (`getSelectedRecordUsing`), `:879-930` (labels), `:1568-1590` (the `getSelectedRecord` cache is keyed by state and path), `:1980-2015` (render).
- **Evidence (exact queries while mounting EditRecord, no eager loads):**

  | Configuration | Fill | Render | Total | Minimum needed |
  |---|---|---|---|---|
  | `Select::make('author_id')->relationship('author','name')` (native, default) | 0 | 1 `pluck(name,id)` of the whole `authors` table, ordered by name, no LIMIT | 1 | 1 |
  | `… ->searchable()` | 0 | 1 `where id = ? limit 1` (label) | 1 | 1 (0 if eager-loaded) |
  | `… ->searchable()->preload()` | 0 | 1 `pluck … limit 50` + 1 `first()` | **2** | 1 |
  | `Select::make('author')->relationship()` (name = relation) | 1 `getResults()` | 1 options (native) or 1 label (searchable) | **2** | 1 |
  | `Select::make('tags')->relationship('tags','name')->multiple()` (BelongsToMany) | 1 `getResults()` (pivot join, full models) | 1 `whereIn` labels (+1 options if preloaded) | **2–3** | 1–2 |

  On a save request, validation (`getInValidationRuleValues`, `:1777-1846`) resolves the label or labels again. For a multiple select, `getOptionLabels()` is not cached, so validation and the re-render each run a `whereIn`. Every later Livewire request re-runs the render query (options or label), because nothing persists across requests.
- **Measure:** SQL count with `DB::listen` while mounting `EditRecord` for each row of the table above, and on one Livewire save request. Expected numbers are in the table. Control: add `->with('author', 'tags')` in `getEloquentQuery()` and expect the label and fill queries to drop to 0. Whether Livewire restores eager-loaded relations on later requests is a run-time fact; measure it with a follow-up `->live()` update.

### #14 Select option and label caches are per instance; Repeater clones each Select
- **Report:** N repeater items run N identical options queries and N label queries. CheckboxList shares its cache by reference.
- **Verdict:** **Confirmed.** `cachedRelationshipOptions`, `cachedRelationshipRecords` and `cachedSelectedRecord` are instance properties. The template Select is never rendered, so every clone starts with a null cache. CheckboxList captures `&$cachedRecords` and `&$cachedOptions` in the `options` closure, which clones share. The label queries per item are for different IDs, so they could only be batched, not shared.
- **Where on 5.11.4:** `forms/src/Components/Select.php:99-143` (properties), `:1069-1182` (options cache, skipped with `modifyQueryUsing` or `ignoreRecord`), `forms/src/Components/CheckboxList.php:188-285`.
- **Measure:** SQL count during the initial render of a `Repeater::make('lines')` with 20 items, each holding `Select::make('product_id')->relationship('product','name')`:
  - native: expect 20 identical `select name, id from products order by name`, where 1 is needed.
  - `->searchable()`: expect 20 `first()` label queries, where 1 `whereIn` is possible.
  - `->searchable()->preload()`: expect 40.
  - Control: the same Repeater with `CheckboxList::make('tags')->relationship()`; expect 1 options query.
  - Repeat with a `->live()` update anywhere on the page: the same counts per request.

### #15 `disableOptionsWhenSelectedInSiblingRepeaterItems()` is O(items² × options)
- **Report:** items × options × items work, plus casts on `$state`, on every render.
- **Verdict:** **Confirmed.** For each option, `isOptionDisabled()` runs `evaluate()` on a closure that injects `$state` (`getState()` builds a cast via `app()`; for a relationship Select it also runs `getRelationship()`). It walks `getParentRepeater()` and `collect($repeater->getRawState())->pluck(...)` over all items. The trait also forces `->live()` and `distinct()`, so this runs on every change. Because `hasDynamicDisabledOptions()` is true, `wire:key` also hashes `serialize($options)`. Non-native selects call `isOptionDisabled` in `transformOptionsForJs`.
- **Where on 5.11.4:** `forms/src/Components/Concerns/CanDisableOptionsWhenSelectedInSiblingRepeaterItems.php:12-47`, `forms/src/Components/Concerns/CanDisableOptions.php:56-68`, `forms/src/Components/Select.php:159-166,1955-1966`.
- **Measure:** the time of the Livewire update after changing one Select inside `Repeater` items N ∈ {10, 20, 40}, each with `Select::make('x')->options(range(1, 100))->disableOptionsWhenSelectedInSiblingRepeaterItems()`. Expect time to grow with N² × 100: about 2,000 `evaluate()` calls and 2,000 `StateCast` resolutions at N=20, so count them with `resolving(StateCast::class)`.

### 3.4-a RichEditor checks `exists()` per image on hydrate and on save
- **Verdict:** **Confirmed.**
  - Hydrate: `RichEditorStateCast::set()` → `getFileAttachmentUrl()` → `$storage->exists($file)`, plus `temporaryUrl()` when private.
  - Save: `resolveFileAttachmentIds()` calls `getFileAttachmentUrl()` again per image.
  - No per-path memo, and the check is skipped only when a `getFileAttachmentUrlUsing` callback or a file-attachment provider supplies the URL.
  - The "disk isn't memoised" sub-claim is negligible. `Storage::disk($name)` returns Laravel's cached adapter; only the disk-name `evaluate()` repeats.
- **Where on 5.11.4:** `forms/src/Components/Concerns/HasFileAttachments.php:185-188` (disk), `:227-261` (`exists` at `:242`), `forms/src/Components/RichEditor/StateCasts/RichEditorStateCast.php:95`, `forms/src/Components/RichEditor.php:538-588`.
- **Measure:** the Storage call count (`fileExists` and `temporaryUrl`) when mounting an edit page whose RichEditor HTML has 20 `<img data-id>` attachments on an S3 or MinIO disk with `->fileAttachmentsVisibility('private')`. Expect 20 `exists` and 20 presigns on mount, plus about 20 `exists` again on save.

### 3.4-b RichEditor builds a new TipTap `Editor` and re-parses on every `getState()` or cast
- **Verdict:** **Confirmed.**
  - `get()` (every `getState()`, and therefore every `$get('content')`, every `$state` injection and every dehydrate) builds an `Editor` via `app()`, calls `setContent()` (an HTML or JSON parse), runs 1–2 `descendants()` passes, and serialises with `getHtml()` or `getDocument()`.
  - `set()` (hydrate, `state()`, and `callAfterStateUpdated` when the state is not an array) also renders each custom block's preview (`toPreviewHtml`, Blade) and runs mention-provider lookups.
- **Where on 5.11.4:** `forms/src/Components/RichEditor.php:753-759,804-810,1618-1632`, `forms/src/Components/RichEditor/StateCasts/RichEditorStateCast.php:19-127`, `forms/src/Components/RichEditor/RichContentRenderer.php:558-567`.
- **Measure:** the time and `resolving(\Tiptap\Editor::class)` count during a save of a form with one RichEditor holding a 200 KB document plus 5 TextInputs whose `->visible(fn (Get $get) => filled($get('content')))`. Expect about 5 + (number of walks) Editor constructions, each re-parsing 200 KB. For custom blocks: mount with 20 custom blocks; expect 20 `toPreviewHtml` renders.

### 3.4-c Builder renders a full block picker between every pair of items
- **Verdict:** **Confirmed.** `addBetweenAction` is visible whenever `isAddable()` is true (the default; `Builder.php:312-360`). For each non-last item, `generateBlockPickerHtml()` renders every block as a `<button>` with `generate_icon_html()`, and so does the bottom "add" picker.
- **Where on 5.11.4:** `forms/src/Components/Builder.php:1926-1952` (between items), `:1955-1965` (bottom), `:1446-1660` (picker).
- **Measure:** response HTML bytes and the count of `fi-fo-builder-block-picker` dropdowns and their buttons for a Builder with 30 items and 40 blocks, each with `->icon()`. Expect 30 pickers × 40 = 1,200 buttons. Control: `->addBetweenAction(fn ($a) => $a->hidden())`; expect 40.

### 3.4-d FileUpload: 3 HEAD requests per file, plus one `getUploadedFiles` round trip per instance
- **Verdict:** **Confirmed**, but the three calls are split across two requests:
  - `exists()` per file in `hydrateFiles()`, run from `afterStateHydrated` on mount.
  - `size()` + `mimeType()` (+ `temporaryUrl()` when private) per file in `getUploadedFile()`, run when the JS calls `getUploadedFiles` (a separate Livewire request per FileUpload instance on init).
  - `fetchFileInformation(false)` skips the `exists`, `size` and `mimeType` calls.
- **Where on 5.11.4:** `forms/src/Components/BaseFileUpload.php:145-166` (`exists` `:160`), `:172-208` (`size`/`mimeType` `:184-185`), `:925-960`; `forms/resources/js/components/file-upload.js:635-650`.
- **Measure:** on an S3 or MinIO disk with `FileUpload::make('docs')->multiple()->disk('s3')` holding 10 files: count Storage or HTTP calls on mount (expect 10 HEAD) and in the follow-up `getUploadedFiles` request (expect 20 HEAD, plus 10 presigns if private). Also count the Livewire HTTP requests on page load with 5 FileUpload fields (expect 5 extra; whether Livewire batches them is a run-time fact).

### 3.4-e Repeater/Builder item labels computed twice per item
- **Verdict:** **Confirmed** when `itemLabel()` is set. Labels are computed once in the item header and again in `getReorderingDescriptionHtml()`, which renders unconditionally, even when the repeater is not reorderable. With `$state` injected, each call runs `getStateSnapshot()`, an item dehydrate plus mutate.
- **Where on 5.11.4:** `forms/src/Components/Repeater.php:1309-1325,1674,1812,2298-2320`; `forms/src/Components/Builder.php:1857,1968,2009-2017`.
- **Measure:** the time of rendering a Repeater with 50 items × 10 fields and `->itemLabel(fn (array $state) => $state['name'] ?? null)`, against no `itemLabel`. Expect 100 `getStateSnapshot` calls (profile) where 50 are needed.

### 3.4-f Relationship Repeater re-queries its records on every Livewire request
- **Verdict:** **Confirmed** for the re-query. "Re-hydrates" holds only in the sense that Eloquent models are hydrated again; form state is not re-hydrated. `cachedExistingRecords` lives on the instance, so it is rebuilt on every request in which the Repeater's item schemas are built (any render or walk). The query is skipped only when the parent record has the relation loaded and there is no `modifyQueryUsing`.
- **Where on 5.11.4:** `forms/src/Components/Repeater.php:63,890-914,1252-1307`.
- **Measure:** SQL count on each `->live()` update elsewhere on an edit page with `Repeater::make('lines')->relationship()` holding 50 rows. Expect 1 `select lines.* … where order_id = ?` per request, plus any nested relation queries per item, against 0 if the state alone were enough.

### 3.4-g `getOptions()`/`getOptionLabels()` are uncached and re-run in validation; `modifyQueryUsing` disables caching
- **Verdict:** **Confirmed.**
  - `HasOptions::getOptions()` evaluates the user closure on every call: once in render, and again in validation through `getOptionLabel()` / `getOptionLabels()` on the save request.
  - Relationship options are cached per instance only when neither `modifyQueryUsing` nor `ignoreRecord` is set.
  - `getOptionLabels()` is never cached.
  - CheckboxList: same `modifyQueryUsing` rule.
- **Where on 5.11.4:** `forms/src/Components/Concerns/HasOptions.php:34-60`, `forms/src/Components/Select.php:1069-1182,1777-1846`, `forms/src/Components/CheckboxList.php:197-285`.
- **Measure:** SQL count on a save request for `Select::make('author_id')->relationship('author','name', fn ($q) => $q->where('active', true))` (native). Expect 1 options query in validation (via the label fallback) plus 1 in render, so 2, where the version without `modifyQueryUsing` expects 1. For `->options(fn () => Author::pluck('name','id'))`, expect 2 per save request.

### 3.4-h `isOptionDisabled()` runs one full `evaluate()` per option per render
- **Verdict:** **Partly.** It costs nothing unless `disableOptionWhen()` (or the sibling-repeater helper) is configured: the loop is over an empty array. When configured, it is one `evaluate()` per option per callback per render, plus the same again in `getEnabledOptions()` and validation.
- **Where on 5.11.4:** `forms/src/Components/Concerns/CanDisableOptions.php:56-68`; callers `Select.php:163,1956,1965,1812,1841`.
- **Measure:** the render time of one `Select->options(range(1, 2000))->disableOptionWhen(fn ($value) => $value % 2)` against no callback. Expect 2,000 `ReflectionFunction` constructions (trace).

### 3.4-i Non-searchable relationship Select (the default) loads the whole table
- **Verdict:** **Confirmed.** This is the default design: native `<select>`, not searchable. A LIMIT applies only when `isSearchable() && filled(searchColumns)` or when the user query sets one.
- **Where on 5.11.4:** `forms/src/Components/Select.php:1069-1074,1151-1155`.
- **Measure:** SQL rows returned and response bytes when rendering `Select::make('customer_id')->relationship('customer','name')` against a 50k-row `customers` table. Expect one query returning 50k rows and about 50k `<option>`s, against `->searchable()` (0 rows on render plus 1 label).

### 3.4-j Select casts resolve the relationship on each `getState()`
- **Verdict:** **Confirmed** (Low). `getDefaultStateCasts()` calls `getRelationship()`, which instantiates `$record->author()` with `isRelation()` checks, then `getRelated()->getKeyType()` on every `getState()`. There is no SQL. It also builds a new cast via `app()`.
- **Where on 5.11.4:** `forms/src/Components/Select.php:1747-1771,1527-1560`.
- **Measure:** a micro-benchmark of 1e5 `getState()` calls on a relationship Select against an `->options([...])` Select.

### 3.4-k ModalTableSelect's label cache isn't keyed by state
- **Verdict:** **Confirmed**, but this is a correctness issue more than a performance one. `getSelectedRecord()` returns `cachedSelectedRecord` whatever the current state. After the selection action calls `state($data['selection'])`, a record cached earlier in the same request would be stale. Select has the state-keyed version (`Select.php:1568-1590`).
- **Where on 5.11.4:** `forms/src/Components/ModalTableSelect.php:42,778-789`.
- **Measure:** this is not a performance benchmark; a functional test (label after selection in one request) is the right check. Performance-wise: 1 `first()` per instance per request.

### 3.4-l Collapsed repeater items still render their full schema
- **Verdict:** **Confirmed** (Low). Collapse is client-side only (`x-show="! isCollapsed"`), which is deliberate for instant expand.
- **Where on 5.11.4:** `forms/src/Components/Repeater.php:1697,1777-1779`.
- **Measure:** response bytes and render time for a Repeater of 50 items × 10 fields with `->collapsed()` against without. Expect them to be equal, which confirms the claim.

### 3.4-m Reorder issues one UPDATE per row
- **Verdict:** **Partly.** `saveToRelationship()` does `$record->fill($itemData)->save()` per existing item. Eloquent issues an UPDATE only for dirty rows, so it is one UPDATE per **moved** row, not per row. The same loop also calls `$item->getState(shouldCallHooksBefore: false)` per item, which re-runs `validate()` for that item (see N-3).
- **Where on 5.11.4:** `forms/src/Components/Repeater.php:1038-1120`.
- **Measure:** SQL count on save after moving the last of 100 items to the top, with `->orderColumn('sort')`. Expect about 100 UPDATEs, because every `sort` shifts; it is 0 for an untouched save.

---

### 3.8-inf-a `RepeatableEntry` over a relationship is N+1
- **Verdict:** **Confirmed.** The item schemas get `->record($itemData)` per related model. Child entries such as `TextEntry::make('author.name')` resolve through `getRelationValue()` per item record (`CanGetStateFromRelationships.php:93-138`), and nothing eager-loads them.
- **Where on 5.11.4:** `infolists/src/Components/RepeatableEntry.php:85-112`, `schemas/src/Components/Concerns/CanGetStateFromRelationships.php:93-138`.
- **Measure:** SQL count when rendering a ViewRecord infolist with `RepeatableEntry::make('comments')->schema([TextEntry::make('author.name')])` over 50 comments. Expect 1 + 50 queries. Control: `->with('comments.author')` in the resource query; expect 0 extra.

### 3.8-inf-b Entry state is never memoised
- **Verdict:** **Confirmed.** `Entry::getState()` calls `getConstantState()` every time. That means container constant-state lookup, `getConstantStateFromRecord()`, a relationship reduce, and `getDefaultState()`. It runs for each `$state` injection and each getter that needs state. Worse, `RepeatableEntry::areCachedDefaultChildSchemasFresh()` re-resolves the whole items state on **every** `getChildSchemas()` call (`RepeatableEntry.php:114-128`).
- **Where on 5.11.4:** `infolists/src/Components/Entry.php:93-96`, `schemas/src/Components/Concerns/HasState.php:1008-1035,1066-1140`.
- **Measure:** the render time of an infolist with 100 `TextEntry::make('relation.field')` entries, each with `->color(fn ($state) => …)->icon(fn ($state) => …)->tooltip(fn ($state) => …)`, against without closures. A profile shows `getConstantState` at about 4 × 100 calls. For RepeatableEntry: profile `getConstantState` calls with 50 items; expect one per tree walk.

### 3.8-inf-c ImageEntry `exists()` default; default-image URL computed for every image
- **Verdict:** **Confirmed** for `exists()`: `$shouldCheckFileExistence = true` by default, giving one `exists()` per image (plus `temporaryUrl` when private). The default-image claim is **Partly**: `getDefaultImageUrl()` is evaluated eagerly per item (`$defaultImageUrl ?? $this->getDefaultImageUrl(...)`), but it is only an `evaluate(null)` unless `defaultImageUrl()` is a closure.
- **Where on 5.11.4:** `infolists/src/Components/ImageEntry.php:68,225-259,261-267,569-573`.
- **Measure:** the Storage `fileExists` count when rendering `ImageEntry::make('photos')` with 20 paths on S3. Expect 20 (0 with `->checkFileExistence(false)`). For the default image: render 100 images with `->defaultImageUrl(fn () => …)`; expect 100 evaluations.

---

### 2.1-a (theme 1) Cache parameter descriptors in a static WeakMap
- See **#1**. **Confirmed:** no cache exists. A `WeakMap<Closure, …>` would work, because the same closure objects are evaluated repeatedly (they are stored on components). Clones share closure objects, but `bindTo` in `afterCloned` creates new ones.

### 2.1-b (theme 1) Build `Get`/`Set` with `new` instead of `app()`
- See **3.3-c**. **Confirmed:** `HasState.php:854-862`. Measure with the `resolving(Get::class)` count per request on a form with 100 `fn (Get $get)` closures.

### 2.1-c (theme 1) Memoise state casts
- See **3.3-c**. **Confirmed:** `HasState.php:94-103` builds the list fresh on every call, and the casts are constructed in `getDefaultStateCasts()` via `app()`.

### 2.1-d (theme 1) Default date formats are closures
- **Verdict:** **Confirmed** (tables and infolists). `dateTime()`, `time()` and `isoDate()` wrap the default in `fn (TextColumn $column) => $column->getTable()->getDefault…Format()`. Per cell that is the `formatStateUsing` closure plus the format closure, two `evaluate()`s with reflection. Plain `date()` with no format passes `null`, so it pays nothing extra. The infolist version is identical.
- **Where on 5.11.4:** `tables/src/Columns/Concerns/CanFormatState.php:63-110,297-305`, `infolists/src/Components/Concerns/CanFormatState.php:76,86,149,157`.
- **Measure:** the render time of a 50-row table with 10 `TextColumn::make(…)->dateTime()` columns against `->dateTime('Y-m-d H:i')`. Expect 500 extra `ReflectionFunction` constructions (trace) and a measurable difference. Check first that the TextColumn fast path does not bypass `formatStateUsing` (tables area).

### 2.2-b (theme 2) RichEditor `exists()` per embedded image; disk not memoised
- See **3.4-a**. **Confirmed** for `exists()`. The "disk not memoised" part is **Wrong/negligible**: Laravel's `FilesystemManager` caches disks by name.

### 2.2-c (theme 2) FileUpload `exists` + `size` + `mimeType` = 3 HEAD per file
- See **3.4-d**. **Confirmed**, split across the mount request (1 per file) and the `getUploadedFiles` request (2 per file).

---

## Problems the report missed

### N-1 Every Field and Entry evaluates an uncached `after_label` closure on every tree walk; with a hint or helper text it builds a new Schema and Text/Icon each time
- `setUpHint()` registers `afterLabel(function (Field | Placeholder $component): array {...})` on every Field (`forms/src/Components/Concerns/HasHint.php:32-…`), and Entry does the same (`infolists/src/Components/Concerns/HasHint.php:33`). `helperText()` registers a `belowContent` closure (`forms/src/Components/Concerns/HasHelperText.php:17`, `infolists/src/Components/Concerns/HasHelperText.php:14`).
- Non-default child-schema keys holding a Closure are never cached (`HasChildComponents.php:132-134`). `getChildSchemas()` calls `getChildSchema($key)` for every key (`:215-232`), and every tree walk calls `getChildSchemas()`: validation ×3, dehydrate, hydrate, `callAfterStateUpdated`, `getFlatComponents`, `getComponentByStatePath`. So each walk evaluates the closure, which means reflection.
- When `->hint()`, `->hintIcon()` or `->helperText()` is set, each walk also builds `Text::make()` / `Icon::make()` plus `Schema::make()` via `app()`.
- **Measure:** `Schema` and `resolving(\Filament\Schemas\Components\Text::class)` counts during one save (`getState()`) of a form with 100 `TextInput::make("f$i")->hint('x')->helperText('y')`. Expect several hundred Schema and Text constructions, against about 0 without hint or helper text.

### N-2 Render-time `getChildSchema()` rebuilds wipe the root `$get` lookup cache
- Nested layout (for example a Section inside a Section) is re-parented to the new uncached schema (#11). `container()` then calls `flushCachedHierarchy()`, then `flushCachedChildSchemaHierarchies()`, then `Schema::flushCachedHierarchy()`, then `clearCachedComponentsByStatePath()`, which climbs to and clears the **root**'s `cachedComponentsByStatePath` (`HasComponents.php:425-438,494-517`). `$get` lookups after that point search again.
- This was traced statically; confirm with a profile.
- **Measure:** the render time of 20 nested `Section > Section > TextInput->visible(fn (Get $get) => …)` against the same fields in a flat form. Count `getComponentByStatePath` misses.

### N-3 Relationship Repeater save re-validates every item
- `saveToRelationship()` calls `$item->getState(shouldCallHooksBefore: false)` per item. `Schema::getState()` always starts with `$this->validate()`, which calls `livewire->validate($rules, …)` (`schemas/src/Concerns/HasState.php:587-590`). So a save with N relationship items runs the root validation plus N item validations.
- **Measure:** the save time and the count of `Validator::make` calls (`Validator::resolver` or the `validating` event) for a relationship Repeater with 100 items × 5 rules. Expect 1 + 100 validator runs.

### N-4 Searchable and preloaded relationship Select ignores the preloaded options when resolving the label
- See #13: `resolveOptionLabel()` uses `getOptionLabelUsing` (and so runs `first()`) even when `$options` is passed and contains the key (`Select.php:580-624`). This is the actual "2 queries per Select" case for BelongsTo.

### N-5 `updatedInteractsWithSchemas` runs the #10 walk once per updated property per cached schema
- `InteractsWithSchemas.php:315-324`. Deferred (non-live) field changes are all sent on the next request, so one `->live()` blur after editing 30 fields runs 30 walks, multiplied by the number of cached schemas (form, infolists, filter forms, action forms).
- **Measure:** see #10, third case.

### N-6 `Builder::getBlocks()` builds a new Schema on each call
- `Builder.php:1013-1019` calls `getChildSchema()` (uncached, #11). It is called from `getBlockPickerBlocks()` in render and from `getDefaultChildSchemas()` (`:1042`), and on each `getBlock()` / `hasBlock()`.
- **Measure:** `Schema` resolutions during a Builder render; expect at least 2 plus 1 per item, from the per-block `getChildSchema()` at `:1050`.

## Assumptions and limits

- Laravel internals (`Container::build` using `ReflectionClass` with no cache, resolving callbacks matching by `instanceof`, `BelongsTo::getResults()` skipping a null FK, `Storage::disk()` caching) are not in this checkout. They are stated from knowledge of Laravel 11/12; check them in the benchmark app's `vendor/`.
- Livewire facts (whether async `$callSchemaComponentMethod` calls are batched, whether eager-loaded relations survive model re-hydration across requests, and the per-property `updated` hook for deferred fields) are Livewire 3/4 behaviour. They are not verified here and should be measured.
- The expected counts above are static estimates. The configurations are chosen so that each estimate is easy to falsify.
