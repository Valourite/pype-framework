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
| `Pype\Outlet`      | `Outlet` (abstract exit stage), `JsonOutlet`                                             |
| `Pype\View`        | plain-PHP templates: `ViewOutlet` (class-based views), `View`, layouts and partials      |
| `Pype\Database`    | PDO `Connection`, `QueryBuilder` (joins, grouping, aggregates), `.sql` `Migrator` — SQLite (WAL), MySQL, Postgres |
| `Pype\Auth`        | `Auth` (users, passwords, API tokens), `Authenticate` stage, `Guard`s: `SessionGuard`, `TokenGuard`, or your own |
| `Pype\Session`     | `StartSession`, `VerifyCsrfToken`, `FlashValidationErrors` (the `'web'` stack), `Session`, swappable `SessionStore` |
| `Pype\Stages`      | built-in stages: `Cors`, `JsonBody`, `RequestId`, `Throttle`                              |
| `Pype\Validation`  | `Validate` stage, `Validator`, `ValidatedInput`                                           |
| `Pype\Container`   | PSR-11 container with autowiring                                                          |
| `Pype\Testing`     | `TestClient` — run requests in-process, no server; keeps cookies like a browser           |
| `Pype\Console`     | `bin/pype` commands: serve, routes, make:*, migrate                                       |

## Development

```bash
composer install
composer test
```

To work on the framework and an app together, point Composer at your local checkout once:

```bash
composer config --global repositories.pype-framework path /path/to/pype-framework
```

Any app requiring `pype/framework` on that machine then symlinks your checkout into `vendor/`.
Undo with `composer config --global --unset repositories.pype-framework`.

## License

MIT
