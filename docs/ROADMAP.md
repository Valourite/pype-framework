# Roadmap

What's planned, roughly in order. Every item must fit the principles in `../CLAUDE.md`: feel like
PHP, build on native functions, cost nothing unless used. Discuss with the owner before starting
anything big.

## Done

- **0.1.0**: pipeline, stages, outlets, routing, container, JSON/views, validation, auth (tokens),
  query builder, `.sql` migrations, test client, console.
- **0.2.0**:
  - sessions + CSRF + guards; joins/aggregates/pagination;
  - uploads/downloads; MethodOverride, RedirectOnSuccess;
  - file sessions (written only when changed); rate-limit drivers (database/APCu);
  - PHP schema builder; SQLite/MySQL/Postgres tested;
  - app CLI commands; OpenSwoole server (`APP_SERVER=swoole`).
- **0.3.0**: faster route matching (hash for static paths, combined `(*MARK)` regexes for
  params) and `bin/pype optimize` (compiled route tables). Config caching was measured and
  skipped: loading config + `.env` takes ~11 µs, a cached config file wasn't faster. Details in
  `ARCHITECTURE.md` under "Route matching". Also named routes with group prefixes, `url()` /
  `Urls`, signed URLs (`signed_url()`, `ValidateSignature`, `APP_KEY` + `key:generate`), names in
  `bin/pype routes`.
- **0.8.1** (2026-10-20): docs. The app guide is `docs/GUIDE.md` (shipped in `vendor/`, moved
  from the skeleton README) with new Testing and Roles/ownership/per-user-limits sections.
- **0.8.0** (2026-10-20): from the $12-server bench (see BENCHMARKS): query builder memoizes quoted
  identifiers (~6 → 1.4 µs per query); OpenSwoole defaults to 1 worker per core and reads the
  container's CPU limit; `Auth\Jwt` + `JwtGuard` (HS256/RS256); configurable wording for pype's
  own errors (`app.errors`) and `Validate` status; `TrimStrings` stage; `insertReturning()`;
  `Request::withInput()`. Fixes: route params are URL-decoded; the container uses defaults for
  optional dependencies it can't build.
  Factories and seeders (`Database\Factory`, `Fake`, `Seeder`, `Hydrator`,
  `QueryBuilder::insertMany()`, `db:seed`, `migrate:fresh --seed`, `make:factory`,
  `make:seeder`). fakerphp/faker considered (MIT, 12 MB, 75 locales): left as an app's choice
  inside `definition()`, not a pype dependency.
- **0.7.0** (2026-10-20): migration rollback (`down()`, `-- down` in `.sql`, batches,
  `migrate:rollback [--step=N]`, `migrate:fresh`, `Schema::dropAllTables()`); fix: `migrate` (and
  any `insert()` into a table without a sequence) failed on a fresh Postgres database.
- **0.6.0** (2026-10-20): database job queue (`Pype\Queue`: `push`/`later`, retries with backoff,
  timeouts, failed jobs, `queue:work`/`restart`/`failed`/`retry`/`forget`, `Mailer::queue()`,
  `make:job`) and the scheduler (`Pype\Schedule`, `routes/schedule.php`, own cron parser,
  `withoutOverlapping`/`onOneServer`, `schedule:run`/`list`/`work`); `FileStore::pruneExpired()`.
- **0.5.0** (2026-10-20):
  - validation rules: `unique`/`exists` (+ `Unique`/`Exists` objects, `{param}` from the route), dates (`before`/`after`...), `items.*` wildcards and dot paths, `required_if`/`required_with`, `regex`, `same`/`different`, `between`, `digits`, `alpha*`, `uuid`, `ip`, `json`, `accepted`, `not_in`; closures get `($value, $field, $data)`; `Rule` interface; custom messages.
  - `Security\Encrypter` (libsodium, APP_KEY, key rotation); "remember me" (`Auth\RememberTokens`, a table per device, selector + validator, rotation, replay detection; `SessionGuard::login(..., remember: true)`).
  - cache (`Pype\Cache`, PSR-16): `remember`/`add`/`increment`/`pull`/`forever`, prefix-limited `clear()`; file (default), APCu, database, array, null stores; locks, `stale()` background refresh, groups with one-write `flush()`; `bin/pype cache:clear`; `Table::longText()`.
  - password reset core (`Auth\PasswordResets`), email verification (`Auth::markEmailVerified/isEmailVerified/emailHash`, `EnsureEmailIsVerified`); the flows, emails and pages live in the skeleton.
  - mail (`Message`, `Mail` classes, `Mailer::send()` / `later()`, PHPMailer SMTP/sendmail/mail, log/array/null transports) and `AfterResponse` + `Application::terminate()`.
- **0.4.0**: logging (PSR-3 `Logger`, JSON lines, request log, forwarding to stderr/syslog/any PSR-3 logger), the live log viewer, the
  development error page, `HtmlOutlet`.

## Next: not decided

Pick the next round from Tier 2 / the follow-ups below with the owner.

## Idea to circle back to: route caching (discussed 2026-10-20, not planned)

Replaces the earlier "whole-page caching for guests only" idea. The owner's direction: **one
stage caches the whole route's finished output per user**, so apps don't cache stage by stage.

```php
$router->get('/dashboard', ['auth', new CacheRoute(ttl: 3600), ResolveMonth::class, MonthSummary::class, Dashboard::class]);
```

