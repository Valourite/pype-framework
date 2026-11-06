# Roadmap

What's planned, roughly in order. Every item must fit the principles in `../CLAUDE.md`: feel like
PHP, build on native functions, cost nothing unless used. Discuss with the owner before starting
anything big. Finished rounds are summarised in the changelog at the end; the details are in git history.

## Open

- **Security review findings (2026-11-02), fix first:** High and Medium (5) in the private advisory
  GHSA-5gjc-8cfw-fcf8 (draft; keep details out of public files until fixed), Low (7) as GitHub
  issues #1-#7 (label `security`).
- Small open questions from earlier rounds: a `View` with no extension in the template name (which
  engine?); `Validate::prepare()` re-indexing rows vs keeping keys, and whether prepared input
  replaces the request input for later stages; a second outlet listed after `RedirectOutlet` wins but
  the flash is still applied; test assertion helpers (there are none beyond the test client).

## Next: not decided

Pick the next round from the open items, Tier 2 and the follow-ups below with the owner.

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

## Tier 2: speed and growing apps

- Multiple database connections.
- Thin HTTP client (curl). Translations (`intl` `MessageFormatter`).
- Keyset paging (`?after=<id>`) for very deep pages, where `OFFSET` is the cost.
- `bin/pype optimize` caching whole stage lists (stage specs are plain data), and lazy stage objects
  (a request builds only the stages its route runs).

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

- **Static-handler check.** Swoole 6.2.3's native static handler (`enable_static_handler`) left a
  keep-alive connection corked: after one static file every later response on it waited ~200 ms. pype
  turns it off and serves `public/` itself (`SwooleServer::serveStatic()`). Not checked: whether
  OpenSwoole's native handler has the same stall. Test: page, css, page on one connection.

- Dev reload: `serve --server=swoole --watch` restarts workers when files change. Today code
  changes need a restart.
- Production hardening: run workers as an unprivileged user (OpenSwoole's `user`/`group`
  settings), document a systemd unit and putting nginx in front for TLS.

## Benchmark follow-ups

- **The $12 challenge:** #335 (0.12) scored 36,000 users, CPU-bound at 246 µs/request on the scoring
  box (~5x the local cost; BENCHMARKS). A follow-up PR runs 0.13.1. What's left per request is mostly
  SQLite and OpenSwoole; the app rebuilds its query each request (~3 µs locally).
