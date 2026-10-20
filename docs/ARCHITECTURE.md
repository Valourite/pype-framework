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

**pype's own error wording:** framework throw sites mark their `HttpException` with a stable
key (`withKey('no_route')`, `bad_json`, `validation`, `unauthenticated`, `invalid_token`, ...;
listed in the skeleton's `config/app.php`), never in the shared factories, so an app's own
`notFound('post not found')` is never reworded. `Application::defaultRender()` replaces the
message with `config('app.errors.<key>')` when set; `server_error` covers the non-debug 500.
`Validate` takes a `status` (422 default).

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

## Cache

`Cache\CacheProvider` (from `config/cache.php`) binds a `Cache\Store` and `Cache\Cache` (also as
`Psr\SimpleCache\CacheInterface`); nothing is built until something asks for it. `Cache` does
key validation (PSR-16: `{}()/\@:` reserved, so apps use dots), the prefix, TTL handling (seconds,
`DateInterval`, null = forever, ≤ 0 deletes) and everything above the store; a `Store` only
knows six operations: `fetch` (found + value, so null is cacheable), `put`, atomic `add`,
`increment`, `forget`, `flush(prefix)`.

| Store | How |
|---|---|
| `FileStore` (default) | one `sha256(key).php` file per key returning `[expiresAt, value]`; scalars/arrays via `var_export` (opcache serves hits from memory), objects via `serialize()`. tmp + `rename()` writes, `opcache_invalidate()` on write/forget. `add`/`increment` hold one of 16 striped `flock`s. Line 1 holds the base64 key so `flush(prefix)` can be prefix-exact |
| `ApcuStore` | `apcu_fetch/store/add/inc/delete`, `APCUIterator` for flush; native TTLs |
| `DatabaseStore` | `cache(key, value, expires_at)`; ints as digits (so `increment` is SQL), the rest `base64(serialize())`. `put` = one upsert; `add` = one upsert whose update only fires on an expired row (`WHERE` / MySQL `IF()`), so it's atomic without retries; `increment` = upsert + `RETURNING` (MySQL: transaction + read back). Keys over 255 chars keep 190 chars + a hash |
| `ArrayStore` / `NullStore` | tests / off |

Built on top, in `Cache`:
- **Locks** (`Cache\Lock`): `add("lock.{name}", ownerToken, seconds)`; `release()` only deletes
  while the stored token is still ours; `run()` = get → try/finally release.
- **`stale($key, fresh, stale, fn)`**: stores `['pype.stale' => value, 'fresh' => until]` for
  fresh + stale seconds. When stale, it takes `lock("refresh.{key}")` and pushes the recompute
  onto `Http\AfterResponse` (current request from a container closure, as `Mailer` does); no
  request → recompute now.
- **Groups**: `group($name)` returns a `Cache` with prefix `{prefix}group.{name}.v{n}.`, where `n`
  is the integer at `groupver.{name}`. `flush()` increments it: one write, old keys are never
  read again and expire. A missing version starts at a random number, so an evicted version key
  can't bring old entries back.

The root `Cache` is a singleton with no mutable state (only group objects, made per call, move
to the new version on `flush()`), so it is worker-safe; `CacheTest` checks this.

## Queue

`Queue\QueueProvider` binds `Queue\Queue` from `config/queue.php`: `SyncQueue` (runs the job in
`push()`, throws), `DatabaseQueue`, `ArrayQueue` (tests), or a class. A job is any object with
`handle()`; it is stored as `base64(serialize())` (`Queue\Payload`), so its constructor data must
be plain. Optional public `$tries` (default 1), `$backoff` (int or one per retry), `$timeout`,
and `failed(Throwable)`, read through `QueuedJob`.

`DatabaseQueue::pop()` claims inside `Connection::transaction()`: SELECT the oldest available row
(`available_at <= now`, not reserved or reserved longer than `retry_after` ago) with
`FOR UPDATE SKIP LOCKED` on MySQL/Postgres (SQLite: `BEGIN IMMEDIATE` serializes writers), then
set `reserved_at` and `attempts + 1`. Success deletes the row; a failure releases it with the
backoff while attempts < tries, else moves it to `failed_jobs` in one transaction. A payload that
won't unserialize goes straight to `failed_jobs`. `QueueConcurrencyTest` forks 4 workers on all
three databases and checks every job ran exactly once.

`Queue\Worker` (`bin/pype queue:work`) loops pop → `Invoker::call([$job, 'handle'])` → delete /
release / fail. It stops after the current job on SIGTERM/SIGINT (pcntl), `--max-jobs`,
`--max-time`, `--memory`, or when the cache key `queue.restart` changes (`queue:restart`). A job
running past its timeout trips `pcntl_alarm`: the worker logs and exits 1 for its supervisor to
restart, and the job is re-claimed after `retry_after`. A failing `pop()` (database gone) logs,
disconnects the `Connection` and retries. `Mailer::queue()` renders now and pushes
`Mail\SendQueuedMail` (the `Message` without its view data).

## Schedule

`routes/schedule.php` is loaded only by `Console` (`schedule:run`, `schedule:list`), with
`$schedule` (`Schedule\Schedule`) and `$app` in scope. `Task`s hold a 5-field expression that the
frequency helpers edit field by field, a timezone, `when()`, `withoutOverlapping()` (cache lock
`schedule.{id}`) and `onOneServer()` (cache `add` of `schedule.{id}.{YmdHi}`). `Schedule\Cron` is
our own parser (`*`, values, ranges, steps, lists, names; DOW 7 = 0; when both day fields are
restricted either matches, and a field starting with `*` counts as unrestricted, as in cron).
`Schedule::run()` runs due tasks one by one in-process: commands through `Console::call()`
(non-zero exit = failure), closures through `Invoker`, jobs via `Queue::push()`; each failure is
logged and the rest still run. `schedule:work` starts a fresh `schedule:run` process every minute.

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

JWT (`Auth\Jwt`, `Auth\JwtGuard`; bound by `AuthProvider`, built only when used): HS256 with
`hash_hmac`/`hash_equals`, RS256 with `openssl_sign`/`openssl_verify`. **The accepted algorithm
follows from the configured keys, never from the token's header**: a secret allows HS256, a
public key allows RS256, so `alg: none` and an RS256 public key used as an HMAC secret fail.
`exp` is required; `nbf` and `exp` allow `leeway`; `iss`/`aud` are checked when configured;
`kid` picks among several public keys. The guard returns null for non-JWT bearer tokens (no
dots) so it chains with `TokenGuard`, unless `jwt.only`; a JWT that fails is a 401
(`invalid_token`). The user is `Auth::findUser(sub)` (`jwt.user = database`), a `JwtUser` from
the claims (`claims`), or whatever a subclass's `user()` returns; the claims go into the context
as `JwtClaims`.

`Security\Encrypter` (bound by Application, built on first use): `sodium_crypto_secretbox` with
APP_KEY, a random nonce per value, JSON (never unserialize), `pype1.` + URL-safe base64;
`app.previous_keys` decrypt during key rotation.

## Services and providers

`Application` holds a PSR-11 `Container` (autowiring; `singleton`, `bind`, `instance`).
Providers (listed in `config/app.php`) bind services from config: `DatabaseProvider`
(`Connection`, `Migrator`, `RateLimiter`), `AuthProvider`, `SessionProvider` (`SessionStore`,
`'web'`), `ViewProvider`, `MailProvider`, `CacheProvider`, `QueueProvider`. Swappable backends follow one pattern: an interface, drivers chosen by
a config string, or your own class name.

Services must be stateless across requests (see the worker-safety principle in `CLAUDE.md`).

## Database

`Connection` wraps PDO: lazy connect, a prepared-statement cache, `transaction()` (SQLite:
`BEGIN IMMEDIATE`). `QueryBuilder` (`$db->table('x as a')`) validates identifiers and quotes per
driver; quoted identifiers are memoized per process (`QueryBuilder::$quoted`, capped at 1,000
so identifiers built from user input can't grow a worker's memory). That took building a query
from ~6 µs to ~1.4 µs (the twelve bench). `insertReturning()` gets database-filled columns
back: `INSERT ... RETURNING` on SQLite/Postgres, insert + select by `id` on MySQL. Route params
are URL-decoded after matching (`Application::dispatch()`).

Factories and seeders (CLI and tests only, never on the request path): `Database\Factory`
builds rows from `definition(Fake)` + states, a sequence and overrides, then resolves closure
values with the row so far (lazy relations). `create()` is one `insertReturning()` per row in a
transaction, hydrated by `Database\Hydrator` (shared with `QueryBuilder::as()`); `insert()` is
`QueryBuilder::insertMany()`: multi-row `VALUES`, chunked under the databases' parameter limits
(≤ 30,000 per statement, ≤ 1,000 rows). `Database\Fake` uses `\Random\Randomizer`
(Mt19937 when seeded); emails/usernames carry a process-wide serial. `Database\Seeder::run()`
is called through the Invoker like a command (`db:seed`, guarded by `--force` without
`app.debug`). Migrations are `.php` (schema builder: `Schema` → `Table`/`Column` → `Grammar` per
driver) or `.sql`, run in name order, recorded by name and batch in `pype_migrations` (one
batch per `migrate`). `rollback()` undoes the last batch (or the last N) newest first, via
`down()` or the part of a `.sql` file after a `-- down` line; one without either is irreversible
(detected up front by checking whether the class overrides `Migration::down()`), and rollback
stops there. Tables from before 0.7 get a `batch` column, one batch per existing migration in
the order they ran. `fresh()` = `Schema::dropAllTables()` + `migrate()`. On Postgres,
`QueryBuilder::insert()` uses `RETURNING *` instead of PDO's `lastInsertId()` (`LASTVAL()`,
which fails on tables without a sequence and aborts the transaction).

## Views

Templates are plain PHP files rendered by `Template` with the global `e()` for escaping and `$this` helpers (`csrf()`,
`old()`, `error()`, `layout()`, `partial()`). `new View('name')` for simple pages; a class
extending `ViewOutlet` for views with their own data/logic (`data()` hook, constructor DI).
