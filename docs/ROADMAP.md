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
- **Unreleased**: validation rules: `unique`/`exists` (+ `Unique`/`Exists` objects, `{param}`
  from the route), dates (`before`/`after`...), `items.*` wildcards and dot paths, `required_if`/
  `required_with`, `regex`, `same`/`different`, `between`, `digits`, `alpha*`, `uuid`, `ip`, `json`,
  `accepted`, `not_in`; closures get `($value, $field, $data)`; `Rule` interface; custom messages.
- **Unreleased**: `Security\Encrypter` (libsodium, APP_KEY, key rotation); "remember me"
  (`Auth\RememberTokens`, a table per device, selector + validator, rotation, replay detection;
  `SessionGuard::login(..., remember: true)`).
- **Unreleased**: password reset core (`Auth\PasswordResets`), email verification
  (`Auth::markEmailVerified/isEmailVerified/emailHash`, `EnsureEmailIsVerified`); the flows,
  emails and pages live in the skeleton.
- **Unreleased**: mail (`Message`, `Mail` classes, `Mailer::send()` / `later()`, PHPMailer
  SMTP/sendmail/mail, log/array/null transports) and `AfterResponse` + `Application::terminate()`.
- **0.4.0**: logging (PSR-3 `Logger`, JSON lines, request log, forwarding to stderr/syslog/any PSR-3 logger), the live log viewer, the
  development error page, `HtmlOutlet`.

## Next: cache (agreed 2026-10-19, not started)

The last Tier 1 item. Scope and decisions agreed with the owner; plan it in plan mode before building.

**Core** (`Pype\Cache`, implements **PSR-16**: add `psr/simple-cache` ^3, interfaces only):
- `get`, `set($key, $value, $ttl)`, `delete`, `has`, `getMultiple`/`setMultiple`/`deleteMultiple` (PSR-16)
- `remember($key, $ttl, fn)`, `add($key, $value, $ttl)` (only if missing, atomic), `increment`/`decrement`,
  `forever`, `pull`; `clear()` removes only pype's keys (a configurable prefix)
- `CacheProvider` + `config/cache.php` (`CACHE_DRIVER`, prefix, path, table); `bin/pype cache:clear`

**Drivers:**

| Driver | Notes |
|---|---|
| `file` (**default**) | one PHP file per key written so opcache serves reads (`var_export`), atomic tmp+rename writes, expiry stored in the file. Decide how to store objects (var_export can't do readonly data classes without `__set_state`; maybe `serialize` for those, the cache dir being as trusted as code) |
| `apcu` (**recommended in the docs**) | `apcu_fetch/store/add/inc/delete`, `APCUIterator` over the prefix for clear. ~60 lines, same pattern as `RateLimit\ApcuRateLimiter` |
| `database` | `cache` table (key PK, value, expires_at), one atomic upsert per write on SQLite/MySQL/Postgres (same SQL as `DatabaseRateLimiter`); skeleton migration |
| `array` / `null` | tests / off |

**APCu caveats to document** (the owner asked for these):
1. The CLI has its own APCu memory: under PHP-FPM, `bin/pype cache:clear` can't clear the site's cache. For apcu it must say so instead of pretending; clear by reloading FPM (deploys do). OpenSwoole: restart.
2. Per server (file is too); use `database` (or Redis later) to share across servers.
3. `apc.shm_size` (32 MB default): full = old entries evicted.
4. Install `pecl install apcu` / `apt install php-apcu`; `apc.enable_cli=1` for CLI use; missing extension = clear error.

**Extras in the same round:**
1. **Locks:** `$cache->lock('send-invoices', 60)->run(fn () => ...)`, on atomic `add()`, every driver.
2. **Background refresh:** `$cache->stale($key, fresh: 60, stale: 600, fn)`: fresh → cached value; stale → old value
   now and recompute after the response via `Http\AfterResponse` (like `Mailer::later()`), guarded by a lock so only
   one process recomputes.
3. **Groups:** `$cache->group("user:{$id}")->remember(...)` / `->flush()`, a version number per group (flush = one
   increment, old keys just stop being read).

**Separate later round:** `new CacheResponse(ttl: 60)` whole-page caching stage (guests only, 200s, never with
cookies/sessions, keyed by path + query + format) with its own benchmark.

**Left out on purpose:** query-builder `->remember()` (couples DB and cache); cache events/stats.

Verify like previous rounds: tests per driver (DB driver via `bin/test-databases.sh`, apcu where the extension is
on), a per-driver get/set benchmark, worker-safety (no request state in the cache service), docs in ARCHITECTURE,
README (framework + skeleton), ROADMAP, CLAUDE.md.

## Tier 2: speed and growing apps

- Database-backed job queue + `queue:work` (`pcntl`); `schedule:run` for cron.
- Seeders and test factories; migration rollback (`down()`); multiple database connections.
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

- Dev reload: `serve --server=swoole --watch` restarts workers when files change. Today code
  changes need a restart.
- Production hardening: run workers as an unprivileged user (OpenSwoole's `user`/`group`
  settings), document a systemd unit and putting nginx in front for TLS.

## Benchmark follow-ups

- Find OpenSwoole's real ceiling (estimated ~10,000 users). Needs more than the 2000 seeded
  accounts: raise `--users` in the bench Dockerfile and `SEEDED_USERS` in `k6/users.js`.
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
