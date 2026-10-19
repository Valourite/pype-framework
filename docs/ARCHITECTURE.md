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

## Links to named routes

`->name('expenses.edit')` names a route (groups can add a prefix: `group(..., name: 'admin.')`).
`Routing\Urls` builds links: `to($name, $params, $absolute)` fills `{placeholders}` (encoded with
`rawurlencode`) and puts the remaining params in the query string. `signed()` adds `expires` and
`signature` = HMAC-SHA256 (`APP_KEY`) of the path plus the sorted query; the host isn't signed,
so links survive proxies. The `ValidateSignature` stage checks it with `hash_equals` and returns
403 otherwise.

The global `url()` and `signed_url()` helpers reach the app's `Urls` through `Urls::current()`,
set by the `Application` constructor. This is the one deliberate process-wide reference in pype:
it only holds the route table and config (the same for every request), so it is worker-safe.

Router::named() builds a name index on first use and rebuilds it on a miss (names are set
after `add()`, by the chained `->name()`). Two routes with the same name are an error.

## Logging and errors

`Log\LogProvider` (from `config/log.php`) binds `Log\Logger` as `Psr\Log\LoggerInterface`:

- **Entries** are one JSON object per line (`time`, `level`, `message`, `request_id`, `context`) in
  `storage/logs/pype-YYYY-MM-DD.log`, appended with `LOCK_EX`; files older than `log.days` are
  deleted (checked once a day per process).
- **Request id:** the logger reads the request currently bound in the container (one request at
  a time per process, under both FPM and OpenSwoole) and `RequestIdValue::for()` gives it an id
  stored in the request's context, shared with the `RequestId` stage. So every line from one
  request, and the error page, share one id.
- **Forwarding:** entries at `log.forward_level` or above also go to each `log.forward` target:
  `StreamLogger` (`stderr`/`stdout`, the same JSON line), `SyslogLogger` (`syslog()`), or any PSR-3
  class from the container (Monolog + Sentry...). They get the raw message and context plus
  `request_id`. The file is written first; a throwing target goes to `error_log()` only.
- **Error reports:** `LogReporter` sends unexpected (5xx) errors to the log; `LogRequests`
  (global, `log.requests`) writes "GET /path 200 3.1 ms" per request.
- **Log viewer:** two routes under `log.viewer.path` (`pype.logs`, `pype.logs.entries`). The
  page polls `entries?after=<byte offset>` about once a second; `LogReader::after()` stats the
  file and reads only new complete lines. `tail()` reads backwards for the initial view and "load
  older". The viewer's own requests are never logged. It's registered only when enabled AND
  (APP_DEBUG or `log.viewer.stages` is non-empty), so it can't be public by accident.
- **Error page:** `Debug\ErrorPage` is an outlet that `Application::renderException` attaches
  to unexpected errors when `app.debug` is on and the client accepts `text/html` (API clients get
  JSON). It already has an outlet, so route/group outlets don't replace it. It reads the request's
  log lines through `LogReader::forRequest()`. Secrets are hidden: Authorization/Cookie headers,
  cookie values, and input fields named like pass/token/secret/key/card.

## Mail and work after the response

`Mail\MailProvider` (from `config/mail.php`) binds a `Transport` (PHPMailer for smtp/sendmail/
mail, `LogTransport`, `ArrayTransport`, `NullTransport`, or a class) and the `Mailer`.
`Mailer::prepare()` copies the message, fills the default sender, renders `view()` with `Views`,
and makes a text part from the HTML (`Mailer::textFrom()`).

`Mailer::later()` prepares now and pushes the delivery onto `Http\AfterResponse`, a list of
callbacks kept in the request's context. `Application::terminate($request)` runs them once the
response is on its way: `run()` calls it after `fastcgi_finish_request()` (PHP-FPM), the
OpenSwoole server after writing the response, `TestClient` right after `handle()`. A failing
callback is reported and doesn't stop the others; outside a request, `later()` sends at once.

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

Password resets and verification (the security-sensitive core; the flow is app code):
`Auth\PasswordResets` stores `sha256(token)` in `password_resets` (one row per user), checks
expiry (`auth.reset_ttl`) and throttle (`auth.reset_throttle`), and `reset()` deletes the token
inside a transaction as the check, so of two racing requests only one changes the password; it
then revokes API tokens. Verification uses a signed URL carrying `Auth::emailHash()` of the
address; `Auth::markEmailVerified()` sets `email_verified_at`; the `EnsureEmailIsVerified` stage
guards routes.

"Remember me" (`auth.remember_table`, off when null): `Auth\RememberTokens` keeps one row per
device with a public selector and `sha256(validator)`; the cookie is `selector:validator`.
`SessionGuard::authenticate()` falls back to the cookie when the session has no user, signs the
user in with a new session id, and swaps in a new validator (the previous one stays valid for 60 s
for simultaneous requests). A known selector with an older validator is treated as a replayed
copy: all of the user's tokens are deleted. Cookies the guard needs to set travel on the
`Session` object (`queueCookie()`), and `StartSession` adds them to the response. Logout and
password resets revoke them.

`Security\Encrypter` (bound by Application, built on first use): `sodium_crypto_secretbox` with
APP_KEY, a random nonce per value, JSON (never unserialize), `pype1.` + URL-safe base64;
`app.previous_keys` decrypt during key rotation.

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