- **What is cached:** the final output (status, headers, rendered body), not the handler's data.
  Reason: views can do work of their own (expenses' `Dashboard::data()` runs two more queries)
  and read context that skipped stages would have put there (`Month`). Needs a small core hook
  so the stage can store the body after the outlet renders (outlets render after the pipeline).
- **Placement:** after `auth` (the key needs the user). Stages before it still run on a hit
  (session, auth); everything after it is skipped (stages, handler, outlet).
- **Key:** route + path + query + output format + user (or a shared "guest" key on public pages
  without a session, which is the old whole-page idea as a special case).
- **'web' traps, handled by the stage:** the CSRF token is per session (two devices = two
  tokens), so include it in the key; skip the cache when the session has flash data, old input
  or validation errors; never store non-200s, redirects, responses setting cookies, downloads.
- **Staleness:** TTL as the backstop; entries in the user's cache group (`user.{id}`), which
  apps already flush on writes (expenses does); optionally flush a user's group automatically
  after any successful POST/PUT/PATCH/DELETE by that user (covers their own changes, not changes
  by others or cron).
- **Costs:** storage grows with users × pages × query variants (TTL bounds it); only worth it
  where reads outnumber changes. Gain unmeasured: benchmark the expenses dashboard before/after.
- **Open questions:** auto-flush on writes default on / opt-in / none; the name (`CacheRoute`,
  `CachePage`, `CacheResponse`); which expenses pages get it.
- **Considered and parked:** "holes" (donut caching: one shared shell, per-user parts filled
  per request, pype's own `csrf()`/`old()`/`error()`/flash as automatic holes, a debug-mode leak
  check). Only pays off on pages with a heavy shared part; one forgotten hole leaks a user's data.

## Gaps found by the docs test (2026-10-20)

A fresh agent given only the expenses app answered 12 "how would you build X?" tasks. These are
places where pype itself, not the docs, came up short:

- **Rules that know the user:** "unique per user" (`sku` within one user's items) can't be
  expressed in `Validate`, because rules can't see the request context. Idea: `{user}` in rule strings
  (like `{id}` for route params), or `rules(Request $request)`.
- **Per-user rate limits:** `Throttle` keys on IP + route. Idea: `new Throttle(30, 60, by: 'user')`.
- **Roles / authorization:** no built-in; apps write an ownership stage and an admin stage
  (documented in GUIDE "Roles, ownership and per-user limits"). Idea: a small `Authorize`
  stage taking a closure (`fn (User $u, Quote $q) => ...`), 404 or 403 on false.

## Found by building quotes and invoices in the expenses app (2026-10-21)

The expenses app grew a company/team model, an item catalogue, customers, quotes and invoices (see
`../expenses/CLAUDE.md`, "What the app does"). It was then seeded with ~3 million expenses and ~420,000
quotes and invoices (474 MB SQLite) to feel the UX. These are the places where pype itself came up short,
in the order the owner wants to tackle them. **Start with 1.** Discuss each one before building (see the
principles above); the owner wants to follow along and work on it too.

Evidence below was measured locally: SQLite, Swoole 6.2.3 (4 workers), the demo company with 150,000
invoices and 80,000 quotes. It is only meant to show what scales badly, not to be a benchmark.

### 1. Pagination with links in the view (decided: do first)

Today `Page` (`src/Database/Page.php`, `final readonly`) has `items`, `total`, `perPage`, `page`,
`lastPage()`, `hasPrevious()`, `hasNext()`, `meta()`. Nothing builds links. The expenses app hand-wrote
`resources/views/partials/pagination.php`: only "← Newer / Older →", so you cannot jump to page 9,000.
`QueryBuilder::paginate()` also always runs `COUNT(*)` on a clone of the full query (joins included).

Wanted:
- **Links for views.** Something like `$page->links(fn (int $n) => url('quotes.index', ['page' => $n, ...]))`
  returning the numbers to show: first, previous, a window around the current page, next, last, with gaps
  marked, so a view loops over it. Or a ready view partial the app can include. Keeping the other query
  parameters (`?status=`, `?q=`, `?month=`) is the part apps get wrong; every list page in the expenses
  app rebuilds them by hand. Decide: data helper only, or also ship a partial? (pype ships no views today.)
- **Cheaper counts.** `simplePaginate()`: fetch `perPage + 1` rows, no `COUNT(*)`, only Next/Previous.
  And/or `paginate(..., count: $cheaperQuery)` to count without the joins and the select list. Measured
  with 150k invoices: `/invoices` 90 ms, `/quotes` 50 ms (80k), `/billing` 92 ms (it only wants 5 rows,
  but goes through `paginate()`), `/invoices?page=9000` 205 ms. Most of that is the count plus the offset.
- **Out-of-range pages.** `?page=99999` renders an empty page. Clamp to the last page, or 404.
- **Later:** keyset paging (`?after=<id>`) for very deep pages, where `OFFSET` is the cost.
- JSON stays as it is: `{"data": [...], "meta": {"pagination": {page, per_page, total, last_page}}}`.

Acceptance: unit tests in `tests/Unit` for the link window (first/middle/last page, one page, gaps),
`simplePaginate()` (no count query run; has-next from the extra row), clamping; a GUIDE section with the
view example; then switch the expenses app over (see "Expenses app follow-ups").

### 2. Foreign keys should be indexed on SQLite and Postgres

`foreignId('quote_id')->constrained('quotes')` creates the constraint but no index on SQLite or Postgres
(MySQL indexes foreign key columns itself). With 94k quote lines, opening one quote ran
`WHERE quote_id = ?` as a full scan, and a plain verification query hung for over two minutes. The app
fixed it with migration `2026_10_21_000500_index_billing_foreign_keys` (skipped on MySQL, the same
convention as `2026_10_18_000000_index_expenses_category`). Idea: `constrained()` adds the index by default
on non-MySQL drivers, with a way to opt out (`->withoutIndex()`), and `down()` drops it. Check how
`Schema/Grammar` emits foreign keys and what a rollback does.

### 3. Stages that take arguments *and* services

`new LoadDocumentsPage('quote')` is not autowired (only class-strings are), so three stages implement
`ContainerAware` and pull their services from the container by hand: `LoadDocumentsPage`,
`LoadDocumentChoices`, `ThrottlePerUser` (`Throttle` and `ValidateSignature` do the same). Idea: a way to
give some constructor arguments and inject the rest, e.g. `LoadDocumentsPage::with(kind: 'quote')` or
`$router->get(..., [stage(LoadDocumentsPage::class, kind: 'quote')])`. Related: `new X(...)` in route
files loads every referenced class on every request. The expenses quote page loads 106 files cold, 12 of
its 23 `app/` files unused by that page. Lazy stage objects (or the parked route-cache idea above) fix both.

### 4. Pages and JSON that read the request context

The expenses routes have no handler closures: `Resolve...`/`Load...` stages put data in the request
context and the route ends with an outlet that reads it. Built in the app, worth promoting:
- `App\Support\ViewData`: a named bag in the context (`ViewData::in($request)->put('page', $page)`).
- `App\Outlets\ContextView`: a `ViewOutlet` whose template gets the ViewData entries plus named context
  entries (`new ContextView('items/form', item: Item::class)`).
- `App\Stages\RespondWith`: ends a JSON route with a context entry (`::context(Item::class)`,
  `::data('page')`, `::everything()`).
- `App\Stages\RedirectTo`: wraps `RedirectOnSuccess` with named targets (`::route('quotes.show', 'msg',
  withId: true)`, `::sameUrl()`, ...). `RedirectOnSuccess` only takes a URL or a closure.
Idea: ship generic versions (`ContextView`, `RespondWith`, a `RedirectOnSuccess` that takes a route name) so
apps don't each write them. Keep the closure forms working.

### 5. Already listed above, now with workarounds in the app

- Per-company unique (`items.sku`): checked inside `CreateItem`/`UpdateItem`; 422 on `sku`.
- Per-user rate limit: `App\Stages\ThrottlePerUser` (uses `RateLimiter::hit`).
- Roles: `App\Stages\EnsureAdmin` (404 for members), `users.role` column.
See "Gaps found by the docs test" for the ideas; these are the real implementations to learn from.

### 6. Dev mailbox

`MAIL_DRIVER` defaults to `log`: sending "succeeds" and the email only goes to `storage/logs`. The owner
sent a quote and thought it had failed. Idea: a dev viewer like `/_pype/logs` (e.g. `/_pype/mail`) listing
captured mail with its links, and/or a one-line note when the log driver is active under `APP_DEBUG`.

### 7. Form helpers for the views

`resources/views/partials/field.php` only renders `<input>`. Missing: select, textarea, checkbox, and
indexed rows like `lines[3][quantity]` with old input and errors (`$this->old('lines')`,
`$this->error('lines.3.quantity')`). Also: the app drops blank rows before validation (`DropBlankLines`), so
error indexes (`lines.0...`) no longer match the rows the browser sent. Idea: a `Validate` "prepare" hook
that cleans input first and a helper that keeps old input and errors aligned.

### 8. Resource routes

Items and customers are the same seven routes. `$router->resource('/items', ...)` (index, create, store,
edit, update, destroy with route names) would cut `routes/web.php`.

### 9. Test helpers

The app wrote its own `TestCase::submitForm()` (load the page, take its CSRF token, post) and `signIn()`
(create a user with `UserFactory`, log in through the form). Idea: built in to `TestClient`, plus
`actingAs($user)`. There are still no assertion helpers.

### 10. Smaller things

- `Fake::unique()` runs out of words around 500 (`UniqueFake` throws); the seeder hit it with 500 items.
- Factories return arrays without `$class` and objects with it; easy to trip over (`$row->id` is null).
- `app.swoole.*` only passes `workers` and `max_request` to `SwooleServer`; other settings need a custom
  start script. Consider passing any `app.swoole.settings` array through.
- The static-handler stall fix (see "OpenSwoole follow-ups"): still to check on real OpenSwoole, and to add
  to the bench (page, then css, then page again on one keep-alive connection).

### Expenses app follow-ups (do after the framework side of 1)

- Switch every list (`documents/index`, `items/index`, `customers/index`, `expenses/index`) to the new links;
  delete `partials/pagination.php`.
- `LoadBilling` gets its latest five invoices through `paginate()` (so a count over 150k rows): use a plain
  limit, or `simplePaginate()`.
- `/quotes/new` and `/invoices/new` are 1.66 MB of HTML with the seeded data: every line row repeats the
  whole item list, and customers are one 8,000-option dropdown. Replace with search-as-you-type
  (`/api/customers?q=`, `/api/items?q=` already exist and take `?q=`).
- `.env.example` still says OpenSwoole "default 2 per CPU core" and "needs the openswoole extension";
  document `SWOOLE_STATIC_HANDLER`; mention in CLAUDE.md that the app runs on Swoole (`php8.5-swoole`).

## Decided with the owner (2026-10-21): the work list

**After 0.9.0 (owner, 2026-10-21): "handlers" are not a pype concept; stages are.** The skeleton has no
`app/Handlers`: every step is a stage in `app/Stages` (the one doing the work returns the result instead
of calling `$next`), JSON shaping is a `JsonOutlet` subclass with `data()` (new hook, like
`ViewOutlet::data()`) in `app/Outlets` (`UserJson`), and no route in the skeleton (or pype's own dev
pages: `ShowLogs`, `ShowLogEntries`, `MailboxViewer`) uses a closure. Closures and invokables still
work in the pipeline; the docs no longer teach them.


Agreed in a design discussion after the section above. The section above has the evidence; this is what
to build and the shape agreed. The pipeline idea behind all of it: **a route is stages that run in order
and talk through the typed request context; a missing entry throws; everything is a class so it is
testable; route files stay small, only stages.** `new X(...)` stays allowed for one-off literals.
Build order: 17, 18, 3, 1, 2, 7, 4, 11, 12, 9, 8, 6, 16, 19, 5, 10. Work through each with the owner.

### D1. Pagination with links (was 1) — **done 2026-10-21, uncommitted** (keyset paging still later)
Built: `paginate($perPage, int|Request $page, count: QueryBuilder|int, pageName:)` (page from `?page=`, clamped to
1..last), `simplePaginate()` returning `Pype\Database\SimplePage`, `Page::links($window)`/`url()`/`previousUrl()`/
`nextUrl()`, `PageLink`, `PageUrls` (path + other query params, page 1 without `?page=`), `Response::source()`
(the Responsable a response came from) so views get `$page` when a route returns a Page; JSON unchanged.
`tests/Unit/PaginationTest.php`, GUIDE "Pagination". Behaviour change: `paginate()` past the end now returns
the last page instead of an empty one (`QueryBuilderTest` updated). Not tested: that `simplePaginate()` runs no
COUNT (there is no query log to assert on).
- `$page->links()` returns plain data (first, previous, a window, next, last, gaps marked: `->number`,
  `->url`, `->current`, `->gap`). **Automatic**: builds URLs from the current request and keeps the other
  query parameters (`?status=`, `?q=`, `?month=`). No view partial ships; the GUIDE shows the loop.
- `simplePaginate()` (fetch `perPage + 1`, no `COUNT(*)`), a cheaper count option, JSON unchanged.
- `?page=99999` **clamps to the last page**. Keyset paging (`?after=`) later.
- Tests: link window (first, middle, last, one page, gaps), no count query for `simplePaginate()`, clamping.

### D2. Index foreign keys on SQLite/Postgres (was 2) — **done 2026-10-21, uncommitted**
Built: `Grammar::indexes()` adds `<table>_<column>_index` for every `constrained()` column on SQLite/Postgres
(skipped on MySQL, for unique/primary columns, with an explicit `->index()`, and when a declared composite index
starts with the column); `Column::withoutIndex()`; on SQLite, `dropColumn()` first runs
`DROP INDEX IF EXISTS <table>_<column>_index` (SQLite refuses to drop an indexed column). Tests in
`SchemaTest`; GUIDE migrations paragraph. Only affects migrations run from now on: existing databases keep
what they have (an app adds the indexes with a new migration, as the expenses app did).
`constrained()` adds the index by default on non-MySQL drivers; `->withoutIndex()` opts out; `down()` drops it.

### D3. Stages with arguments: `stage()` and `::with()` (was 3) — **done 2026-10-21, uncommitted**
Built: `Pype\Pipeline\StageSpec`, `stage()` in `helpers.php`, the `Pype\Pipeline\WithArguments` trait (gives
`::with()`), `Container::makeWith()` (named or positional arguments, the rest autowired, unknown names
throw), `Pipeline::invoke()` builds specs, `Router::isOutlet()`/`describe()` see through them,
`tests/Unit/StageSpecTest.php`, GUIDE "Stages with arguments". Follow-up done too: every configured built-in stage/outlet has `::with()` (the
trait; their `setContainer()` still runs, so nothing else changed), and the `'web'`/`'auth'` stacks are specs and
class-strings instead of instances. Still open: `optimize` caching whole stage lists; and decided 2026-10-21: `new` stays allowed in
route files; docs and stubs show `::with()` first; `routes:check` (D11) lists `new` stages as a hint (built at boot,
shared by every request in a worker), not an error. A strict mode may come later.
- `stage(LoadDocumentsPage::class, kind: 'quote')` returns an immutable descriptor (class + scalar/array
  arguments). Nothing is instantiated until the pipeline reaches it; the container then builds it with the
  given arguments plus autowired services. Fresh instance per request (worker-safe: a stage may keep what it
  loaded in a property). Descriptors are plain data, so `bin/pype optimize` can later cache whole stage lists.
- A stage may declare a static named constructor, convention **`with()`**:
  `LoadDocumentsPage::with(kind: 'quote')` returns `stage(self::class, kind: ...)`.
- Arguments are scalars/arrays only; objects belong in the context. `new X(...)` and `ContainerAware` keep working.
- Why arguments: configuration known when the route is written (`Throttle::with(5, 60)`,
  `RequireRole::with('admin')`); data produced during the request goes in the context.
- Class-string stages are already lazy (resolved in `Pipeline::invoke()` when reached). The cost today is
  `new X()` in route files: every one runs on every request under FPM (once per worker under Swoole).

### D4. No generic ContextView/RespondWith (was 4) — **done 2026-10-21, uncommitted**
Built: `RedirectOutlet` and route names in `RedirectOnSuccess` (with D18), `php bin/pype make:view Name` (a page
outlet reading the context, plus its template), `tests/Unit/PageOutletTest.php`, GUIDE "Views" shows the
stages-then-page-outlet shape. Skeleton: its page closures became `WelcomeView`, `AccountView`,
`ResetPasswordView` (`routes/web.php` has no closures left). Nothing else was needed in the framework:
`ViewOutlet::data()` already gets the request, so it reads the context.
Pages use **one outlet class per page** (`QuoteView extends ViewOutlet`, `data()` reads typed context);
JSON routes end with a stage that returns data. `ContextView`, `RespondWith`, `ViewData` and the `Load…Page`
stages in the expenses app are not promoted. `RedirectOnSuccess` accepts a **route name** as well as a URL.
**`RedirectOutlet`** (new): `new RedirectOutlet('quotes.show', flash: 'Quote accepted.')` as the last element
fills `{id}` from the matched route's params. Outlets pass a `Response` through unchanged, so a stage can
still return `redirect(...)` (D18) to bypass it.

### D5. Per-user throttle and per-scope unique (was 5) — **done 2026-10-21, uncommitted** (roles still parked)
Built: `Throttle` `by:` (context entry's id, `Auth::USER` = configured user class, guests fall back to IP) and
`key:` (a shared counter instead of method + path); `unique`/`exists` string rules take `col=value` scopes,
`unique`'s ignore id accepts `null`/empty; `Validate` fills `{user.prop}` from the signed-in user (clear error when
nobody is) and has a `rulesFor(Request)` hook (new method, so existing `rules()` overrides keep working).
`tests/Unit/PerUserLimitsTest.php`, GUIDE "Roles, ownership and per-user limits". Skeleton: resend-verification
throttled per user; CLAUDE.md built-ins rows.
- `new Throttle(20, 3600, by: User::class)`: the key uses the user id from the context (shared across that
  user's devices); guests fall back to the IP.
- A `unique` rule that can read a context entry (per-company SKU).
- **Roles / `Authorize` stage: parked, review later.** No tenancy system.

### D6. Dev mailbox (was 6) — **done 2026-10-21, uncommitted**
Built: `Pype\Mail\Mailbox` (JSON file per message in `storage/mail`, newest `keep` kept, links extracted),
`MailboxTransport` (wraps the configured transport while the mailbox is on; not for array/null),
`MailboxViewer` + `Mail/views/mailbox.php` at `/_pype/mail` (`pype.mail`; list, `?id=` detail with sandboxed
iframe, warning banner for the log driver), `mail.mailbox` config (enabled = APP_DEBUG by default; outside
debug only with guarding `stages`, as the log viewer), `serve` prints a line when `MAIL_DRIVER=log` (and the
mailbox URL). Checked end to end on the skeleton (register → welcome mail → link listed).
`tests/Unit/MailboxTest.php`, GUIDE "Mail". Skeleton: `config/mail.php` mailbox block, `.env.example`,
`.gitignore` (`/storage/mail/`), CLAUDE.md built-ins row.
`/_pype/mail` lists captured mail with its links (`APP_DEBUG` only) and a one-line warning when the `log`
mail driver is active.

### D7. `$csrf`, `$old`, `$error` in every template (was 7) — **done 2026-10-21, uncommitted**
Built in `Template::render()` (so layouts and partials get them too, only when rendering for a request):
`$csrf` (token string; without a session a Stringable that throws a clear LogicException when echoed), `$old`
and `$error` (first-class callables of the Template methods), `$errors`. `old()` also reads dot keys
(`lines.3.quantity`). Differs from the plan: a colliding key in the view's data **wins** instead of throwing
in debug (throwing would break any page that passes `errors` or `old`); documented. `tests/Unit/TemplateLocalsTest.php`,
GUIDE forms section; skeleton templates and CLAUDE.md switched. The `Validate` "prepare" hook
is now its own item, D20.
The framework owns **no HTML**. `ViewOutlet::render()` adds locals to every template (and partials):
`$old('email')`, `$error('email')`, `$errors`, `$csrf` (token string; clear error on use without the `web`
stack). Per-request closures, not globals (a global `old()` would need a "current request" static, unsafe
under Swoole). Names are reserved: a `data()` key that collides throws in debug. `$this->old()` keeps working.
Open: a `Validate` "prepare" hook so cleaning rows (`DropBlankLines`) keeps `lines.N` error indexes aligned.

### D8. Resource routes (was 8) — **done 2026-10-21, uncommitted**
Built: `Router::resource($path, $actions, name:, param:)` and `apiResource()` returning the routes by action;
unknown keys throw (a typo can't silently drop a route); default name from the path with `{params}` dropped
(`/quotes/{quote}/lines` → `quotes.lines`). `tests/Unit/ResourceRoutesTest.php`, GUIDE "Resource routes".
Skeleton: `/api/users` index + show are an `apiResource`. Update is PATCH only (no PUT alias) as agreed.
`$router->resource('/items', [...stage lists per key...])`: adds only the name prefix and the conventional
path/method per key: `index GET /`, `create GET /new`, `store POST /`, `show GET /{id}`,
`edit GET /{id}/edit`, `update PATCH /{id}`, `destroy DELETE /{id}`; names `items.<key>`; an omitted key means
no route. `apiResource()` is the same without `create`/`edit`. Expands to ordinary routes.

### D9. Test helpers (was 9) — **done 2026-10-21, uncommitted**
Built: `TestClient::submit($page, $action, $fields, $files)` (token from the page's `_token` input, Referer =
the page, Host localhost; clear LogicException when the page has no token) and `actingAs(object|int $user)`
(stores a signed-in session and puts its cookie in the jar; needs SessionProvider). `TestResponse` assertions
(PHPUnit's `Assert`, chainable): status ones, `assertRedirect`, `assertHeader`, `assertSee`/`assertDontSee`,
`assertJson` (subset), `assertJsonPath`, `assertCookie`, `assertInvalid` (API 422 or flashed form errors).
`tests/Unit/TestHelpersTest.php`, GUIDE "Testing". Skeleton: `WebAuthTest` and `PasswordAndVerificationTest`
use them (their own token helpers are gone), plus an `actingAs` example.
Written in `TestClient`, no package: `submit($page, $action, $fields)` (loads the page, takes the CSRF token,
posts with `Host`/`Referer` defaults), `actingAs($user)` (session without the login form), plus the missing
assertions (`assertRedirect`, `assertStatus`, `assertSee`, `assertJson`...). Symfony DomCrawler could be an
optional dev dependency later.

### D10. Smaller things (was 10) — **done 2026-10-21, uncommitted**, except the OpenSwoole check
Built: `Fake::unique()` numbers string values once random picks run out (50 tries; `desk2`, `ada.smith2@…`), other
types still throw; factories without `$class` return `Pype\Database\Row` (stdClass + ArrayAccess + JsonSerializable,
`toArray()`), `make()` still returns arrays of input data; `SwooleServer::fromApp()` (Console uses it) passes
`app.swoole.settings` through, named options and `--workers` win. Tests in FakeTest, FactoryTest,
SwooleServerTest; GUIDE factories and Swoole paragraphs. Skeleton: `config/app.php` `static_handler` and
`settings`, `.env.example` Swoole lines fixed (OpenSwoole or Swoole, 1 worker per core, SWOOLE_STATIC_HANDLER).
**Still open:** the static-handler stall on real OpenSwoole (only `php8.5-swoole` is installed here; the sury PPA
has no php8.5-openswoole) and the keep-alive bench case.
- `Fake::unique()` must not run out (~500 words): numeric suffix or `numerify` patterns.
- Factories always return the same type: objects (`stdClass` without `$class`); check what the query builder
  returns by default and match it.
- Pass `app.swoole.settings` straight through to the server.
- Check the static-handler fix on real OpenSwoole and add page→css→page on one keep-alive connection to the bench.
- Faker is an **install-time choice** (D19); the built-in `Fake` stays the default.

### D11. Context contracts and `routes:check` (was 11) — **done 2026-10-21, uncommitted**
Built: `Pype\Pipeline\Requires`/`Provides` attributes (repeatable, inherited from parent classes),
`DeclaresContext` (static methods, win over attributes), `Pype\Routing\RouteCheck` (`problems()`, `hints()`,
static `declarations()`), `Application::checkRoutes()` and `globalStages()`, `Router::expandStacks()`,
`php bin/pype routes:check` (exit 1 on problems, prints `new` stage hints), `Auth::USER` placeholder for the
configured user class; declarations on StartSession, VerifyCsrfToken, Validate, Authenticate,
EnsureEmailIsVerified, RequestId; the log viewer routes use class-string outlets; `make:stage` stub mentions the
attributes. `tests/Unit/RouteCheckTest.php`, GUIDE "Checking the order of stages". Skeleton: `FindUser`
provides `User`, `AccountView` requires it, `tests/Feature/RoutesTest.php` asserts no problems.
Not checked: handler and closure parameters (they may come from the container as well as the context).
Stages optionally declare what they need and put: static `requires()` / `provides()` methods
(`DeclaresContext` interface) **or** `#[Requires(...)]` / `#[Provides(...)]` attributes (the method wins).
`Quote::class` is the context key: `put($quote)` stores under `'App\Data\Quote'`, `get(Quote::class)` reads
it. Named entries are declared as strings (`'page'`); conditional puts are trusted as declared.
`php bin/pype routes:check` expands stacks, walks each route's stages with the set of keys known so far
(Route, Request, plus what `web`/`auth` provide) and reports `PrepareQuote needs [Quote]; nothing earlier
provides it`. It also runs as a test (`composer test` fails on a mis-ordered route). Stages without
declarations are skipped. Zero runtime cost.

### D12. Chain-testing helper (was 12) — **done 2026-10-21, uncommitted**
Built: `Pype\Testing\Chain` (`of($stages, $app)`, `with($item, as:)`, `params()`, `run(Request|'GET /path')`) and
`ChainResult` (`context()`, `reachedEnd()`, `status()`, `data()`, `body()`, `response()`, `request()`). Uses the
real Pipeline and `Router::compile()` (container, specs, stacks, outlets) but not the error rendering, so
exceptions reach the test. `tests/Unit/ChainTest.php`, GUIDE "Testing". Skeleton: `tests/Unit/FindUserTest.php`
and a Unit suite in `phpunit.xml`.
Run a list of stages without HTTP with a pre-filled context:
`Chain::of([LoadQuote::class, PrepareQuote::class])->with($user)->run(Request::create('GET', '/quotes/1'))`.

### D13/D14. Dropped / folded
Repositories are an app choice, not a framework concept (stages query with `$db->table()`); dropped. Feature
files, group stacks and group-level context stages (`UseQuotes::class`) are covered by D17 plus the existing
`group()` and `$app->stack()`.

### D15. Per-page outlets
Same as D4. The expenses app will not be converted: it was a test bed and will break as the framework
changes; a new app will be built.

### D16. One `View` outlet, many engines (was 16) — **done 2026-10-21, uncommitted**
Built in `Views` (so `View`, page outlets and mail views all get it): extension picks the engine, order
`.blade.php`, `.latte`, `.php` for extension-free names, explicit extensions allowed; Latte engine built once per
Views (loader resolves names from the views root, like PHP and Blade), BladeOne built per render; compile cache
`app.view_cache` (default storage/views); clear LogicException naming the `composer require` when a package is
missing (not covered by a test: both packages are dev dependencies). `Template::forRequest()`/`locals()` give
Latte/Blade the same `$csrf`/`$old`/`$error`/`$errors`. composer.json: both packages in `require-dev` and
`suggest`. `tests/Unit/TemplateEnginesTest.php` (incl. a Latte form through `View` + `submit()`), GUIDE "Latte or
Blade instead of plain PHP". Skeleton: `config/app.php` `view_cache`, `.gitignore`, CLAUDE.md. Installing an
engine at `create-project` time is D19.
`new View('quotes/show')` picks the engine **from the file extension**, no `LatteView`/`BladeView` classes:
`.latte` → Latte, `.blade.php` → BladeOne, `.php` → plain PHP (check `.blade.php` before `.php`).
Engines load lazily; a missing package throws `Run: composer require latte/latte`. The packages are in
`suggest`. Separate compile cache folder per engine under `storage/views`. Engines can be mixed per route.
Same locals (`$old`, `$error`, `$csrf`) in every engine. Open: with no extension in the name, what happens
when several files match (suggest: fixed order `.blade.php`, `.latte`, `.php`; an explicit extension wins).
Prototype both against one page before locking the adapter API. Beware double escaping with `e()`.

### D17. Load every file in `routes/` (new) — **done 2026-10-21, uncommitted**
Built (skeleton switched too: API now under `/api`, tests updated): `Application::routesIn()`, `Router::names()`, `Application::SCHEDULE` (shared with Console),
`make:routes <name> [--web|--api]`, `tests/Unit/RoutesInTest.php`, GUIDE "Route files".
`$app->routesIn(__DIR__ . '/../routes')` loads `routes/**/*.php`, so adding a file needs no edit in
`bootstrap/app.php`. Layout: feature folders with `web.php` and `api.php` (`routes/quotes/web.php`,
`routes/quotes/api.php`) and the top-level `routes/web.php`, `routes/api.php` still work. **The basename
decides**: `api.php` gets the `/api` prefix and the `api.` route-name prefix (web `quotes.index`, api
`api.quotes.index`); `web.php` and any other name get none. Files starting with `_` are skipped. Load order is
alphabetical by full path (deterministic; check that the router already prefers static over dynamic patterns
for `/quotes/new` vs `/quotes/{id}`). A duplicate route name fails at boot. `bin/pype optimize` caches all
files. The `make:routes quote` stub writes a route group wrapped in `['web']`; nothing is forced.

### D20. `Validate` "prepare" hook — **agreed and done 2026-10-21, uncommitted**
Decided: `prepare(array $input): array` (hook only, no `drop_empty_rows` rule yet); keys kept so errors and
`$old()` name the browser's rows; the request is not changed (old input = as sent); `ValidatedInput` gets the
prepared data, re-numbered only for fields that arrived as a list and lost rows (arrays keyed on purpose keep
their keys). Built in `Validate::handle()`/`renumber()`; `tests/Unit/ValidatePrepareTest.php` (API error key,
handler rows, a form refilled as typed with the error on row 2); GUIDE "Tidy the input first". Skeleton:
CLAUDE.md validation row. Original note:
Forms with rows (`lines[0..n]`) send blank rows. The expenses app drops them in a stage (`DropBlankLines`) before
`Validate`, so the indexes in the errors (`lines.3.quantity`) no longer match the rows the browser shows. Idea: a
`prepare(array $input): array` method a `Validate` subclass can override to clean input before the rules run,
plus a way to keep (or map back) the original indexes so `$old('lines.3.quantity')` and `$error(...)` line up.
Open questions: re-index or keep keys; whether prepared input replaces the request input for later stages.

### D18. `redirect()` helper (new) — **done 2026-10-21, uncommitted** (with D4's `RedirectOutlet` and route names in `RedirectOnSuccess`)
Built: `redirect()` in `helpers.php`; `Response::withFlash()`/`flashes()` (StartSession applies them);
`Pype\Outlet\RedirectOutlet`; `Pype\Session\FlashOnSuccess`; `Urls::target()` (path or route name, filling
`{placeholders}` from the matched route); `RedirectOnSuccess` takes route names; `tests/Unit/RedirectTest.php`;
GUIDE "Redirects". Found while building: outlets wrap every stage, the session included, so an outlet's flash
would be lost after StartSession saved. `RedirectOutlet` therefore leaves a `FlashOnSuccess` in the context
on the way in and StartSession applies it on the way out, using the same success rule (not an error, status
< 300, not a JSON request). Still open: a second outlet listed after `RedirectOutlet` on one route wins, but
the flash is still applied.
A pure function: `return redirect('quotes.index', flash: ['status' => '...']);` from inside a stage short-
circuits the pipeline. It returns a `Response` carrying the flash; the `web` session stage applies it on the
way out, so it touches no session itself (worker-safe).

### D19. Install-time choices (new) — **done 2026-10-21, uncommitted**
Built: `php bin/pype install [--template=php|latte|blade|both] [--faker|--no-faker] [--no-interaction] [--dry-run]`
in the framework Console: asks when STDIN is a terminal (and COMPOSER_NO_INTERACTION isn't set), otherwise
takes only the flags (CI: nothing added); runs `composer require` (COMPOSER_BINARY from composer's scripts, else
`composer`). `tests/Unit/InstallCommandTest.php`; checked by hand through a pseudo-terminal and with a real
`composer require latte/latte` in a scratch copy of the skeleton (a `.latte` view rendered). Skeleton:
`post-create-project-cmd` runs it after `key:generate` and `migrate`; README "Quick start". Note: the local
skeleton resolves `pype/framework` from the path repo as dev-main, which its `^0.8` stable constraint refuses,
so `composer require` fails there until a release (or minimum-stability dev); from Packagist it works.
Faker (`fakerphp/faker`) is maintained (v1.24, not abandoned).
Composer `post-create-project-cmd` runs `php bin/pype install`, which asks which template engine(s)
(plain, Latte, BladeOne: runs the `composer require`) and whether to add Faker. Flags for CI:
`--no-interaction`, `--template=latte`, `--faker`.

## Found by the Helpdesk load test (2026-10-22)

The same Helpdesk app built by fresh AI sessions in pype and Laravel (spec: `../HELPDESK.md` in the
pype folder), load tested on 2 vCPU / 4 GB with 500k tickets (`~/Github/helpdesk/bench/RESULTS.md`).

### H1. `count()` leaves out LEFT JOINs on the joined id — **done, 0.10.0**
The AI's list query LEFT JOINed `users` for the assignee's name and `paginate()` counted through it:
2.7 ms per count vs 0.24 ms, ~85% of the list's cost. `count()` now drops a `leftJoin()` whose ON is
`<joined alias>.id = ...` when no where, having or other join reads that alias (assumes `id` is the
primary key; `count:` still overrides). Same app, no app change: 700 req/s went from 145% CPU to 32%,
and 3,000 req/s passes (was ~600).

### H2. Swoole and SQLite settings from `.env` — **done, 0.10.0**
`env_prefixed('SWOOLE_')` / `Env::prefixed()`: every `SWOOLE_*` variable becomes a server setting
(typed), spread last into the skeleton's `app.swoole`, which is now a flat list of server settings
(`workers`/`static_handler` short names and the old nested `settings` still read). Same for SQLite:
`SQLITE_*` → pragmas (names and values checked, `null` unsets a default). `max_request` default
10,000 → 100,000 (it recycled a worker every ~14 s at 700 req/s).

### H3. Server mode — **done, 0.10.0**
`app.swoole.mode` / `SWOOLE_MODE`: `base` (default, the owner's choice) or `process`. In base mode a
recycled worker drops its keep-alive connections (nginx retries GETs; a POST would 502). Forced
recycling every 20k requests at 2,000 req/s: base 20 resets, process 0, same CPU. Documented in GUIDE.

### H4. Hydrator casts by parameter type — **done, 0.10.0**
`Hydrator` (so `->as()` and factories) casts to the constructor's declared type when the database hands
back another: `bool` (SQLite 0/1), `int`/`float` (numeric strings), `string` (numbers),
`DateTimeImmutable`/`DateTimeInterface`, backed enums. Union/mixed/untyped: as is. Casts are worked
out once per class.

### H5. JSON outlets: status and pages — **done, 0.10.0**
- `JsonOutlet::with(status: 201)` / `protected ?int $status = 201;`: on success only.
- `data()` may return a `Responsable` (a `Page`/`SimplePage`): its data and meta are used, so an
  outlet reading the page from the context keeps `meta.pagination`. `Page::map()` / `SimplePage::map()`
  shape the items.
- `meta.pagination` now has `next` and `previous` links (they keep the filters).

### H6. Grouped conditions and raw ordering — **done, 0.10.0**
`where(fn (QueryBuilder $q) => ...)` / `orWhere(fn ...)` wrap the closure's conditions in parentheses
(nestable; an empty group adds nothing). `orderByRaw($sql, $bindings)`; its bindings come last and
are dropped with the order by `count()`/`exists()`.

### H7. Test helpers — **done, 0.10.0**
`TestClient::withIp()` (shares the cookie jar; `Request::create(..., ip:)`); a `null` file or row in
`post()`/`submit()` files is an empty input, dropped like `fromGlobals()` drops `UPLOAD_ERR_NO_FILE`,
and the form stays multipart; `Schedule::load($app)` (the console uses it too) and
`Schedule::task('command' | Job::class | 'name')`.

### H8. Skeleton — **done, 0.10.0**
AGENTS.md: index every column a list filters or sorts by (the as-built pype Helpdesk sorted 500k tickets
by an unindexed `updated_at`: half the throughput). `phpunit.xml` forces `APP_URL` (the Helpdesk tests
broke when `.env` moved the port).

## Tier 2: speed and growing apps

- Multiple database connections.
- Thin HTTP client (curl). Translations (`intl` `MessageFormatter`).

## Tier 3: later

- Redis stores (`phpredis`) for sessions, cache, rate limits.
- FrankenPHP and RoadRunner adapters (same shape as `Server\SwooleServer`), only if wanted.
- Minimal lifecycle hooks/events.
- Swappable template renderer: keep plain-PHP views as the default, allow Latte or BladeOne as an
  opt-in outlet. Measured per render (2026-10-15): plain PHP 10 µs, Latte 17 µs, BladeOne 32 µs
  with 0 dependencies, Laravel Blade 26 µs but 26 packages.
- Class-based views with richer behaviour. The owner wants this designed properly, explicitly
  **not** a cheap Livewire clone.

## OpenSwoole follow-ups

- **Static handler stall (found by the expenses app, 2026-10-20, fixed, uncommitted).** With Swoole 6.2.3
  (`php8.5-swoole` from the sury PPA), Swoole's native static handler (`enable_static_handler => true`)
  made a browser's clicks take 300-500 ms instead of ~2 ms. After the first static file on a keep-alive
  connection every later response on it waited ~208 ms (TCP_CORK's 200 ms timeout, it seems; not
  fixed by `open_tcp_nodelay`). Reproduced with Chrome over CDP and with a raw socket; the benchmark
  missed it because it only requested pages. Fix: `enable_static_handler` is now off by default and
  `SwooleServer::serveStatic()` serves `public/` files itself; `app.swoole.static_handler` turns the
  native handler back on. Not tried: real OpenSwoole (no PHP 8.5 package here), so unknown if it is
  affected. Worth a regression check in the bench: request a page, then a css file, then a page again
  on one connection and compare times.

- Dev reload: `serve --server=swoole --watch` restarts workers when files change. Today code
  changes need a restart.
- Production hardening: run workers as an unprivileged user (OpenSwoole's `user`/`group`
  settings), document a systemd unit and putting nginx in front for TLS.

## Benchmark follow-ups

- Find OpenSwoole's real ceiling (estimated ~10,000 users). Needs more than the 2000 seeded
  accounts: raise `--users` in the bench Dockerfile and `SEEDED_USERS` in `k6/users.js`.
- **Re-run the database comparison** (SQLite vs Postgres vs MariaDB on the expenses bench): the
  2026-10-16 numbers were all SQLite (dash ignored `env.sh`'s argument; fixed 2026-10-20).
- Benchmark real MySQL 8 (the bench uses MariaDB because Debian has no MySQL 8 package), e.g.
  with the database in a second container sharing the CPU limit.
- Run on a quiet machine or a real 2 vCPU VPS. The desktop used so far makes p99 noisy.

## Build or use a package?

Reviewed 2026-10-19. The rule: **our own code on the per-request path**, where speed lives and
where pype's design differs (stage lists per route, outlets, worker safety). **Packages** where
the problem is security-sensitive, dev-only, or an interop interface.

| Component | Decision | Why |
|---|---|---|
| Pipeline, stages, outlets | ours | pype's core idea; PSR-15 is one fixed middleware chain, not a stage list per route |
| Router | ours, with fast matching (done) | packages only map URL → handler; pype's routes carry stages, groups, stacks and outlets |
| Container | ours | autowiring is all pype needs; PHP-DI is much bigger and needs compiling to be fast |
| Request / Response | ours | PSR-7 copies objects on every change; HttpFoundation is a large dependency. Optional PSR-7 converters later for interop (RoadRunner) |
| Sessions | ours | PHP's native `session_*` uses global state and sends its own headers; breaks OpenSwoole |
| Query builder | ours | PDO directly is as fast as it gets; an ORM conflicts with the principles |
| Migrations | ours (done) | runs only at deploy, so a package would cost nothing per request, but Phinx/Doctrine add many dependencies to every app. Revisit only for mature rollback/seeding |
| Validation | ours, extend | built on `filter_var`; alternatives are far bigger for the same rules |
| `.env` | ours | 61 lines; loading `.env` + config takes ~11 µs, so no config cache is needed (measured) |
| Templates | plain PHP | fastest; existing engines may come as an opt-in outlet (Tier 3) |
| Console | ours | CLI-only so no request cost either way; symfony/console would add packages to every app |
| Mail | **PHPMailer** behind our `Transport` (done) | SMTP/MIME are security-sensitive; PHPMailer is 1 package and ~0.6 MB vs 12 new packages for symfony/mailer (measured 2026-10-19). A symfony driver can still be added as another Transport |
| HTTP client | thin curl wrapper; point to symfony/http-client or Guzzle for more | same reason |
| Dev error page | ours (done) | measured against whoops: same zero production cost, and only ours can show pype's route, stages and the request's log lines |
| Logger, cache | ours, implementing **PSR-3** (done) / **PSR-16** | interface packages only, so Monolog etc. plug in |
| Encryption | native `sodium_*` | no package needed |

## Won't do (conflicts with the principles)

- An ORM with magic properties or lazy loading (query builder + plain data classes instead).
- Facades / static proxies; a template language of our own (plain PHP stays the default; an existing engine may be an opt-in outlet, see Tier 3); annotation/attribute routing.
- Hidden global or static state.
