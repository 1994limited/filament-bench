# Review: Panels, Support, Widgets, Notifications findings against Filament v5.11.4

Checked against a `filamentphp/filament` checkout at tag `v5.11.4` (HEAD `6427cc1`). Paths are relative to `packages/`.

General notes:
- Almost every cited file is **byte-identical** between 4.x@6035bbc and v5.11.4. Only these files in this area differ: `panels/src/Panel/Concerns/HasComponents.php` (Livewire Finder API), `HasAuthorization.php` (enum abilities, comment), `notifications/src/Http/Middleware/PublishNotifications.php` (now also decodes non-`JsonResponse` Livewire bodies), the CSP nonce in `base.blade.php`/`sidebar.blade.php`, and the `Js` assets. So line numbers barely moved and nothing in this area was "fixed in 5.x".
- Filament 5 runs on **Livewire 4**. I checked Livewire behaviour against `livewire/livewire` at `e03c29c` (v4.4.8+, cloned to /tmp/lw). Two Livewire 4 facts change how some findings should be measured:
  1. Non-isolated component updates queued in the same 5 ms buffer go out in **one HTTP request** (`js/request/index.js` `sendMessages`). `wire:poll` timers with the same interval share one `setInterval` clock (`js/directives/wire-poll.js` `syncronizedInterval`), so all 5 s pollers on a page fire together and are bundled. In background tabs, ~95% of ticks are skipped (`throttleWhile(theTabIsInTheBackground)`, `Math.random() < .95`).
  2. `lazy => true` defaults to `isolate = true` (`src/Features/SupportLazyLoading/SupportLazyLoading.php:52-65`). Each lazy component sends **its own** load request; only `lazy.bundle` or `#[Lazy(bundle: true)]` bundles them.
- On PHP ≥ 8.4, Livewire's `ModelSynth::hydrate` returns a lazy proxy. The query runs on first use (`SupportModels/ModelSynth.php:85-99`, `IsLazy.php`).
- Benchmark tip: count queries with `DB::listen`; count policy checks with `Gate::after(fn () => $n++)`, which runs after every `Gate::inspect`; count navigation badge work with a static counter inside each test resource's `getNavigationBadge()`.

---

## Top-20 items

### #3 Widgets poll every 5 s by default
- Report: Stats/Chart widgets poll every 5 s by default, re-running their queries 12×/min per tab through the full middleware stack; neither calls `skipRender()`.
- Verdict: **Partly.** The default is confirmed: `'5s'` for `StatsOverviewWidget` and every `ChartWidget` subclass, with no `skipRender()`. "Through the full middleware stack" per widget is **wrong on Livewire 4**: all 5 s pollers on a page share one clock, and their updates are bundled into one `POST /livewire/update` per tick (unless a widget is `#[Isolate]`). Background tabs poll ~5% as often. Neither default dashboard widget (Account, FilamentInfo) polls, so this only applies to widgets the app adds. `TableWidget` does not use `CanPoll`.
- Where on 5.11.4: `widgets/src/Concerns/CanPoll.php:7`; used at `widgets/src/StatsOverviewWidget.php:16` and `widgets/src/ChartWidget.php:18`; rendered at `widgets/resources/views/stats-overview-widget.blade.php:17` (`wire:poll.5s`, which calls `$refresh`) and `widgets/resources/views/chart-widget.blade.php:88-89` (`wire:poll.5s="updateChartData"`).
- Evidence: `protected ?string $pollingInterval = '5s';` and Livewire's `let clock = { timer: setInterval(() => clock.callbacks.forEach(i => i()), ms) ...}` in the shared `clocks[ms]` map.
- Measure: dashboard with 2 `StatsOverviewWidget` (3 stats, each `Model::count()`) and 2 `LineChartWidget` (`getData()` doing one `SUM … GROUP BY`), with default `$pollingInterval`.
  - (a) The `wire:poll.5s` attribute is present in the dashboard HTML after the widgets lazy-load. Grep the lazy-load responses, because the initial HTML only holds placeholders.
  - (b) `/livewire/update` requests per minute in a visible headless tab, counted after the lazy loads. Expect ~12, bundled. ~48 would mean the updates are not bundled.
  - (c) Calls to `getStats()`/`getData()` per minute: expect ~48. Queries per minute: expect 12 × (6 + 2) = 96.
  - (d) The same in a hidden tab (`document.visibilityState='hidden'` through CDP `Emulation.setFocusEmulationEnabled` or a minimised window). Expect ~0.6 requests per minute.
  - (e) Response bytes per poll. The Chart HTML re-embeds `@js($this->getCachedData())` on every poll (see 3.8-w-a).

### #5 `getRedirectUrl()` builds a whole new NavigationManager
- Report: it re-runs every resource `canAccess()` and every badge COUNT; with tenant domains it runs once per tenant in the switcher and once each for the sidebar and topbar `getHomeUrl()`.
- Verdict: **Confirmed, but conditional.** Each call builds `new NavigationManager` and mounts it from scratch, without using the request-scoped singleton. It runs every page's and resource's `registerNavigationItems()`, so every `canAccess()` and every eager badge, under the tenant passed in (switched with `setTenant(..., isQuiet: true)`). The badge results are thrown away; only the first item's URL is used. When it runs during a request:
  - **Panels with `tenantDomain()`:** `Panel::getUrl($tenant)` calls `getRedirectUrl($tenant)` (`HasRoutes.php:178-179`). That happens for the sidebar logo `getHomeUrl()` (`livewire/sidebar.blade.php:102`), the topbar logo `getHomeUrl()` (`livewire/topbar.blade.php:124`, unconditional), and every switchable tenant in the tenant menu (`components/tenant-menu.blade.php:138`). `getHomeUrl()` goes through `homeUrl ?? getUrl()`, and `getUrl()` with no tenant resolves `getUserDefaultTenant()`. So each full page costs **2 + (switchable tenants)** extra navigation mounts, unless `->homeUrl()` is set.
  - **Panels without a tenant domain:** `getUrl()` returns `route('…home')` or `url(path)` and never calls `getRedirectUrl()`. It runs only in `RedirectToHomeController` (`GET /admin` when no page owns `/`, and the tenancy fallback route), once, before a redirect. With the default Dashboard at `/` on Laravel 13 the `home` route is not registered at all (`routes/web.php:186-197`).
  - A Livewire update of the page component never calls it. A Livewire update of Sidebar/Topbar does, because they re-render the logo and the tenant menu.
