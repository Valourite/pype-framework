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
- **0.4.0**: logging (PSR-3 `Logger`, JSON lines, request log, forwarding to stderr/syslog/any PSR-3 logger), the live log viewer, the
  development error page, `HtmlOutlet`.

## Tier 1: almost every app needs these

| Feature | Built on | Notes |
|---|---|---|
| More validation rules: `regex`, `unique`/`exists`, `before`/`after`, `items.*`, closure rules, custom messages | `preg_match`, `filter_var`, query builder | Most common hand-written app code |
| Cache: `remember($key, $ttl, fn)`; APCu / file / array | `apcu_*`, `var_export` + opcache | Same driver pattern as sessions and rate limits. Implement **PSR-16** (`psr/simple-cache`, interfaces only) |
| Encryption; "remember me" cookie guard | `sodium_crypto_secretbox` | Uses `APP_KEY` (exists since 0.3) |
| Mail: `Mailer` interface; log driver + an SMTP driver | **a package**: PHPMailer (0 deps) or symfony/mailer | Don't write SMTP/MIME ourselves: security-sensitive and easy to get wrong. pype owns the small interface, the driver wraps the package (optional, composer `suggest`) |
| Password reset; email verification | the above | |

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
| Mail | **package** behind our interface | SMTP/MIME are security-sensitive |
| HTTP client | thin curl wrapper; point to symfony/http-client or Guzzle for more | same reason |
| Dev error page | ours (done) | measured against whoops: same zero production cost, and only ours can show pype's route, stages and the request's log lines |
| Logger, cache | ours, implementing **PSR-3** (done) / **PSR-16** | interface packages only, so Monolog etc. plug in |
| Encryption | native `sodium_*` | no package needed |

## Won't do (conflicts with the principles)

- An ORM with magic properties or lazy loading (query builder + plain data classes instead).
- Facades / static proxies; a template language of our own (plain PHP stays the default; an existing engine may be an opt-in outlet, see Tier 3); annotation/attribute routing.
- Hidden global or static state.
