# How pype works

## A request, start to finish

```
public/index.php
  └─ bootstrap/app.php        Env::load, new Application(config), routes/*.php
       └─ $app->run()         Request::fromGlobals() → handle() → Response::send()

Application::handle($request)
  1. bind Request in the container
  2. global pipeline:   [outlets…, global stages…]  →  dispatch()
  3. dispatch():        Router::resolve(method, path) → route pipeline [outlets…, stages…, handler]
  4. no outlet claimed the response → app default outlet (JsonOutlet unless changed)
  5. render the response now (a failing template becomes a reported 500)
  6. forget the Request (long-running servers)
```

Under OpenSwoole (`APP_SERVER=swoole`) steps 1–6 are the same; `Server\SwooleServer` builds the
`Request` from OpenSwoole's request (`toRequest()`) and writes the `Response` back (`emit()`)
instead of `fromGlobals()` / `send()`. The app boots once in the master process; each worker
handles one request at a time.

## Stages

A stage is anything the `Invoker` can call with `(Request $request, Closure $next)` semantics:

- a class implementing `Pipeline\Stage` (`handle(Request $request, Closure $next): mixed`);
- a class name (resolved from the container each time);
- a closure or invokable with any parameters (the handler at the end of a route is just a stage
  that doesn't call `$next`).

A stage either calls `$next($request)` to continue, or returns something to short-circuit. What
it returns becomes a `Response` via `Response::from()`: arrays/objects → data for the outlet,
`Response` as is, `Responsable` → its `toResponse()`, `null` → 204.

Exceptions are caught per stage (`Pipeline::onException` → `Application::renderException`), so
outer stages always get a `Response` back. `HttpException` carries a status; `renderUsing()`
maps your own exception types.

### Parameter injection (`Pipeline\Invoker`)

| Parameter | Gets |
|---|---|
| `Request` | the request flowing through |
| `Closure`, or untyped `$next` | the next stage |
| `Context` | the request's context |
| a class that's in the `Context` | that object (e.g. `User` put there by `Authenticate`) |
| any other class | resolved from the container |
| scalar named like a route param (`{id}` → `int $id`) | the param, cast to the type |

### Context

`$request->context()` is a typed, per-request store keyed by class: `put($user)`,
`get(User::class)`, `find(User::class)`. Stages hand data to later stages through it (session,
signed-in user, validated input, loaded models). It is the only place per-request state should
live.

## Outlets

An `Outlet` is a stage that runs the rest of the pipeline, then claims the response
(`withOutlet($this)`) unless a closer outlet already did. `Router::compile()` moves outlets to the
front of a stage list, so they wrap everything and the innermost wins: route > group > global >
app default. Rendering (`Outlet::render()`) happens **after** the pipeline has returned, in
`Application::handle()`.

Built in: `JsonOutlet` (`{"data": …}` / `{"error": …}`), `View` / `ViewOutlet` (HTML templates),
`FileOutlet` (downloads, used by `Response::file()`). Write your own by implementing `render()`
and `contentType()`.

## Route matching

`Router::resolve()`:

1. **Static paths** (no `{param}`) are a hash lookup, `isset($static[$path][$method])`.
2. **Paths with params** are combined into regexes of up to 50 patterns each, every alternative
   tagged `(*MARK:n)`, so one `preg_match` finds the pattern. Routes that differ only in param
   names share a pattern; the method picks the route.
3. Building those regexes costs more than one plain scan, so a Router's **first** lookup scans
   the routes in order (that's every request under PHP-FPM) and later lookups use the compiled
   regexes (a long-running server compiles once).
4. `bin/pype optimize` writes both tables to `bootstrap/cache/routes.php` with a signature of the
   routes (method + path, in order). opcache keeps that file in memory, so FPM skips building.
   A stale file (routes changed) is ignored, never trusted.
5. Wrong method (405), and patterns that can match the same path (`/a/{x}` and `/{y}/b`), go
   to the plain scan, so the answer is always the registration-order one.

Precedence: a static path beats a param pattern (`/users/new` over `/users/{id}`) regardless
of registration order. Otherwise the first registered route wins.

Measured (2026-10-19, worst-case route): a lookup on a long-running server takes 0.4 µs (30
routes) to 1.5 µs (500); the old plain scan took 2.3–33 µs, FastRoute 0.3–2.7 µs. Under PHP-FPM
with `optimize`, routing adds ~1–4 µs on top of registering the routes.

## Stacks

Named lists of stages registered by providers or apps: `$app->stack('web', [...])`, used in
routes by name: `['web', ...]`. Built in:

- `'web'` (SessionProvider): `StartSession` → `VerifyCsrfToken` → `FlashValidationErrors`
- `'auth'` (AuthProvider): `Authenticate` with the configured guards

## Auth

Transport-agnostic. `Authenticate` asks each `Guard` (`authenticate(Request): ?object`) in turn;
built in are `SessionGuard` (cookie session, only inside `'web'` so always CSRF-checked) and
`TokenGuard` (`Authorization: Bearer`). How a user logs in is the app's handler:
`Auth::attempt()` checks a password, `SessionGuard::login()` remembers the user,
`Auth::issueToken()` makes an API token.

## Services and providers

`Application` holds a PSR-11 `Container` (autowiring; `singleton`, `bind`, `instance`).
Providers (listed in `config/app.php`) bind services from config: `DatabaseProvider`
(`Connection`, `Migrator`, `RateLimiter`), `AuthProvider`, `SessionProvider` (`SessionStore`,
`'web'`), `ViewProvider`. Swappable backends follow one pattern: an interface, drivers chosen by
a config string, or your own class name.

Services must be stateless across requests (see the worker-safety principle in `CLAUDE.md`).

## Database

`Connection` wraps PDO: lazy connect, a prepared-statement cache, `transaction()` (SQLite:
`BEGIN IMMEDIATE`). `QueryBuilder` (`$db->table('x as a')`) validates identifiers and quotes per
driver. Migrations are `.php` (schema builder: `Schema` → `Table`/`Column` → `Grammar` per
driver) or `.sql`, run in name order, recorded by name in `pype_migrations`.

## Views

Templates are plain PHP files rendered by `Template` with the global `e()` for escaping and `$this` helpers (`csrf()`,
`old()`, `error()`, `layout()`, `partial()`). `new View('name')` for simple pages; a class
extending `ViewOutlet` for views with their own data/logic (`data()` hook, constructor DI).
