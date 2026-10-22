# pype/framework: notes for working on the framework

pype is a small, pipeline-first PHP framework (PHP 8.2+). Each route is its own pipeline of
**stages**; the **outlet** at the end decides how the result is written (JSON, HTML, CSV, file).
The starter app is `pype/pype` (github.com/Valourite/pype-skeleton); apps are created with
`composer create-project pype/pype`.

`docs/GUIDE.md` is the app developer's guide (every built-in with examples; apps read it from
`vendor/`, and their AGENTS.md (imported by CLAUDE.md) points there): **update it whenever a feature
changes**. The app's AGENTS.md is copied once at `create-project` and never updated, so keep detail in
the guide and only stable rules there.
Read `docs/ARCHITECTURE.md` before changing core behaviour, `docs/ROADMAP.md` for what's planned,
`docs/BENCHMARKS.md` for performance numbers and how to measure.

## Where things stand (keep this current)

- **Released:** 0.10.1 (2026-10-22): `SWOOLE_MODE` defaults to `process` (overloaded, base mode failed 24%
  of requests where process queued them).
  0.10.0 (2026-10-22): the Helpdesk load test's fixes (ROADMAP "Found by the Helpdesk
  load test", H1-H8): `count()` leaves out LEFT JOINs on the joined id, every `SWOOLE_*`/`SQLITE_*` env
  variable is a setting (`env_prefixed()`), `SWOOLE_MODE` base|process, `max_request` 100,000, the
  hydrator casts to declared types, `JsonOutlet::with(status:)` and `Page::map()` from `data()`, grouped
  `where(fn ...)` and `orderByRaw()`, `withIp()`, empty file inputs, `Schedule::load()`.
  0.9.1 (2026-10-21): `JsonOutlet::data()` (JSON outlets read the context, like page outlets),
  pype's dev pages use stages, docs drop "handlers" (every step is a stage); skeleton has no app/Handlers.
  0.9.0 (2026-10-21): the work list from the expenses app (ROADMAP "Decided with the
  owner": routesIn, stage()/::with(), Requires/Provides + routes:check, pagination links, redirects,
  template locals, page outlets, Latte/Blade by extension, dev mailbox, test helpers, Chain, resource
  routes, per-user throttle and scoped unique, Validate::prepare, FK indexes, install command); skeleton
  has AGENTS.md. Still open: roles, the static-handler check on real OpenSwoole.
  0.8.1 (docs: `docs/GUIDE.md`), 0.8.0 (framework + skeleton on Packagist): factories + seeders, and the
  $12-server bench round (query builder memo, container-aware 1x workers, JWT, configurable
  errors, TrimStrings, insertReturning, URL-decoded params). 0.7.0: migration rollback + the
  Postgres insert fix. 0.6.0: queue + scheduler. 0.5.0: validation rules, mail, password
  reset/verification, encryption, remember me, cache.
- **Next:** the owner will have a fresh AI build the same app (a helpdesk) in Laravel and in pype, to
  compare. Open framework items: roles (parked), the OpenSwoole static-handler check.
- **Example app:** `../expenses` is an old test bed (local git only, never give it a remote); it is not
  kept up to date with the framework. Its bench is in `bench/` (see `docs/BENCHMARKS.md`).

## How the owner works

- Plans bigger rounds in plan mode first; answers design questions before code is written.
- Commits and pushes only when asked; releases are explicit ("release 0.x").
- Wants measured numbers (benchmarks) for performance claims, and honest caveats.
- Prefers short benchmark runs (≤ ~10 minutes); the dev machine is a busy desktop, so p99 is noisy.

## Principles (the owner's, not negotiable without asking)

1. **It should feel like writing PHP.** Plain classes, closures, arrays, `match`, typed params.
   No magic properties, facades/static proxies, template languages, annotation routing or ORM
   lazy loading. A PHP developer should be able to read any stage and know what it does.
2. **Useful built-ins, so apps don't write boilerplate** (auth, sessions, CSRF, validation,
   uploads, rate limits, migrations...). But every built-in is optional and swappable.
