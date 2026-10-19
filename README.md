# pype/framework

The core of [pype](https://github.com/Valourite/pype-skeleton): a lightweight, pipeline-first PHP
framework. Every route is its own pipeline of **stages**; output is data (JSON by default) that a
**outlet** (the exit stage) writes out — JSON by default, HTML through a view, or your own format.

> Start a new app with `composer create-project pype/pype my-app`. This package is the library that
> lands in `vendor/`.

**Requires:** PHP 8.2+, `ext-pdo` (plus the driver you use). **Depends on:** `psr/container` only.

## What's inside

| Namespace          | Contents                                                                                  |
|--------------------|-------------------------------------------------------------------------------------------|
| `Pype`             | `Application` (lifecycle, config, providers, error rendering/reporting), `Provider`         |
| `Pype\Pipeline`    | `Stage` interface, `Pipeline`, `Invoker` (parameter injection for closures/invokables)    |
| `Pype\Routing`     | `Router` (groups, named stacks), `Route`                                                  |
| `Pype\Http`        | `Request`, `Response`, `Cookie`, typed `Context`                                          |
| `Pype\Outlet`      | `Outlet` (abstract exit stage), `JsonOutlet`, `HtmlOutlet`, `FileOutlet`                 |
| `Pype\View`        | plain-PHP templates: `ViewOutlet` (class-based views), `View`, layouts and partials      |
| `Pype\Database`    | PDO `Connection`, `QueryBuilder` (joins, grouping, aggregates), `Migrator` (`.sql` or PHP), `Schema` builder — SQLite (WAL), MySQL, Postgres |
| `Pype\Auth`        | `Auth` (users, passwords, API tokens), `Authenticate` stage, `Guard`s: `SessionGuard`, `TokenGuard`, or your own |
| `Pype\Session`     | `StartSession`, `VerifyCsrfToken`, `FlashValidationErrors` (the `'web'` stack), `RedirectOnSuccess`, `Session`, `SessionStore`: database, file, array or your own. Only saved when changed (or once a minute to slide the expiry) |
| `Pype\RateLimit`   | `RateLimiter` behind `Throttle`: `DatabaseRateLimiter` (one atomic upsert per hit), `ApcuRateLimiter`, or your own |
| `Pype\Stages`      | built-in stages: `Cors`, `JsonBody`, `MethodOverride`, `RequestId`, `Throttle`             |
| `Pype\Validation`  | `Validate` stage, `Validator`, `ValidatedInput`                                           |
| `Pype\Container`   | PSR-11 container with autowiring                                                          |
| `Pype\Testing`     | `TestClient` — run requests in-process, no server; keeps cookies like a browser           |
| `Pype\Console`     | `bin/pype` commands: serve, routes, make:*, migrate                                       |
| `Pype\Log`         | PSR-3 `Logger` (JSON lines, a file per day; forwards to stderr, syslog or any PSR-3 logger), request log, and a live **log viewer** page with filters (`/_pype/logs`) |
| `Pype\Mail`        | `Message`, `Mail` classes, `Mailer` (`send()`, or `later()` after the response); SMTP/sendmail via PHPMailer, log, array (tests) |
| `Pype\Debug`       | development **error page**: trace with source, route and stages, request (secrets hidden), the request's log lines |
| `Pype\Server`      | `SwooleServer`: run the app as a long-running OpenSwoole server (`APP_SERVER=swoole`)      |

## Docs

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md): how a request flows through stages and outlets, injection, context, providers
- [`docs/ROADMAP.md`](docs/ROADMAP.md): what's planned, and what pype deliberately won't do
- [`docs/BENCHMARKS.md`](docs/BENCHMARKS.md): load-test results (PHP-FPM vs OpenSwoole, SQLite/Postgres/MariaDB) and how to re-run them
- [`CLAUDE.md`](CLAUDE.md): design principles, layout, commands, traps and the release process, for anyone (or any AI assistant) working on the framework

## Development

```bash
composer install
composer test                 # SQLite in memory
bin/test-databases.sh         # the same suite on SQLite, MySQL 8.4 and Postgres 17 (Docker)
```

To work on the framework and an app together, point Composer at your local checkout once:

```bash
composer config --global repositories.pype-framework path /path/to/pype-framework
```

Any app requiring `pype/framework` on that machine then symlinks your checkout into `vendor/`.
Undo with `composer config --global --unset repositories.pype-framework`.

## License

MIT
