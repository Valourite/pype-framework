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

## Tier 2: speed and growing apps

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