3. **Speed via native PHP.** Prefer native functions (`password_hash`, `hash_hmac`, `finfo`,
   `filter_var`, `random_bytes`, `sodium_*`, `apcu_*`, PDO) over userland reimplementations.
4. **Nothing costs anything unless it's used.** A feature that isn't configured must not be
   loaded, connect, or run per request (e.g. `SwooleServer`, the schema builder, rate-limit
   drivers are only autoloaded when picked). Measure when in doubt (`docs/BENCHMARKS.md`).
5. **No runtime dependencies** except PSR interface packages (`psr/container`, `psr/log`,
   `psr/simple-cache`). Security-sensitive protocols (mail) use a package behind a pype
   interface, as an optional `suggest`; dev-only tools (e.g. whoops) go in `require-dev`. See
   "Build or use a package?" in `docs/ROADMAP.md`.
6. **Worker-safe.** Apps may run under OpenSwoole, where one `Application` serves many
   requests. Per-request state lives on the `Request` (and its `Context`), never in services,
   singletons or statics. `tests/Unit/WorkerSafetyTest.php` guards this.
7. Stages are configured **objects** (`Throttle::with(5, 60)`, or `new Throttle(5, 60)`), never `'name:arg'` strings.
8. Ask the owner before adding heavy features or new concepts. Don't commit or push unless asked.

## Layout

| Path | What |
|---|---|
| `src/Application.php` | lifecycle: config, providers, global stages, `handle()`, error rendering |
| `src/Pipeline/` | `Stage` interface, `Pipeline`, `Invoker` (fills closure/invokable params by type and name) |
| `src/Routing/` | `Router` (groups, named stacks, outlet hoisting in `compile()`, fast matching + `optimize` cache), `Route` |
| `src/Http/` | `Request`, `Response`, `Context`, `Cookie`, `UploadedFile`, `Responsable` |
| `src/Outlet/` | `Outlet` (abstract stage), `JsonOutlet`, `FileOutlet` |
| `src/View/` | plain-PHP templates: `View`, `ViewOutlet` (class-based views), `Template`, `Views` |
| `src/Validation/` | `Validator` (rules), `Validate` stage, `ValidatedInput` |
| `src/Auth/` | `Auth` (users, passwords, API tokens), `Authenticate` stage, guards |
| `src/Session/` | session stages (`'web'` stack), `Session`, stores (database/file/array) |
| `src/Database/` | `Connection` (PDO), `QueryBuilder`, `Page`, `Migrator`, `Schema/` (builder), `Factory`/`Fake`/`Seeder`/`Hydrator` |
| `src/Cache/` | `Cache` (PSR-16 + remember/add/increment, `lock()`, `stale()`, `group()`), `Store` + file/apcu/database/array/null, `Lock`, `CacheProvider` |
| `src/Queue/` | `Queue` + database/sync/array drivers, `QueuedJob`, `Worker` (`queue:work`), `FailedJobs`, `QueueProvider` |
| `src/Schedule/` | `Schedule` (routes/schedule.php), `Task` (frequency helpers, locks), `Cron` (own parser) |
| `src/RateLimit/` | `RateLimiter` + database/apcu/array drivers (used by `Stages\Throttle`) |
| `src/Stages/` | built-in stages: Cors, JsonBody, MethodOverride, RequestId, Throttle |
| `src/Log/` | `Logger` (PSR-3, JSON lines), `LogReader`, `LogRequests`, `LogReporter`, `LogViewer` + `views/viewer.php`, `LogProvider` |
| `src/Security/` | `Encrypter` (libsodium) |
| `src/Mail/` | `Message`, `Mail`, `Mailer`, `Transport` + PHPMailer/log/array/null transports, `MailProvider` |
| `src/Debug/` | `ErrorPage` (dev error page outlet) + `views/error.php` |
| `src/Server/` | `SwooleServer` (OpenSwoole adapter, `APP_SERVER=swoole`) |
| `src/Console/` | `bin/pype` commands and `make:*` stubs |
| `src/Testing/` | `TestClient` (in-process requests with a cookie jar), `TestResponse` |
| `tests/Unit/` | PHPUnit tests; `tests/Support/TestDatabase.php` picks the database from env |