- Where on 5.11.4: `panels/src/Panel/Concerns/HasRoutes.php:200-249` (`new NavigationManager` at :223); `getUrl` at :170-198.
- Evidence: `$this->navigationManager = new NavigationManager; $navigation = $this->navigationManager->get();` against the request-scoped singleton `$this->app->scoped(NavigationManager::class, …)` at `FilamentServiceProvider.php:82`.
- Measure: panel `->tenant(Team::class, slugAttribute: 'slug')->tenantDomain('{tenant:slug}.app.test')`, user in 5 teams, 10 resources whose `getNavigationBadge()` runs `Model::count()` and bumps a static counter.
  - Count `getNavigationBadge()` calls and COUNT queries on one dashboard `GET`. Expect 10 × (1 sidebar mount + 2 logo `getHomeUrl` + 4 switchable tenants) = **70**. The control without `tenantDomain` (path tenancy) should give **10**.
  - Count `viewAny` checks with `Gate::after`: same multipliers.
  - Count `getRedirectUrl` calls, wrapping it in a Panel subclass via `Filament::registerPanel`. Expect 6.
  - Also run a Livewire update on the Sidebar (call a user-menu action): expect ~70 again.

### #6 Navigation badges are evaluated eagerly
- Report: one COUNT per resource on every page load, again on each sidebar/topbar refresh, and in record sub-navigation on every `live()` update.
- Verdict: **Confirmed, with nuance on the second part.** `getNavigationItems()` calls `static::getNavigationBadge()` immediately; `NavigationItem::badge()` would accept a Closure, but none is passed. Navigation is mounted **once per request**: the scoped singleton sets `isNavigationMounted`, so the sidebar's and topbar's `getNavigation()` share one mount. That gives one COUNT per accessible resource per full page. A Livewire update of the page component does **not** re-run the sidebar; Sidebar/Topbar re-render only on their own updates (user-menu or tenant-menu actions) or on `refresh-sidebar`/`refresh-topbar` events, and core never dispatches those. Record sub-navigation is rebuilt on every render of a record page: `$cachedSubNavigation` is a protected property, so it is not persisted across Livewire requests. That covers every `live()` update and every action call. One trigger the report missed: with `->navigationHierarchyInBreadcrumbs()` (opt-in, default false), `getBreadcrumbs()` calls `$panel->getNavigation()` on **every** render, so every Livewire update of any panel page mounts the full navigation, including all badges and `canAccess()`.
- Where on 5.11.4: `panels/src/Resources/Resource/Concerns/HasNavigation.php:77`, `panels/src/Pages/Page.php:167`, `panels/src/Resources/Pages/Page.php:152`, `panels/src/Pages/Concerns/HasSubNavigation.php:21,56-133`, `panels/resources/views/components/page/index.blade.php:12`; breadcrumbs at `panels/src/Pages/Page.php:203-215,291-300`.
- Evidence: `->badge(static::getNavigationBadge(), color: static::getNavigationBadgeColor())`; `protected array $cachedSubNavigation;` (protected, not dehydrated).
- Measure:
  - Badge COUNT queries on one `GET` of a list page with 10 resources that each define `getNavigationBadge()` as a COUNT. Expect **10**.
  - The same page with `->topNavigation()` (sidebar and topbar both call `getNavigation()`): still expect 10. 20 would mean the mount is not shared.
  - An Edit page whose resource defines `getRecordSubNavigation()` returning 4 record pages, each with a COUNT badge, plus a `TextInput::make('x')->live()`. Expect 4 COUNT queries and 4 `canAccess` checks per keystroke update.
  - The same with `->navigationHierarchyInBreadcrumbs()`. Expect 10 extra COUNTs per update of any page.

### #18 Echo + Pusher (92 KB) registered as a core asset on every panel page
- Report: `echo.js` is downloaded and parsed even when broadcasting is off.
- Verdict: **Confirmed.** `echo.js` is 91,939 B (24,236 B gzip) and is emitted in every panel layout by `@filamentScripts(withCore: true)`, without `defer` or `async`, at the end of `<body>`. It is only used by `window.EchoFactory`, and that is instantiated only when `hasBroadcasting()` (default **true**) **and** `config('filament.broadcasting.echo')` is set. The config ships commented out, so by default Echo is never created but the file is always loaded.
- Where on 5.11.4: `panels/src/FilamentServiceProvider.php:143`; `panels/resources/views/components/layout/base.blade.php:153-164`; `panels/resources/js/echo.js`; `support/config/filament.php:17-32`; `support/src/Assets/Js.php:14` (`isDeferred = false`).
- Evidence: `Js::make('echo', __DIR__ . '/../dist/echo.js')->core(),` and `@if (filament()->hasBroadcasting() && config('filament.broadcasting.echo'))`.
- Measure: in a default panel with the `filament.broadcasting.echo` config absent, check (a) whether `<script src=".../js/filament/filament/echo.js?v=…">` is in the HTML (expect yes), (b) its transfer size (expect ~24 KB gzip, 92 KB raw), (c) its parse and evaluate time from a Chrome trace (`EvaluateScript` for echo.js), and (d) that `window.Echo` is undefined. The control is a patched provider without the asset: same page and same `window.Echo`.

---

## 3.5 Panels