- **Re-run the database comparison** (SQLite vs Postgres vs MariaDB on the expenses bench): the
  2026-10-16 numbers were all SQLite (dash ignored `env.sh`'s argument; fixed 2026-10-20).
- Benchmark real MySQL 8 (the bench uses MariaDB because Debian has no MySQL 8 package), e.g.
  with the database in a second container sharing the CPU limit.

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
| JWT | **lcobucci/jwt** behind `Auth\Jwt` (2026-11-02, optional) | signatures and claim checks are security-sensitive; instance-based (no static clock/leeway, unlike firebase/php-jwt) |
| Cron expressions | **dragonmantank/cron-expression** behind `Schedule\Cron` (2026-11-02) | a widely used parser (Laravel's) with no dependencies; date and DST edge cases are easy to get wrong in our own. Only the scheduler loads it |

## Won't do (conflicts with the principles)

- An ORM with magic properties or lazy loading (query builder + plain data classes instead).
- Facades / static proxies; a template language of our own (plain PHP stays the default; an existing engine may be an opt-in outlet, see Tier 3); annotation/attribute routing.
- Hidden global or static state.

## Changelog

- **0.13.1** (2026-11-06): faster per request, from profiling the $12 challenge app: `Hydrator` lets
  PHP coerce scalar columns (20 rows 8.6 → 3.6 µs), the container reflects each class's constructor
  once, `Pipeline` steps through stages by index, `SwooleServer` skips the static-file check for
  paths without an extension. 45 → 38.5 µs per request end to end (twelve bench, 1 vCPU). A
  fractional value into an `int` parameter now gets PHP's deprecation notice instead of a silent cut.
- **0.13.0** (2026-11-06): OpenSwoole start-up warnings (base mode with a finite `memory_limit`,
  opcache off); the `serve` mail line only with a `MailProvider`; a short `auth.jwt.secret` stops the
  app at boot. Breaking: the nested `app.swoole.settings` array and the pre-0.7 migrations-table
  upgrade are gone. The test suite passes on MySQL and Postgres again.
- **0.12.0** (2026-11-03): hardening round: `TrustProxies`; `APP_ENV` (missing = production, forces
  debug off; `display_errors=0`); `Env` casts on/off, yes/no; cron on `dragonmantank/cron-expression`
  (macros, `L`/`W`/`#`, DST rules); `Auth\Jwt` on `lcobucci/jwt` (optional; HS256 secrets 32+ bytes,
  future `iat` rejected); a security review (findings under Open).
- **0.11.0** (2026-10-22): `Auth\Authorize` (a stage you extend with `allows()`, filled from the
  context; 403/404/redirect) and `make:authorize`.
- **0.10.1** (2026-10-22): `SWOOLE_MODE` defaults to `process` (overloaded, base mode failed 24% of
  requests where process queued them).
- **0.10.0** (2026-10-22): from the Helpdesk load test: `count()` leaves out LEFT JOINs on the joined
  id; every `SWOOLE_*`/`SQLITE_*` variable is a setting (`env_prefixed()`); `SWOOLE_MODE`;
  `max_request` 100,000; the hydrator casts to declared types; `JsonOutlet::with(status:)`,
  `Page::map()`; grouped `where(fn ...)`, `orderByRaw()`; `withIp()`; empty file inputs; `Schedule::load()`.
- **0.9.1** (2026-10-21): `JsonOutlet::data()`; pype's dev pages are stages; "handlers" are gone from the
  docs (every step is a stage).
- **0.9.0** (2026-10-21): from building quotes and invoices: `routesIn()`, `stage()`/`::with()`,
  Requires/Provides + `routes:check`, pagination links, `redirect()`, template locals (`$csrf`, `$old`,
  `$error`), page outlets, Latte/Blade by file extension, dev mailbox, test helpers, `Chain`, resource
  routes, per-user `Throttle` and scoped `unique`, `Validate::prepare()`, indexed foreign keys, install
  command.
- **0.8.1** (2026-10-20): the app guide moved to `docs/GUIDE.md`.
- **0.8.0** (2026-10-20): factories and seeders; from the first $12 bench: query builder identifier
  memo, 1 OpenSwoole worker per core (container-aware), `Auth\Jwt` + `JwtGuard`, `app.errors`,
  `Validate` status, `TrimStrings`, `insertReturning()`, URL-decoded route params.
- **0.7.0** (2026-10-20): migration rollback (`down()`, `-- down`, batches, `migrate:rollback`,
  `migrate:fresh`); fix for `insert()` on Postgres tables without a sequence.
- **0.6.0** (2026-10-20): database job queue and the scheduler.
- **0.5.0** (2026-10-20): validation rules (`unique`/`exists`, dates, wildcards), encryption, remember
  me, cache (PSR-16; file/APCu/database/array/null), password reset, email verification, mail.
- **0.4.0**: logging (PSR-3), the live log viewer, the dev error page, `HtmlOutlet`.
- **0.3.0**: fast route matching, `bin/pype optimize`, named routes, `url()`, signed URLs.
- **0.2.0**: sessions, CSRF, guards; joins/aggregates/pagination; uploads; file sessions; rate limits;
  the schema builder; SQLite/MySQL/Postgres; app commands; the OpenSwoole server.
- **0.1.0**: pipeline, stages, outlets, routing, container, JSON/views, validation, token auth, query
  builder, `.sql` migrations, test client, console.