Providers (`DatabaseProvider`, `AuthProvider`, `SessionProvider`, `ViewProvider`) bind services
from config and register stacks. Pattern for swappable backends: an interface + drivers picked
by a config string, or a class name (see `SessionProvider`, `DatabaseProvider` rate limits).

## Commands

```bash
composer install
vendor/bin/phpunit                 # SQLite in memory, ~3 s
bin/test-databases.sh              # same suite on SQLite, MySQL 8.4, Postgres 17 (Docker), APCu on
bin/bench-cache.sh                 # cache store micro-benchmark (Docker image from test-databases.sh)
bin/bench-queue.sh                 # queue throughput, 1 and 4 workers, on the three databases
DBS=pgsql bin/test-databases.sh    # one database
PHP=8.2 DBS=sqlite bin/test-databases.sh   # another PHP version (CI runs 8.2-8.4)
```

Database-touching tests must use `TestDatabase::fresh()` / `withAuthTables()` and portable SQL
(or the schema builder) so they pass on all three databases.

To work on the framework inside an app, apps use a Composer path repository: globally
`composer config --global repositories.pype-framework path ~/Github/pype/framework`, which
symlinks this checkout into the app's `vendor/`.

## Traps (each one bit us once)

- **Outlets render after the pipeline returns.** Anything a template needs from the session must
  exist before then: the CSRF token is created eagerly in `StartSession`, and flash ageing is only
  applied to the stored copy (`Session::dataToStore()`), not the live session.
- **Outlets are hoisted** to the front of a stage list by `Router::compile()` so they wrap
  everything; the innermost wins (route > group > global > app default).
- **Route matching** (see ARCHITECTURE "Route matching"): static paths beat `{param}` patterns;
  a Router's first lookup scans, later ones use compiled regexes; overlapping patterns always go
  through the scan so answers never depend on which path was taken. Keep `RouterTest` green.
- `url()` inside a routes file runs while routes are still being registered, so it only finds
  routes named **above** it. Inside closures (`RedirectOnSuccess(fn () => url(...))`) it runs at
  request time and finds everything.
- `Urls::current()` (behind `url()`) is the one intended process-wide reference: app-level data
  only. Don't add others; per-request data goes on the Request.
- The logger finds the request id through the request bound in the container: correct because
  each process handles one request at a time. If coroutines are ever enabled, this must change.
- The log viewer and error page are framework templates (`src/Log/views`, `src/Debug/views`):
  plain PHP + inline CSS/JS, no external requests. Insert log text with `textContent` / `$h()`.
  Never show secrets on the error page (see `ErrorPage::SECRET_*`), and keep the viewer
  unregistered in production unless guarded by stages.
- App test suites set a fixed test `APP_KEY` (signed URLs) and `MAIL_DRIVER=array` in
  phpunit.xml; tests read mail with `$app->container->get(Pype\Mail\Transport::class)->messages()`.
- App test suites set `LOG_PATH=storage/logs/testing` and `LOG_REQUESTS=false` (phpunit.xml) so
  test runs don't fill the app's log.
- `Validate` keeps `$rules`/`$messages` as plain properties with defaults: apps subclass it with
  their own constructor and never call parent's. Rule objects in routes are shared by all
  requests, so `DatabaseRule::using()` returns a copy instead of storing the connection.
- Deferred work (`Mailer::later()`) goes on `AfterResponse` in the request context and runs in
  `Application::terminate()`. Anything that serves requests must call terminate() after sending
  the response (run(), SwooleServer, TestClient do). PHP's built-in dev server has no
  fastcgi_finish_request(), so there the client still waits; PHP-FPM and OpenSwoole don't.
