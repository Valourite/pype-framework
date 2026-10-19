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

## Tier 1: almost every app needs these

| Feature | Built on | Notes |
|---|---|---|
| URL generation from named routes; signed URLs (with expiry) | `http_build_query`, `hash_hmac` | `Route::name()` exists, nothing uses it yet. Needed by the next items |
| More validation rules: `regex`, `unique`/`exists`, `before`/`after`, `items.*`, closure rules, custom messages | `preg_match`, `filter_var`, query builder | Most common hand-written app code |
| Logger: levels, daily file, request id | `file_put_contents(FILE_APPEND\|LOCK_EX)` | Only `error_log` today (`Exceptions\ErrorLogReporter`) |
| Cache: `remember($key, $ttl, fn)`; APCu / file / array | `apcu_*`, `var_export` + opcache | Same driver pattern as sessions and rate limits |
| Encryption; "remember me" cookie guard | `sodium_crypto_secretbox` | Needs an `APP_KEY` |
| Mail: `Mailer` interface; SMTP + log drivers | `stream_socket_client` | |
| Password reset; email verification | the above | |
| Development error page (trace + source) | `debug_backtrace` | Today a dev crash is a bare JSON error |

## Tier 2: speed and growing apps

- `bin/pype optimize`: compile routes and config to a PHP file that opcache keeps in memory
  (big win under FPM, where routes are rebuilt every request).
- Database-backed job queue + `queue:work` (`pcntl`); `schedule:run` for cron.
- Seeders and test factories; migration rollback (`down()`); multiple database connections.
- Thin HTTP client (curl). Translations (`intl` `MessageFormatter`).

## Tier 3: later

- Redis stores (`phpredis`) for sessions, cache, rate limits.
- FrankenPHP and RoadRunner adapters (same shape as `Server\SwooleServer`), only if wanted.
- Minimal lifecycle hooks/events.
- Class-based views with richer behaviour. The owner wants this designed properly, explicitly
  **not** a cheap Livewire clone.

## Won't do (conflicts with the principles)

- An ORM with magic properties or lazy loading (query builder + plain data classes instead).
- Facades / static proxies; a template language; annotation/attribute routing.
- Hidden global or static state.