### 3.5-a Tenant list loaded 3–4× per page, never memoised; switcher unbounded
- Report: `getUserDefaultTenant()` loads all tenants to take the first; the tenant list is loaded 3–4× per page; the switcher renders every tenant with no limit.
- Verdict: **Partly.** `getUserTenants()` is called 2–4× per page: Sidebar and Topbar each have their own `cachedSwitchableTenants`, and each logo `getHomeUrl()` calls `getUserDefaultTenant()`, which calls `Arr::first(getUserTenants())` unless the user implements `HasDefaultTenant`. With the **documented** `getTenants()` (`return $this->teams;`), Eloquent caches the relation on the same auth user instance, so it is **one query**, with the models hydrated once. "Never memoised" only holds for custom implementations that query (`$this->teams()->get()`). The unbounded switcher is confirmed: every tenant is rendered, and each one gets `getTenantAvatarUrl` and `getUrl($tenant)`. With a tenant domain, each `getUrl($tenant)` is a full navigation mount (#5).
- Where on 5.11.4: `panels/src/FilamentManager.php:595-609,648-657`; `panels/src/Livewire/Concerns/HasTenantMenu.php:113-130`; `panels/resources/views/components/tenant-menu.blade.php:21,134-139`.
- Evidence: `$tenant = Arr::first($this->getUserTenants($user));`; `@foreach ($tenants as $tenant) … $tenantUrl = filament()->getUrl($tenant);`.
- Measure: user in 200 teams, path-based tenancy, docs `getTenants()`. On one dashboard `GET`, count (a) `getTenants()` invocations (static counter; expect 2–4), (b) tenant queries (expect 1), and (c) `.fi-tenant-menu-item` elements in the HTML (expect 199) and their bytes. Repeat with `getTenants()` returning `$this->teams()->get()`: expect 2–4 queries.

### 3.5-b ListRecords per-row `recordAction`/`recordUrl` clone the root ActionGroup and run several policy checks per row
- Report: per row, the whole root ActionGroup is cloned and 3–6 policy checks run; the same happens in relation tables.
- Verdict: **Confirmed.** Each closure, for `view` and then `edit`, calls `getRootGroup()->getClone()`, which deep-clones every action in the group, then `record($record)` and `isHidden()` (policy). `recordUrl` repeats the same loop and then falls back to `canView`/`canEdit`. The table calls both closures for every row.
- Where on 5.11.4: `panels/src/Resources/Pages/ListRecords.php:140-226`; `panels/src/Resources/Concerns/InteractsWithRelationshipTable.php:128-140,190-202`; `actions/src/ActionGroup.php:734-748`.
- Evidence: `$action = $actionGroup->getClone()->getFlatActions()[$action->getName()];`
- Measure: list page with 50 rows, record actions `ActionGroup::make([ViewAction::make(), EditAction::make(), DeleteAction::make()])`, and a policy whose `view`/`update`/`delete` return true. Count `Gate::after` checks per page request, divided by 50. Expect ≥3 per row from the recordUrl/recordAction closures, plus the row actions' own checks. Count `ActionGroup::getClone` calls (expect ~100). Measure render time against a control that sets `->recordUrl(fn ($record) => …)` and `->recordAction(null)`.

### 3.5-c Without `filament:optimize`, discovery runs on every request in the app
- Report: `allFiles` + `class_exists` + reflection run on every request, including API and front-end routes, because panels are built eagerly.
- Verdict: **Confirmed.** `FilamentServiceProvider::packageBooted()` resolves `PanelRegistry`, which runs every PanelProvider's `panel()`. Every `discoverResources/Pages/Widgets/Clusters/LivewireComponents` call walks the directory with `allFiles` and autoloads each class unless the component cache file exists. The cache is never used in console (`! app()->runningInConsole()`). `filament:optimize` is also hooked into `php artisan optimize` (`support/src/SupportServiceProvider.php:489-491`), so apps that run `optimize` on deploy are already covered.
- Where on 5.11.4: `panels/src/Panel/Concerns/HasComponents.php:501-556,723-725`; `panels/src/FilamentServiceProvider.php:161`.
- Evidence: `foreach ($filesystem->allFiles($directory) as $file) { … if (! class_exists($class)) … (new ReflectionClass($class))->isAbstract()`.
- Measure: a `Route::get('/api/ping', fn () => 'ok')` outside the panel, in an app with 40 resources (×4 pages) + 20 widgets discovered. Compare (a) total request time and (b) `opcache`/autoload count (`count(get_declared_classes())` at response) with and without `php artisan filament:optimize` (`bootstrap/cache/filament/panels/*.php` present). Expect hundreds of extra declared classes and measurable ms without the cache.

### 3.5-d `IdentifyTenant` runs on every Livewire request (theme 3)
- Report: route-binding query + `canAccessTenant` on every Livewire request, i.e. 2 queries.
- Verdict: **Confirmed** (2 queries with the documented `canAccessTenant`). `IdentifyTenant` is registered as Livewire persistent middleware, so it re-runs on every `/livewire/update` whose original route had a `{tenant}` parameter. It calls `resolveRouteBinding($key, $slugAttribute)` (1 query), then `canAccessTenant()`. The documented `return $this->teams()->whereKey($tenant)->exists();` adds another query. Nothing is cached across requests.
- Where on 5.11.4: `panels/src/Http/Middleware/IdentifyTenant.php:21-46`; persistent registration at `panels/src/FilamentServiceProvider.php:147-157`; `panels/src/Panel/Concerns/HasTenancy.php:272-290`; it is the first item in `getTenantMiddleware()` at `panels/src/Panel/Concerns/HasMiddleware.php:116-122`.
- Evidence: `$tenant = $panel->getTenant($request->route()->parameter('tenant')); if (! $user->canAccessTenant($tenant)) abort(404);`
- Measure: path-tenancy panel with the docs User model. Count queries on `teams`/`team_user` during one `/livewire/update` (for example a table sort click) that are attributable to the middleware: expect 2. Get the attribution by comparing with a run where `IdentifyTenant` is swapped for a version that uses a cached tenant, or by logging a backtrace in `DB::listen`. Also time the middleware on its own.

### 3.5-e Tenancy scope uses `whereHas` for many-to-many ownership (theme 4)
- Report: the tenancy global scope uses `whereHas` for many-to-many on every model query.
- Verdict: **Confirmed.** `BelongsTo` uses `whereBelongsTo` and `MorphTo` uses `whereMorphedTo`. Everything else, including `BelongsToMany` and `HasMany`, falls to `whereHas(rel, whereKey(tenant))`. That is a correlated `EXISTS (select * from teams inner join team_post … where posts.id = team_post.post_id and teams.id = ?)`, joining the tenants table when the pivot alone would do. The global scope is applied to every query on the model while the panel is current, including counts, badges and relation managers.
- Where on 5.11.4: `panels/src/Resources/Resource/Concerns/BelongsToTenant.php:41-66` (global scope at :128-156).
- Evidence: `default => $query->whereHas($tenantOwnershipRelationshipName, fn (Builder $query) => $query->whereKey($tenant->getKey())),`
- Measure: `Post` belongsToMany `Team`, 1M posts and 1k teams on MySQL 8 and Postgres. Record the SQL and EXPLAIN of the index page's `COUNT(*)` and paginated select. Compare the timing with a hand-written `whereIn('posts.id', DB::table('team_post')->select('post_id')->where('team_id', ?))`.

### 3.5-f Sub-navigation rebuilt (policies + badges) on every Livewire update
- Report: `HasSubNavigation.php:56` rebuilds on every Livewire update.
- Verdict: **Confirmed.** It is memoised only within a request, in a protected property, and recomputed on every render of a page that has cluster or record sub-navigation. Each rebuild runs `shouldRegisterNavigation`, `canAccess` (policy) and eager `getNavigationBadge()` for every sub-page (`HasSubNavigation.php:170-205`), plus `isVisible`.
- Where on 5.11.4: `panels/src/Pages/Concerns/HasSubNavigation.php:21,56-133,170-205`; `panels/src/Resources/Pages/Concerns/InteractsWithRecord.php:126`; `panels/resources/views/components/page/index.blade.php:12`.
- Evidence: `if (isset($this->cachedSubNavigation)) return $this->cachedSubNavigation;` on a `protected array` property.
- Measure: see #6. Count policy checks and badge queries per `/livewire/update` on an Edit page with 4 record sub-pages; expect 4 + 4 per update.

### 3.5-g Record fetched twice per request on record pages
- Report: ModelSynth fetches the record, then `resolveRecordFromLivewire` fetches it again with the write PDO.
- Verdict: **Confirmed** (2 fetches per Livewire update). Livewire 4's ModelSynth returns a lazy proxy on PHP ≥ 8.4. Filament immediately reads `$model->getAttribute($routeKeyName)` / `getRouteKey()`, which initialises the proxy (query 1, `useWritePdo()->firstOrFail()`). It then calls `resolveRecord()` → `resolveRecordRouteBinding(…, useWritePdo)` (query 2). The `hasResolvedRecordForRequest` guard stops the `call` hook from adding a third query. The lazy proxy would allow Filament to read the key from the snapshot meta without loading, but it does not.
- Where on 5.11.4: `panels/src/Resources/Pages/Page.php:51-107`; `panels/src/Resources/Pages/Concerns/InteractsWithRecord.php:15-28`; `panels/src/Livewire/ScopedModelPropertiesComponentHook.php:16-26`; Livewire `src/Features/SupportModels/ModelSynth.php:85-99`.
- Evidence: `$routeKey = filled($routeKeyName = …) ? $model->getAttribute($routeKeyName) : $model->getRouteKey(); … $record = $this->resolveRecord($routeKey);`
- Measure: Edit page for `Post`. Count `select * from posts where … id = ? limit 1` queries in one `/livewire/update` (a `live()` field change). Expect 2 on PHP 8.4. Run on PHP 8.3 (no lazy proxy) and on 8.4: same count expected. Also count `mountParentRecord` queries for a nested resource: 1 more.

### 3.5-h Global search cost
- Report: one sequential query per resource, 50 results per resource, `canView`/`canEdit` per result, `%term%` per word × attribute, no minimum length.
- Verdict: **Confirmed.** `DefaultGlobalSearchProvider` loops over resources sequentially and runs `canGloballySearch()` (`canAccess` policy) for each. `$globalSearchResultsLimit = 50`. `getGlobalSearchResultUrl()` calls `canView` and, if needed, `canEdit` per record. Patterns use leading and trailing wildcards. The only guard is `blank($search)`, so 1 character triggers a search after the debounce.
- Where on 5.11.4: `panels/src/GlobalSearch/Providers/DefaultGlobalSearchProvider.php:10-35`; `panels/src/Resources/Resource/Concerns/HasGlobalSearch.php:26,36-50,87-120,125-160,220-250`; `panels/src/Livewire/GlobalSearch.php:16-24`.
- Evidence: `protected static int $globalSearchResultsLimit = 50;`; `if (blank($search)) { return null; }`.
- Measure: 10 searchable resources, each with 2 searchable attributes and 100k rows. Type "a" and count, for one `/livewire/update`: (a) search SELECTs (expect 10), (b) `Gate::after` checks (expect 10 `viewAny` + up to 500 `view` + `update`), (c) rows hydrated (up to 500), (d) server time. Repeat with "ab cd" (split terms) and confirm OR groups per word × attribute in the SQL.

### 3.5-i `canAccess()`/`canViewAny()` not memoised across the request
- Report: the same policy runs in navigation, sub-navigation, search and parent checks.
- Verdict: **Confirmed.** `canAccess()` → `canViewAny()` → `get_authorization_response()` → `Gate::getPolicyFor()` + `Gate::forUser($user)->inspect()` on every call, with no cache. Laravel's Gate does not cache results either.
- Where on 5.11.4: `panels/src/Resources/Resource/Concerns/HasAuthorization.php:34-46`; `panels/src/helpers.php:31-80`.
- Evidence: `return get_authorization_response($action, $record ?? static::getModel(), static::shouldCheckPolicyExistence());`
- Measure: `Gate::after` counter keyed by `[$ability, $model]`. On one list-page `GET` with 10 resources, record the count of `viewAny` per resource. Expect ≥2 for the current resource (navigation + page `canAccess`) and ≥1 for every other resource, more with global search, breadcrumbs or #5.

### 3.5-j `NavigationManager::get()` clones and serialize-regroups on every call
- Report: `get()` clones items and regroups with `serialize()` on every call.
- Verdict: **Confirmed (Low).** The result of `get()` is not memoised: each call clones every item, filters `isVisible()`, and groups by `serialize($group)` / `unserialize`. Callers per full page: the sidebar, the topbar when top navigation is on, and breadcrumbs when the hierarchy option is on.
- Where on 5.11.4: `panels/src/Navigation/NavigationManager.php:48-185`.
- Evidence: `->map(fn (NavigationItem $item): NavigationItem => clone $item) … ->groupBy(fn … serialize($group))`.
- Measure: number of `NavigationManager::get()` calls per request: expect 1 with the sidebar layout and 2 with `->topNavigation()`. Microbenchmark `get()` with 50 items in 8 groups (µs per call).

### 3.5-k Collapsible sidebar groups render badges twice
- Report: badges are rendered twice in collapsible groups.
- Verdict: **Confirmed, HTML only (Low).** When the sidebar is collapsible on desktop and a group has a label and an icon, the group renders a dropdown copy of its items (`getBadge()` again) plus the normal list. The badge value is computed eagerly at mount, so `getBadge()` only re-evaluates a string: no extra query today. It would double the queries if badges became lazy closures without memoisation, which matters for the fix proposed in #6.
- Where on 5.11.4: `panels/resources/views/components/sidebar/group.blade.php:19,78-164,186-215`; `panels/src/Navigation/NavigationItem.php:189-192`.
- Evidence: `$hasDropdown = filled($label) && filled($icon) && $sidebarCollapsible;`
- Measure: `->sidebarCollapsibleOnDesktop()`, a group with an icon and 5 badged items. Count `.fi-badge` occurrences per item in the HTML (expect 2) and the sidebar HTML bytes against a non-collapsible sidebar. Expect no change in badge queries.

### 3.5-l `HasAuthorization` creates a `ReflectionMethod` per `canView`/`canEdit`/`canCreate`
- Report: a new `ReflectionMethod` per call (micro).
- Verdict: **Confirmed (Low).** This only happens when the policy allowed the action; denied responses return early.
- Where on 5.11.4: `panels/src/Resources/Resource/Concerns/HasAuthorization.php:65-86` (reflection at :79).
- Evidence: `if ((new ReflectionMethod(static::class, $method))->getDeclaringClass()->getName() === self::class) {`
- Measure: microbenchmark 10k `Resource::canEdit($record)` calls with an allowing policy (ns per call), with and without a static `[class][method] => bool` cache.

---

## 3.6 Support

### 3.6-a Each `filamentDropdown` instance gets its own global listeners and observers
- Report: per instance, `resize` + `livewire:navigate` listeners, 2 subtree MutationObservers and 1 ResizeObserver; every morph fires all of them.
- Verdict: **Confirmed (small correction).** Each instance runs in `init()`: one `window` `resize` listener (rAF-throttled per instance), one `document` `livewire:navigate` listener, **one** MutationObserver observing **two** targets (the panel subtree and the trigger container subtree), one ResizeObserver, plus 5 element listeners. Setup is eager in `init()`, not deferred to first open. Since 4.x, some of the cost has been reduced: the visibility check is cached (`shouldCheckTriggerVisibility`) and `setAttributeIfChanged` avoids observer loops. Every morph that touches a row's trigger or panel still runs that row's callback (`syncAria` + `setUpMenu` querySelectorAll + `recoverMenuFocus`).
- Where on 5.11.4: `support/resources/js/components/dropdown.js:66-200` (listeners :78-79, observers :144-200); `support/resources/views/components/dropdown/index.blade.php:32`.
- Evidence: `document.addEventListener('livewire:navigate', this.navigateListener); window.addEventListener('resize', this.resizeListener)`; `this.observer.observe(panel, …); this.observer.observe(triggerContainer, {… subtree: true})`.
- Measure: a 50-row table whose row actions are an `ActionGroup`. In a CDP session, count `getEventListeners(window).resize.length` (expect ~50 + n) and the MutationObserver / ResizeObserver instances (wrap the constructors before load). Time a sort-click morph with Performance profiling (scripting ms in MutationObserver callbacks) and time a `window.resize` (50 rAF callbacks).

### 3.6-b `getAlpineComponentSrc()` is not memoised (theme 5)
- Report: `Arr::flatten`, `asset()` and `InstalledVersions` (plus sha256 on dev versions) run per call, per editable cell.
- Verdict: **Confirmed (Low impact).** Each call runs `Arr::only` + `Arr::flatten` over the registered components, a linear scan, `asset()`, and `getVersion()` → `InstalledVersions::getVersion` (+ `hash('sha256')` when the version is `dev-*`). It is called once per cell for `ToggleColumn`, `TextInputColumn`, `SelectColumn` and `CheckboxColumn`, and once per field/widget elsewhere (27 call sites).
- Where on 5.11.4: `support/src/Assets/AssetManager.php:112-126,345-352`; `support/src/Assets/AlpineComponent.php` `getSrc()`; `support/src/Assets/Asset.php:68-106`; `tables/src/Columns/ToggleColumn.php:54`.
- Evidence: `'x-load-src' => FilamentAsset::getAlpineComponentSrc('columns/toggle', 'filament/tables'),` inside the per-cell `toEmbeddedHtml()`.
- Measure: a 50-row table with 2 `ToggleColumn`s. Count `getAlpineComponentSrc` calls per render (expect 100) and the total µs. Microbenchmark one call with a dev (`dev-5.x`) vs a tagged version to see the sha256 cost.

### 3.6-c Loading-spinner SVG (~600 B) inlined in every button, badge and dropdown item with `wire:click` (theme 6)
- Report: ~600 B each; 50 rows × 4 actions ≈ 120 KB per response.
- Verdict: **Confirmed. The size is underestimated; the count depends on the actions.** The SVG body is ~680 B after heredoc dedent. Its attributes add ~150–250 B (`class`, `aria-hidden`, `wire:loading.delay.default`, and a repeated `wire:target="mountAction(…recordKey…)"`), so roughly **0.85–0.95 KB per instance**. It is emitted only when the control has `wire:click`/`wire:target` (or is a submit button for a form). URL actions such as Edit/View with a resource page and ActionGroup triggers don't get one. A typical row (Edit = URL, Delete = `wire:click`) has 1; a row of 4 modal actions has 4.
- Where on 5.11.4: `support/src/View/DefaultLoadingIndicator.php:10-37`; `support/src/helpers.php:246-260`; `support/src/View/Concerns/CanGenerateIconButtonHtml.php:72-76,150-153`; the same in `CanGenerateButtonHtml.php:152`, `CanGenerateDropdownItemHtml.php`, `CanGenerateBadgeHtml.php`, `CanGenerateLinkHtml.php`.
- Evidence: `$hasLoadingIndicator = filled($wireTarget) || ($type === 'submit' && filled($form));` and the `<svg … ><path …>` heredoc.
- Measure: a 50-row table with record actions `[EditAction (modal, no page), DeleteAction, Action::make('a')->action(fn () => null), Action::make('b')->action(…)]`. Count `fi-loading-indicator` occurrences in the HTML and in the `/livewire/update` response (expect 200) and sum their bytes (expect ~170–190 KB uncompressed). Record the gzip size as well, because these repeat and compress well. The control is the same page with `LoadingIndicator` bound to a `<svg><use href="#fi-spinner"/></svg>` implementation.

### 3.6-d `generate_href_html()` runs an SPA regex, `parse_url` ×2, and emits ~190 B inline JS per URL
- Report: per-URL SPA regex, `parse_url` ×2, host parsing, and ~190 B of inline JS.
- Verdict: **Wrong as stated for the default config; Partly with SPA mode.** When SPA mode is off (default), it returns `href="…"` immediately: `FilamentView::hasSpaMode()` returns false before any parsing. With `->spa()`: `str($url)->is($exceptions)` runs only when `spaUrlExceptions` are set; `is_app_url` short-circuits for relative URLs and otherwise calls `parse_url` twice. The ~190 B `x-on:click` JS is emitted only when `hasNestedClickEventHandler` is true (and prefetching is off). Otherwise it adds `wire:navigate` (14 B).
- Where on 5.11.4: `support/src/helpers.php:144-185`; `support/src/View/ViewManager.php:151-166`.
- Evidence: `if (! $this->hasSpaMode) { return false; }`.
- Measure: only worth benchmarking with `->spa()` + `->spaUrlExceptions([...])`: µs per call × number of links per page (count `href=` in a 50-row table with `recordUrl`), and bytes of `x-on:click="if (! ($event.altKey` occurrences (expect 0 in tables unless nested handlers).

### 3.6-e Anonymous `<x-filament::*>` components get Laravel's Collection-based attribute bag
- Report: anonymous components use Laravel's bag rather than Filament's optimised one.
- Verdict: **Confirmed, with lower impact than implied.** Anonymous Blade components (`support/resources/views/components/*.blade.php`) receive `Illuminate\View\ComponentAttributeBag` from Laravel's compiler. Filament's optimised bag (`support/src/View/ComponentAttributeBag.php`) is only used where Filament constructs it. The hot paths (actions, buttons, icon buttons, badges, dropdown items in tables) already render through PHP generators (`CanGenerate*Html`) using Filament's bag, so the remaining Blade-component use is mostly layout, modals and form wrappers.
- Where on 5.11.4: `support/src/View/ComponentAttributeBag.php:14-24`; e.g. `support/resources/views/components/button/index.blade.php`.
- Evidence: the class docblock "Optimized attribute bag that avoids the Collection overhead in Laravel's merge()…"; no swap of Laravel's bag in `SupportServiceProvider`.
- Measure: count `Illuminate\View\ComponentAttributeBag::merge` and `class` calls (Xdebug/SPX or a profiler) on a 50-row list page and on an Edit form with 30 fields; µs share of the total render.

### 3.6-f `Str::markdown` builds a new converter per call
- Report: `Markdown.php` → a new CommonMark converter per call.
- Verdict: **Confirmed (it is Laravel's `Str::markdown`/`inlineMarkdown`, which create a new `GithubFlavoredMarkdownConverter` each call).**
- Where on 5.11.4: `support/src/Markdown.php:26-31`.
- Evidence: `return $this->isInline ? Str::inlineMarkdown($this->text) : Str::markdown($this->text);`
- Measure: a 50-row table with `TextColumn::make('body')->markdown()`. Count markdown conversions (expect 50 per render) and µs per conversion with a fresh converter against a reused static one.

### 3.6-g Core JS bundle (196 KB, ~66 KB gzip) eagerly ships SortableJS, tippy, floating-ui and js-md5
- Report: Sortable and md5 could be lazy.
- Verdict: **Confirmed (frontend; checked briefly).** `support/dist/index.js` is 196,341 B (65,788 B gzip). `index.js` statically imports `@awcodes/alpine-floating-ui`, `async-alpine`, `js-md5`, `./sortable.js` and `@ryangjchandler/alpine-tooltip` (tippy), and sets `window.jsMd5`.
- Where on 5.11.4: `support/resources/js/index.js:1-37`.
- Evidence: `import { md5 } from 'js-md5'`, `import Sortable from './sortable.js'`.
- Measure: transfer/parse time of `support.js` on any panel page (Chrome trace `EvaluateScript`), and the size of the bundle rebuilt with Sortable/md5 split out.

### 3.6-h `filter()`/`whereStartsWith()` allocate Collections per control
- Report: these allocate Collections per control.
- Verdict: **Confirmed (Low).** For example, `$attributes->whereStartsWith(['wire:target','wire:click'])->filter(...)->first()` runs per icon button/button/link/dropdown item.
- Where on 5.11.4: `support/src/View/Concerns/CanGenerateIconButtonHtml.php:72` (and the sibling `CanGenerate*Html` traits).
- Measure: µs per `generateIconButtonHtml` call (microbenchmark), × controls per 50-row page.

### 3.6-i `ColorManager` `serialize()`s cache keys
- Report: `serialize()` on cache keys.
- Verdict: **Partly (Low).** `getComponentClasses` serialises only when `$component` is an object (a `HasColor` instance); with a class-string it is used as-is. `getComponentCustomStyles` always serialises the `$color` array, but only runs for custom (array) colours.
- Where on 5.11.4: `support/src/Colors/ColorManager.php:113-123,166-170`.
- Evidence: `$componentKey = is_string($component) ? $component : serialize($component);`
- Measure: calls to `serialize` from ColorManager per 50-row page with `->color(fn …)` badge columns (profiler).

### 3.6-j `MethodAccess` reflects on every Livewire call (theme 5)
- Report: reflection on every Livewire call.
- Verdict: **Confirmed (Low).** It runs per method call (Livewire `before('call')`), not per request. Each call builds a `new ReflectionClass`, walks the parent classes with `getAttributes(Unexposed|Exposed)`, and inspects the return type. `__dispatch` calls do the walk twice. There is no per-`[class, method]` cache.
- Where on 5.11.4: `support/src/Livewire/MethodAccess.php:21-75`; registered at `support/src/SupportServiceProvider.php:173`.
- Evidence: `$class = new ReflectionClass($component); … while ($class && $class->hasMethod($method)) { … getAttributes(Unexposed::class, …)`.
- Measure: µs per invocation (microbenchmark against `EditRecord` subclass + `save`), × `calls` per `/livewire/update`. Expect single-digit µs, so negligible unless there are many calls.

### 3.6-k `copyable.js` adds a MutationObserver per cell
- Report: one MutationObserver per copyable cell.
- Verdict: **Confirmed (frontend; brief).** `init()` observes `closest('.fi-in-entry, .fi-ta-col') ?? parentElement` per instance.
- Where on 5.11.4: `support/resources/js/components/copyable.js:60-80`.
- Measure: MutationObserver count (constructor wrapper) on a 50-row table with 2 `->copyable()` columns: expect 100. Time the morph scripting.

### 3.6-l `modal.js` keeps document listeners registered while closed
- Report: document listeners stay registered while the modal is closed.
- Verdict: **Confirmed (frontend; brief).** Each modal instance adds `mousedown` and `mouseup` capture listeners on `document` in `init()` and removes them only on `destroy()`.
- Where on 5.11.4: `support/resources/js/components/modal.js:70-150,264-295`.
- Measure: `getEventListeners(document).mousedown.length` on a page with N modals (table with per-row modal actions rendered); a click-latency trace.

### 3.6-m Render hooks allocate even when none are registered
- Report: render hooks allocate even with no hooks registered.
- Verdict: **Confirmed (Low).** Each `renderHook()` normalises the name, wraps scopes, builds a closure and runs `array_map` before checking whether any hooks exist.
- Where on 5.11.4: `support/src/View/ViewManager.php:94-135`.
- Measure: the number of `renderHook()` calls per page (counter; dozens per layout, more per table) × µs per empty call.

---

## 3.8 Widgets

### 3.8-w-a ChartWidget's `updateChartData()` never calls `skipRender()`
- Report: each poll re-renders the whole view and re-hashes the dataset twice.
- Verdict: **Confirmed.** The poll calls `updateChartData()` (hash 1). The `rendering()` hook calls `updateChartData()` again (hash 2). The whole view re-renders, including `x-data="chart({ cachedData: @js($this->getCachedData()) … })"`, so the full dataset JSON is resent in the HTML on every poll even when unchanged. When it has changed, it is sent a second time in the `updateChartData` event. `getData()` runs once per request (`cachedData`).
- Where on 5.11.4: `widgets/src/ChartWidget.php:57-60,121-136`; `widgets/resources/views/chart-widget.blade.php:88-110`.
- Evidence: `public function rendering(): void { $this->updateChartData(); }`; no `skipRender()` anywhere in `widgets/src`.
- Measure: one `LineChartWidget` with 365 points × 3 datasets and unchanged data. Per poll, record (a) response bytes (expect the full widget HTML + dataset), (b) `md5`/`json_encode` of the dataset per request (expect 2), and (c) server render ms. The control is a subclass calling `$this->skipRender()` when the checksum is unchanged.

### 3.8-w-b StatsOverview polls `$refresh`
- Report: each poll rebuilds every Stat and md5s every sparkline.
- Verdict: **Confirmed.** `wire:poll.5s` has no method, so it calls `$refresh` and does a full render. `getCachedStats()` is per request, so `getStats()` (the user's queries) re-runs. Each Stat with a chart computes `md5(json_encode($chart))` (cheap). The real cost is the user's queries plus the schema render.
- Where on 5.11.4: `widgets/resources/views/stats-overview-widget.blade.php:17`; `widgets/src/StatsOverviewWidget.php:95-127`; `widgets/src/StatsOverviewWidget/Stat.php:181`.
- Evidence: `'wire:poll.' . $pollingInterval => $pollingInterval ? true : null`.
- Measure: `getStats()` calls per minute (counter) and response bytes per poll for a 4-stat widget with sparklines; queries per poll.

### 3.8-w-c Each lazy widget is a separate request
- Report: each lazy widget loads in its own request (needs checking against Livewire isolation).
- Verdict: **Confirmed (Livewire 4).** Widgets are lazy by default (`CanBeLazyLoaded::$isLazy = true`, so `getDefaultProperties()` sets `'lazy' => true`). Livewire 4's `SupportLazyLoading::mount` sets `$isolate = true` unless `lazy.bundle`/`#[Lazy(bundle: true)]`, and the JS partitions each un-loaded lazy isolated component into its own request. Loads trigger on viewport intersection (`lazy` is not `'on-load'`). After loading, the widgets' polls are bundled (see #3).
- Where on 5.11.4: `support/src/Concerns/CanBeLazyLoaded.php:10-23`; `widgets/src/Widget.php:110-118`; `widgets/resources/views/components/widgets.blade.php:35-39`; Livewire `src/Features/SupportLazyLoading/SupportLazyLoading.php:48-101`, `js/request/interactions.js:11-19`.
- Evidence: `$isolate = true; … if (isset($params['lazy.bundle']) && $params['lazy.bundle']) $isolate = false;`
- Measure: dashboard with 6 widgets visible on load. Count `/livewire/update` requests in the first 2 s after `DOMContentLoaded`: expect 6. With `protected static bool $isLazy = false` on each widget: expect 0 (all inline). The control would pass `lazy.bundle`: expect 1.

### 3.8-w-d Page filters re-render every widget at once
- Report: changing a page filter re-renders all widgets at once.
- Verdict: **Confirmed.** The dashboard filters form is `->live()` (`HasFiltersForm.php:31-39`). Each widget using `InteractsWithPageFilters` has `#[Reactive] public ?array $pageFilters`, so every filter keystroke or change re-renders **every** reactive child in the same request (Livewire bundles children with reactive bindings), re-running all their queries. `HasFiltersAction` (a modal with deferred apply) avoids the per-keystroke part.
- Where on 5.11.4: `panels/src/Widgets/Concerns/InteractsWithPageFilters.php:13-14`; `panels/src/Pages/Dashboard/Concerns/HasFiltersForm.php:31-39`; `panels/src/Pages/Page.php:639`.
- Evidence: `#[Reactive] public ?array $pageFilters = null;` and `->live()->statePath('filters')`.
- Measure: dashboard with `HasFiltersForm` (a `DatePicker` + `Select`) and 6 widgets using `InteractsWithPageFilters`. Change the `Select` once and count (a) HTTP requests (expect 1), (b) components in that request's response `components[]` (expect 7), (c) widget queries (Σ of all widgets), and (d) response bytes. Compare with `HasFiltersAction`.

## 3.8 Notifications

### 3.8-n-a JSON-path unread count + redundant `exists()` every 30 s per tab; the poll runs even with Echo
- Report: `where('data->format','filament')` count + `exists()` every 30 s; no index; the poll still runs with Echo.
- Verdict: **Partly.**
  - Confirmed: every render runs `count()` of unread notifications. When the modal is closed, it also runs `exists()` (when open it uses the paginated `count()` instead). The `exists()` is redundant only when unread > 0; it is needed when all are read.
  - Confirmed: the `wire:poll.30s` is unconditional. There is no check of `hasBroadcasting()` or of Echo configuration.
  - Overstated: "no index". Laravel's notifications table has the `morphs('notifiable')` index, so the database narrows to the user's rows first. The JSON extract (`data` is a TEXT column) is then evaluated for each of that user's rows. Cost is O(notifications of that user), not O(table).
  - The feature is **off by default** (`hasDatabaseNotifications = false`) and lazy by default: the placeholder also runs a count.
- Where on 5.11.4: `notifications/src/Livewire/DatabaseNotifications.php:37,113-140,248-251`; `notifications/resources/views/database-notifications.blade.php:9-13,49-51`; `panels/src/Panel/Concerns/HasNotifications.php:12-18`.
- Evidence: `$unreadNotificationsCount = $this->getUnreadNotificationsCount(); $hasNotifications = $notifications?->count() ?? $this->getNotificationsQuery()->exists();`
- Measure: `->databaseNotifications()`, a user with 10k notifications (500 unread), MySQL and Postgres.
  - Per poll request: notification queries (expect 2, count + exists) and their time with EXPLAIN (key = notifiable index, JSON filter applied as a filter on those rows).
  - Poll requests per minute in a visible tab (expect 2).
  - With `->databaseNotifications()` + `filament.broadcasting.echo` configured: still 2/min.

### 3.8-n-b The open list re-renders and re-hydrates all 50 notifications every poll
- Report: the open list re-renders all 50 notifications on every poll.
- Verdict: **Confirmed.** While `isOpen`, each poll runs `simplePaginate(50)` (51 rows), then `Notification::fromDatabase()` per row (actions are rebuilt) and the full modal render.
- Where on 5.11.4: `notifications/src/Livewire/DatabaseNotifications.php:97-106,212-216`; view line 9.
- Evidence: `return $this->getNotificationsQuery()->simplePaginate(50, pageName: 'database-notifications-page');`
- Measure: with the modal open and 50 notifications that each have 2 actions, record per poll response bytes, server ms, and `Notification::fromDatabase` calls (expect 50).

### 3.8-n-c Global `PublishNotifications` middleware (theme 3)
- Report: prepended to the global kernel; `json_decode`s every Livewire response, including big table HTML, and reads the cache every time.
- Verdict: **Confirmed, scoped to Livewire requests.** It is prepended to the **global** HTTP kernel, so it runs on every request in the app. On a **non-Livewire** request (no `X-Livewire` header), `$payload` is `null` and there is no decode and no cache read, unless notifications were sent during that request (then one locked cache write). The cost there is resolving the scoped `NotificationMailbox` (negligible). On **every Livewire** request it:
  - (1) Decodes the whole response JSON: `JsonResponse::getData()` is a `json_decode` of the body. New in 5.x, it also `json_decode`s non-`JsonResponse` bodies (streamed responses).
  - (2) Calls `hasPublishedNotifications()`, which does a cache `get` of the mailbox key. With the **file** cache store it does that under a **blocking lock** (`mutate()` → `lock->block(5)`); with the database store it is one `SELECT` on the `cache` table.
  - (3) If any notification is pending or published, `json_encode`s the whole payload again (`setData`).
  - Separately, `InitializeNotificationMailbox` is attached to every matched route (`RouteMatched` listener) and touches the session twice per request.
- Where on 5.11.4: `notifications/src/NotificationsServiceProvider.php:46-57`; `notifications/src/Http/Middleware/PublishNotifications.php:14-56`; `notifications/src/NotificationMailbox.php:67-85,199-243`; `notifications/src/Http/Middleware/InitializeNotificationMailbox.php`.
- Evidence: `$payload = Livewire::isLivewireRequest() ? (($response instanceof JsonResponse) ? $response->getData() : json_decode($response->getContent() ?: '')) : null;` and `if (($batch !== null) || $mailbox->hasPublishedNotifications())`.
- Measure:
  - (a) Time spent in `PublishNotifications::handle` after `$next()` for a `/livewire/update` returning ~500 KB (a 100-row table sort). Expect a few ms for the decode. With a notification sent in that request, expect decode + re-encode.
  - (b) Cache operations per Livewire request: with `CACHE_STORE=database`, expect +1 `select … from cache where key = 'filament:notifications:…'`. With `CACHE_STORE=file`, expect a lock file create + read + delete. Also measure contention with 5 concurrent Livewire requests from one session.
  - (c) Overhead on a non-Livewire `GET /api/ping`: expect ≈0 (but `InitializeNotificationMailbox` touches the session if the route has one).

### 3.8-n-d `sendToDatabase()`/`broadcast()` rebuild the payload per recipient; the "sent" event fires before the queued insert
- Report: the payload is rebuilt per recipient, and the event fires before the queued insert.
- Verdict: **Confirmed.** The loop calls `$user->notify($this->toDatabase())` per user, and each call rebuilds `getDatabaseMessage()` (`toArray()` with actions and closures). `DatabaseNotification` implements `ShouldQueue`, so with a non-sync queue `DatabaseNotificationsSent::dispatch($user)` fires before the row exists, and the recipient's `refresh` may miss it until the next 30 s poll. That is a correctness issue as well as a performance one.
- Where on 5.11.4: `notifications/src/Notification.php:177-221`; `notifications/src/DatabaseNotification.php:11`.
- Evidence: `foreach ($users as $user) { $user->notify($this->toDatabase()); if ($isEventDispatched) { DatabaseNotificationsSent::dispatch($user); } }`
- Measure: `Notification::make()->…->actions([2 actions])->sendToDatabase(User::limit(1000)->get(), isEventDispatched: true)`. Record `toArray()` calls (expect 1000), wall time, and queued jobs (expect 1000). With `QUEUE_CONNECTION=database`, check whether the `DatabaseNotificationsSent` broadcast precedes the `notifications` insert (timestamps).

---

## Cross-cutting themes (panels/support parts), mapped to the entries above
- Theme 3: polling defaults: #3, 3.8-n-a. `PublishNotifications` global middleware: 3.8-n-c. `IdentifyTenant`: 3.5-d.
- Theme 4: tenancy `whereHas`: 3.5-e.
- Theme 5: `getAlpineComponentSrc`: 3.6-b. `MethodAccess` reflection: 3.6-j.
- Theme 6: loading spinner SVG per button: 3.6-c. Dropdown listeners: 3.6-a.

## Not in the report (noticed while checking)
1. **`->navigationHierarchyInBreadcrumbs()`** (opt-in) makes every Livewire update of any panel page mount the full navigation (`Page::getBreadcrumbs()` → `$panel->getNavigation()`), with every `canAccess()` and every badge COUNT. See #6.
2. **`getRedirectUrl()` computes badges under other tenants.** It switches the tenant quietly and mounts the whole navigation per switchable tenant, only to read the first item's URL. On tenant-domain panels this is the dominant cost of the tenant menu (#5).
3. **`PublishNotifications` with the file cache store** takes a blocking cache lock on every Livewire request (`hasPublishedNotifications()` → `mutate()`), which serialises concurrent Livewire requests from the same session for the length of the cache read.
4. **ChartWidget resends the full dataset in the HTML on every poll** (`@js($this->getCachedData())` inside `wire:ignore`), even when unchanged (3.8-w-a).
5. **Record pages** also run `hydrateCanAuthorizeAccess()` (a `canAccess` / `update` policy) on every Livewire update (`InteractsWithRecord.php:38-41`). That is expected for security, but it should be counted in a benchmark's policy totals.
6. Possible non-performance issue (not verified at run time): `getHomeUrl()` → `getUrl()` with no tenant uses `getUserDefaultTenant()`, not `Filament::getTenant()`, so the logo may link to the default tenant rather than the current one.

## Assumptions
- Livewire behaviour comes from Livewire `main` at `e03c29c` (Nov 2026, ≥ v4.4.8, which `composer.json` requires). The repo has no `vendor/` or lock entry for Livewire, so the exact installed version in the benchmark app may differ.
- "Documented" tenant model methods are the ones in `docs/07-users/03-tenancy.md:96-104`.
- Byte sizes come from the checked-in `dist/` files at v5.11.4.