- Exceptions are caught per stage inside the pipeline, so outer stages always see a `Response`.
- **SQLite transactions use `BEGIN IMMEDIATE`** (`Connection::sqliteTransaction`): a plain
  BEGIN fails with "database is locked" when it upgrades to a write under concurrency. PDO
  before PHP 8.4 doesn't see a manual BEGIN, hence the manual flag.
- Portability: MySQL rejects non-aggregated columns not in GROUP BY; MySQL/Postgres return raw
  `SUM()` as strings; Postgres can't use select aliases in HAVING; MySQL ignores inline
  `REFERENCES` (the schema builder emits table-level foreign keys).
- **Opcache won't cache a file changed after the current request started**
  (`opcache.file_update_protection`). `FileStore` hits come from opcache under PHP-FPM from the
  next request on, but never in a CLI run (measured) and, by the same rule, not in an OpenSwoole
  worker (one long request; not measured) unless `opcache.file_update_protection=0`. Benchmarks of the file store must set it.
- `Cache::group()` objects keep the version they were made with; the root `Cache` singleton has
  no mutable state. Don't add any (worker safety), and don't store group objects in services.
- APCu's `apcu_delete()` returns false for a missing key; PSR-16 `delete()` must still return true.
- `routes/schedule.php` must never be loaded per request (it's only `Console::schedule()`):
  `ScheduleTest` checks a web request doesn't run it.
- Queue workers are long-running like OpenSwoole: no per-job state in services. Forked test
  workers need their **own** `Connection` (never share the parent's PDO across `pcntl_fork`),
  and SQLite tests across processes need a file database (`:memory:` is per connection).
- `schedule:run --time=...` is read in PHP's timezone; tasks may have their own. Pass a zone
  (`--time="2026-10-21 00:05 Africa/Johannesburg"`) when testing a task by hand.
- The Docker test image is tagged `pype-test-php:<php>-2`; bump the suffix whenever its
  Dockerfile in `bin/test-databases.sh` changes (otherwise the cached image is reused).
- **Postgres: never call PDO `lastInsertId()` blindly.** It is `LASTVAL()`, which fails on a
  table without a sequence and aborts the open transaction. `QueryBuilder::insert()` uses
  `RETURNING *` on Postgres. Tests that insert into a serial table first hide this (they did).
- **MySQL indexes foreign key columns itself.** An explicit index on such a column replaces
  MySQL's and then can't be dropped ("needed in a foreign key constraint"), so a `down()` that
  drops it fails there. Round-trip migrations (`migrate:fresh` → `migrate:rollback --step=100`
  → `migrate`) on all three databases before trusting a `down()`.
- **JWT: the algorithm comes from the configured key, never from the token's header.** Keep it
  that way (`Jwt::verify()`), or `alg: none` / RS-to-HS key confusion become possible.
- `QueryBuilder::$quoted` is a process-wide memo of quoted identifiers: keep the cap, and only
  ever put identifiers in it (never values).
- The twelve bench (`../twelve-bench/`) builds its pype image from this checkout; rebuild
  (`build.sh`) after framework changes. The Laravel image there is the comparison baseline.
- Sessions are only written when their data changed or every `session.touch` seconds.
- Load testing with k6: needs `noCookiesReset` + `redirects: 0` (or browser users silently log
  out), and a 60 s `gracefulStop` (or requests cut off at the end show up as timeouts).

## Releasing

Semver, pre-1.0: breaking changes bump the minor (0.2 → 0.3). Steps: update the README and
`docs/`, run the full suite on all databases, commit, `git tag -a vX.Y.Z`, then **push the
framework first** (`git push origin main --tags`), wait for Packagist
(https://repo.packagist.org/p2/pype/framework.json) to list it, then bump and push the skeleton.
Verify with a fresh `composer create-project pype/pype` using an empty `COMPOSER_HOME` (so the
global path repository doesn't shadow Packagist).

## Related

- Skeleton (starter app): `../skeleton`, github.com/Valourite/pype-skeleton.
- Example app + load-test bench: `../expenses` ("Spendwise"), see `docs/BENCHMARKS.md`.
