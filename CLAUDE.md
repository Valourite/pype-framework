# pype/framework: notes for working on the framework

pype is a small, pipeline-first PHP framework (PHP 8.2+). Each route is its own pipeline of
**stages**; the **outlet** at the end decides how the result is written (JSON, HTML, CSV, file).
The starter app is `pype/pype` (github.com/Valourite/pype-skeleton); apps are created with
`composer create-project pype/pype`.

Read `docs/ARCHITECTURE.md` before changing core behaviour, `docs/ROADMAP.md` for what's planned,
`docs/BENCHMARKS.md` for performance numbers and how to measure.

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
5. **No runtime dependencies** except PSR interface packages (`psr/container` today; `psr/log` and
   `psr/simple-cache` planned). Security-sensitive protocols (mail) use a package behind a pype
   interface, as an optional `suggest`; dev-only tools (e.g. whoops) go in `require-dev`. See
   "Build or use a package?" in `docs/ROADMAP.md`.
6. **Worker-safe.** Apps may run under OpenSwoole, where one `Application` serves many
   requests. Per-request state lives on the `Request` (and its `Context`), never in services,
   singletons or statics. `tests/Unit/WorkerSafetyTest.php` guards this.
7. Stages are configured **objects** (`new Throttle(5, 60)`), never `'name:arg'` strings.
8. Ask the owner before adding heavy features or new concepts. Don't commit or push unless asked.

## Layout

| Path | What |
|---|---|
| `src/Application.php` | lifecycle: config, providers, global stages, `handle()`, error rendering |
| `src/Pipeline/` | `Stage` interface, `Pipeline`, `Invoker` (fills closure/invokable params by type and name) |
| `src/Routing/` | `Router` (groups, named stacks, outlet hoisting in `compile()`), `Route` |
| `src/Http/` | `Request`, `Response`, `Context`, `Cookie`, `UploadedFile`, `Responsable` |
| `src/Outlet/` | `Outlet` (abstract stage), `JsonOutlet`, `FileOutlet` |
| `src/View/` | plain-PHP templates: `View`, `ViewOutlet` (class-based views), `Template`, `Views` |
| `src/Validation/` | `Validator` (rules), `Validate` stage, `ValidatedInput` |
| `src/Auth/` | `Auth` (users, passwords, API tokens), `Authenticate` stage, guards |
| `src/Session/` | session stages (`'web'` stack), `Session`, stores (database/file/array) |
| `src/Database/` | `Connection` (PDO), `QueryBuilder`, `Page`, `Migrator`, `Schema/` (builder) |
| `src/RateLimit/` | `RateLimiter` + database/apcu/array drivers (used by `Stages\Throttle`) |
| `src/Stages/` | built-in stages: Cors, JsonBody, MethodOverride, RequestId, Throttle |
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
bin/test-databases.sh              # same suite on SQLite, MySQL 8.4, Postgres 17 (Docker)
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
- Exceptions are caught per stage inside the pipeline, so outer stages always see a `Response`.
- **SQLite transactions use `BEGIN IMMEDIATE`** (`Connection::sqliteTransaction`): a plain
  BEGIN fails with "database is locked" when it upgrades to a write under concurrency. PDO
  before PHP 8.4 doesn't see a manual BEGIN, hence the manual flag.
- Portability: MySQL rejects non-aggregated columns not in GROUP BY; MySQL/Postgres return raw
  `SUM()` as strings; Postgres can't use select aliases in HAVING; MySQL ignores inline
  `REFERENCES` (the schema builder emits table-level foreign keys).
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
