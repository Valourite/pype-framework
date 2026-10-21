# The pype guide

How to build an app with pype: every built-in, with examples. Apps have this file at
`vendor/pype/framework/docs/GUIDE.md`. The examples follow the starter app (`pype/pype`), so they
mention its files (`routes/web.php`, `config/auth.php`, `UserFactory`, ...).

**How pype thinks.** A request goes through a pipeline: an ordered list of small classes, each doing
one thing, that hand typed objects to each other through the request context. Order is explicit, so
reading a route tells you everything that happens to the request; a stage that runs before what it
needs fails loudly (and `routes:check` finds it without a request). Everything is a class, so every
step can be tested alone. The code is plain PHP: no magic methods, no facades, no ORM, no template
language you must learn, and nothing loads or costs anything until a route uses it. pype runs the same
under PHP-FPM and long-running Swoole workers, which is why per-request data lives on the request and
never in a static or a service. The app's `AGENTS.md` has the rules in short; this guide has the detail.

## A route

```php
$router->get('/users/{id}', ['auth', FindUser::class, UserJson::class]);
```

- `'auth'` — a named stack (here: the built-in bearer-token check, which puts the user in the context)
- `FindUser` — a **stage**: `handle(Request $request, Closure $next)`; loads the user into the context
  and calls `$next`. A stage that ends the route returns the result instead of calling `$next`.
- `UserJson` — the **outlet**, the stage where the flow leaves the pipeline: it decides what goes out
  (a `JsonOutlet` subclass reading the context)

Swap the outlet and the same stages render HTML:

```php
$router->get('/users/{id}/profile', [FindUser::class, UserProfile::class]);
```

Everything in a route is a stage. Closures and invokable classes are accepted too (their parameters
are injected by type), but pype apps are built from `Stage` classes.

## Building a feature, start to finish

A route is a **pipeline**: a list of small classes that run in order and hand typed objects to each
other through the request context. Route files stay short and list only classes; each class does one
thing and can be tested on its own (see Testing). The usual shape:

```
[guard] → [validate] → [load what it needs] → [do the work] → [outlet]
 'web',    ValidateX    LoadX (404 if not      CreateX         XView (page) / RedirectOutlet /
 Authenticate           yours), puts X in      (returns the    the default JSON outlet
                        the context            result)
```

Quotes, as an example (`php bin/pype make:routes quotes`, `make:stage`, `make:view`):

```php
// routes/quotes/web.php
$router->group('/quotes', ['web', Authenticate::with(redirectTo: '/login')], function (Router $router) {
    $router->get('/', [LoadQuotes::class, QuotesView::class])->name('index');
    $router->get('/new', [QuoteFormView::class])->name('create');
    // CreateQuote returns the new Quote; the outlet sends browsers to its page (JSON clients get the quote).
    // `new` here because the outlet takes a closure, which ::with() can't carry.
    $router->post('/', [ValidateQuote::class, CreateQuote::class, new RedirectOutlet('quotes.show', fn (Quote $quote) => ['id' => $quote->id], flash: 'Quote created.')])->name('store');

    $router->group('/{id}', [LoadQuote::class], function (Router $router) {
        $router->get('/', [QuoteView::class])->name('show');
        $router->get('/edit', [QuoteFormView::class])->name('edit');
        $router->patch('/', [ValidateQuote::class, UpdateQuote::class, RedirectOutlet::with('quotes.show', flash: 'Saved.')])->name('update');
        $router->delete('/', [DeleteQuote::class, RedirectOutlet::with('quotes.index', flash: 'Deleted.')])->name('destroy');
    });
}, name: 'quotes.');

// routes/quotes/api.php: the same stages, JSON out (served under /api/quotes)
$router->group('/quotes', ['auth'], function (Router $router) {
    $router->get('/{id}', [LoadQuote::class, QuoteJson::class])->name('show');   // a JsonOutlet subclass: what clients see
    $router->post('/', [ValidateQuote::class, CreateQuote::class])->name('store');
}, name: 'quotes.');
```

```php
#[Requires(User::class)]
#[Provides(Quote::class)]
final class LoadQuote implements Stage                     // app/Stages: load + ownership
{
    public function __construct(private Connection $db) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->context()->get(User::class);
        $quote = $this->db->table('quotes')->where('id', (int) $request->param('id'))
            ->where('company_id', $user->company_id)->as(Quote::class)->first()
            ?? throw HttpException::notFound();            // someone else's: 404, so ids don't leak

        $request->context()->put($quote);

        return $next($request);
    }
}

final class ValidateQuote extends Validate                 // app/Stages: rules (+ prepare() for rows)
{
    protected function rules(): array
    {
        return ['customer_id' => 'required|int|exists:customers,id,company_id={user.company_id}', 'notes' => 'nullable|string|max:2000'];
    }
}

#[Requires(ValidatedInput::class, User::class)]
final class CreateQuote implements Stage                   // app/Stages: the work; the last stage returns the result
{
    public function __construct(private Connection $db) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $input = $request->context()->get(ValidatedInput::class);
        $user = $request->context()->get(User::class);
        $id = $this->db->table('quotes')->insert([...$input->all(), 'company_id' => $user->company_id]);

        return $this->db->table('quotes')->where('id', $id)->as(Quote::class)->first();
    }
}

#[Requires(Quote::class)]
final class QuoteView extends ViewOutlet                   // app/Views: what the template gets
{
    protected function template(): string { return 'quotes/show'; }

    protected function data(mixed $data, Request $request): array
    {
        return ['quote' => $request->context()->get(Quote::class)];
    }
}
```

A stage that must decide where to go (or stops early) returns
`redirect('route.name', [...], flash: '...')` instead. `php bin/pype routes:check` confirms every stage comes after what it needs; `Chain` tests a stage alone,
`TestClient` the whole route.

**Don't** (each of these was tried in a real app and replaced by the shape above):

- **A generic "context view" or a named bag of view data.** One page outlet per page reads typed
  objects from the context; its `data()` is the list of what the template gets.
- **Stages that only exist to feed a template variable** (`LoadQuotesPageForTheView`). Load the real thing
  (`Quote`, a `Page` of quotes) and let the page outlet pick from it.
- **`ContainerAware` in your own stages.** Ask for services in the constructor; give arguments with
  `X::with(...)`.
- **Logic in route closures.** Every step is a stage class; a JSON route ends in a `JsonOutlet`
  subclass (or the default one) rather than a closure that returns data.
- **A repository class per table by default.** Stages query with `$db->table()`. Put logic several
  stages share (numbering inside a transaction, totals) in a plain service class.
- **A separate "handler" or "controller" layer.** The step that does the work is a stage like any other
  (it just returns instead of calling `$next`).
- **403 for another user's record**, or checking ownership in the stage that does the work. A `Load`
  stage gives 404.
- **Your own throttle, per-user unique check, redirect-with-message, pagination links or form token
  scraping in tests.** They're built in: `Throttle::with(..., by:, key:)`, `unique:...,{user.prop}`,
  `redirect()` / `RedirectOutlet`, `paginate(25, $request)` + `$page->links()`, `submit()` / `actingAs()`.
- **Per-request data in a service, singleton or static.** It goes in the request context.

## Concepts

| Concept    | What it is                                                                                         |
|------------|----------------------------------------------------------------------------------------------------|
| Stage      | a step in a pipeline: class implementing `Stage`, `stage(X::class, arg: ...)`, configured instance, closure, or invokable class |
| Injection  | closures/invokables get params by type or name: `fn (User $user, int $id, Connection $db) => ...`  |
| Context    | typed per-request store: `$request->context()->put($user)` / `->get(User::class)`                  |
| Stack      | named reusable stage list: `$app->stack('admin', ['auth', EnsureAdmin::class])`                    |
| Outlet     | the exit stage that writes the result out: `JsonOutlet` (default), `View`, or your own subclass    |
| Provider   | `register(Application $app)` — how the app and composer packages plug in (`config/app.php`)        |
| Errors     | `$app->renderUsing(Type::class, fn ...)` maps exceptions; `$app->reportUsing(...)` logs/alerts     |

Return values are normalised: `Response` as-is, `null` → 204, anything else → `{"data": ...}`.
Exceptions become `{"error": {...}}` *inside* the pipeline, so outer stages still run.

### Stages with arguments

A class-string stage (`LoadQuote::class`) is built when a request reaches it, with its constructor's
services autowired. When one stage class serves several routes, give it arguments with `stage()`,
or `::with()` on stages that `use WithArguments`:

```php
use Pype\Pipeline\WithArguments;

final class LoadDocumentsPage implements Stage
{
    use WithArguments;

    public function __construct(private string $kind, private Connection $db) {}   // $kind given, $db autowired
    ...
}

$router->get('/quotes', [ResolveListing::class, LoadDocumentsPage::with(kind: 'quote'), QuotesView::class]);
$router->get('/invoices', [ResolveListing::class, stage(LoadDocumentsPage::class, kind: 'invoice'), InvoicesView::class]);
```

- Arguments go by name (or position); the constructor's other parameters are services.
- Arguments are plain data: scalars, arrays, enums. Configuration known when the route is written goes
  here; data made during the request goes in the context.
- Nothing is loaded until a request reaches the stage, and each request gets a fresh instance, so a
  stage may keep what it loaded in a property, even under OpenSwoole.
- `new LoadDocumentsPage('quote')` still works, but it runs (and loads the class) while the routes
  file runs, on every request under PHP-FPM, and the one instance is shared by every request a Swoole
  worker serves. Services then need `ContainerAware`.
- pype's own configured stages and outlets have `::with()` too: `Throttle::with(5, 60)`,
  `Validate::with([...rules])` (and your `Validate` subclasses), `Authenticate::with(redirectTo: '/login')`,
  `RedirectOutlet::with('quotes.show', flash: 'Saved.')`, `View::with('quotes/show')`. A closure argument
  (`RedirectOnSuccess(fn ...)`) still needs `new`. The `'web'` and `'auth'` stacks are built this way.
- Write your own `with()` instead of the trait for typed parameters:
  `public static function with(string $kind): StageSpec { return stage(self::class, kind: $kind); }`.

### Checking the order of stages

Stages talk through the context, so order matters: a stage that reads a `Quote` must come after the
one that puts it there. At runtime a missing entry throws `Context has no [Quote]`; to catch it without
running the route, declare what each stage reads and puts:

```php
use Pype\Pipeline\{Provides, Requires};

#[Requires(User::class)]
#[Provides(Quote::class)]
final class LoadQuote implements Stage { ... }

// or, without attributes:
final class LoadQuote implements Stage, DeclaresContext
{
    public static function requires(): array { return [User::class]; }
    public static function provides(): array { return [Quote::class]; }
}
```

```
$ php bin/pype routes:check
✗ GET /quotes/{id}: QuoteView needs [App\Data\Quote]; nothing before it provides it.
✗ 41 routes checked, 1 problem.
```

- The key is what `put()` stored it under: the object's class. `Auth::USER` stands for the signed-in user
  (the configured `auth.user` class); `Authenticate` provides it.
- Global stages count first, then the route's stages with groups and stacks expanded. Outlets are checked
  last, wherever they are listed. Closures and undeclared stages are skipped; a class-string that isn't a
  class is reported.
- pype's own stages are declared: `StartSession` provides `Session`, `VerifyCsrfToken` requires it,
  `Validate` (and your subclasses) provide `ValidatedInput`, `Authenticate` provides the user, `RequestId`
  provides `RequestIdValue`.
- In a test: `$this->assertSame([], $this->app->checkRoutes());` (the skeleton has one).
- It also prints hints for stages written as `new X(...)`: allowed, but built while routes load and shared
  by every request in a worker; `X::with(...)` or `X::class` avoid that.

## Built in

| Stage / service                     | Use                                                                     |
|-------------------------------------|-------------------------------------------------------------------------|
| `'auth'` / `Authenticate::with(...)`  | require a user, found by guards (session, bearer token, or your own)    |
| `'web'`                             | session cookie + CSRF check + validation errors back to the form         |
| `Pype\Auth\Auth`                    | `createUser`, `attempt`, `issueToken`, `revoke`, `revokeAll`            |
| `Throttle::with(5, 60)`               | rate limit **per IP + route** (429 + `Retry-After`); counters in the database or APCu (`RATE_LIMIT_DRIVER`). Per user: see Roles, ownership and per-user limits |
| `Validate::with([...rules])`          | 422 on bad input; `ValidatedInput` injected on success (see Validation) |
| `Pype\Cache\Cache`                 | `remember`, locks, background refresh, groups; file, APCu or database (see Cache) |
| `Pype\Queue\Queue`                 | background jobs: `push`, `later`, retries, `queue:work` (see Queue); `routes/schedule.php` for cron tasks |
| `new TrimStrings()` (global)        | trims string input before validation (passwords left alone)             |
| `new RequestId()`                   | `X-Request-Id` on every response                                        |
| `new Cors([...origins])`            | CORS headers + preflight                                                |
| `new JsonBody(maxBytes: ...)`       | 413 on big bodies, 400 on malformed JSON                                |
| `new MethodOverride()` (global)     | forms send PUT/PATCH/DELETE via a hidden `_method` field                |
| `RedirectOnSuccess::with($to, $msg)`  | reuse an API route's stages from a form: redirect + flash on success; `$to` is a path or a route name |
| `new RedirectOutlet('route', ...)`  | end a route with a redirect on success, the way `View` ends one with a page (see Redirects) |
| `redirect('route', flash: '...')`   | return from any stage to stop there and redirect (see Redirects)       |

## Two ways in: pages and JSON

The same users and stages serve both. Only the first and last stages differ.

- **`routes/web.php`**: browser pages. The `'web'` stack starts a session (cookie), checks the CSRF
  token on every form post, and sends a failed form back to itself with errors and old input.
  `/login`, `/register`, `/account`, `POST /logout`.
- **`routes/api.php`**: JSON for apps and scripts, authenticated with bearer tokens.
  Served under `/api` (the file is named `api.php`): `POST /api/auth/register`, `POST /api/auth/login`,
  `POST /api/auth/logout`, `GET /api/me`.

Auth isn't tied to either. `Authenticate` asks a list of *guards* who the user is (from
`config/auth.php`: session, then bearer token). How a user proves who they are is your
login stage's job; `SessionGuard::login()` just remembers the result. For anything else (API keys,
a header from your proxy), implement `Pype\Auth\Guard`:

```php
$router->get('/account', ['web', Authenticate::with(redirectTo: '/login'), ...]);    // browsers
$router->get('/me', ['auth', fn (User $user) => $user]);                             // tokens
$router->post('/hooks', [Authenticate::with(guards: [ApiKeyGuard::class]), ...]);      // your own
```

Forms in templates:

```php
<form method="post" action="/login">
    <input type="hidden" name="_token" value="<?= e($csrf) ?>">
    <input name="email" value="<?= e($old('email')) ?>">
    <?php if ($message = $error('email')): ?><p class="error"><?= e($message) ?></p><?php endif ?>
</form>
```

Every template rendered for a request (the page, its layout, its partials) gets these, and pype
writes no HTML for you:

| Variable | What |
|---|---|
| `$csrf` | the CSRF token. Needs the `'web'` stack; using it without one throws and says so |
| `$old('email', default: '')` | what was typed before a failed submission. Dots reach nested input: `$old('lines.3.quantity')` is `lines[3][quantity]` |
| `$error('email')` | the first validation error for that field, or `null` |
| `$errors` | all of them: `['email' => ['...'], ...]` |

Your own data wins if it uses one of these names. Don't assign to `$error` or `$old` in a template
(`$error = ...` replaces the helper): name the result `$message`. `$this->csrf()`, `$this->old()`
and `$this->error()` still work.

A session cookie only authenticates routes in the `'web'` stack, so every cookie-authenticated
form post is CSRF-checked.

### Route files

`$app->routes($file)` loads one file. `$app->routesIn($dir)` loads every `.php` file under a folder,
subfolders included, so a new file needs no line in `bootstrap/app.php`:

```php
$app->routesIn(__DIR__ . '/../routes');
```

```
routes/web.php            as it is
routes/api.php            under /api; route names get api. in front
routes/quotes/web.php     as it is
routes/quotes/api.php     under /api; ->name('index') in a quotes. group is api.quotes.index
routes/_helpers.php       skipped: files and folders starting with _ are never loaded
routes/schedule.php       skipped: the schedule commands read it
```

Only the file name `api.php` is special. Every other file is loaded as it is, and its own groups
decide the path, stages and name prefix. Files load in alphabetical order, folder by folder; that
order only matters for overlapping patterns (`/{a}/edit` and `/quotes/{id}`): a path with no
`{params}` such as `/quotes/new` always wins over `/quotes/{id}`. Two routes with the same name
throw while loading. `php bin/pype optimize` compiles every route, whichever file it came from.

`php bin/pype make:routes quotes` writes `routes/quotes/web.php` (a group in `['web']`) and
`routes/quotes/api.php` (a group in `['auth']`); `--web` or `--api` writes only one. Nothing is
forced: change the stages to whatever that file needs.

### Resource routes

The usual routes of one kind of thing, each with its own stages; leave a key out and that route
doesn't exist:

```php
$router->resource('/items', [
    'index'   => [ResolveListing::class, LoadItems::class, ItemsView::class],                     // GET    /items
    'create'  => [ItemFormView::class],                                                          // GET    /items/new
    'store'   => [ValidateItem::class, CreateItem::class, RedirectOutlet::with('items.index')],   // POST   /items
    'show'    => [LoadItem::class, ItemView::class],                                             // GET    /items/{id}
    'edit'    => [LoadItem::class, ItemFormView::class],                                         // GET    /items/{id}/edit
    'update'  => [LoadItem::class, ValidateItem::class, UpdateItem::class, RedirectOutlet::with('items.show')], // PATCH /items/{id}
    'destroy' => [LoadItem::class, DeleteItem::class, RedirectOutlet::with('items.index')],      // DELETE /items/{id}
]);
```

- Names: `items.index` ... `items.destroy`, after a named group's prefix (and `api.` in an `api.php`
  file). `name: 'stock'` changes the `items` part; `param: 'item'` names the placeholder.
- `apiResource()` is the same without `create` and `edit` (form pages). A key that isn't one of these
  is an error, so a typo (`'delete'`) doesn't quietly drop a route.
- They are ordinary routes: `bin/pype routes` lists them, `routes:check` checks them, and you can still
  add others next to them.

### Redirects

Two ways, depending on who decides:

```php
// The route decides: on success, redirect. {id} comes from the matched route by itself.
$router->post('/quotes/{id}/accept', [LoadQuote::class, AcceptQuote::class, new RedirectOutlet('quotes.show', flash: 'Quote accepted.')]);

// From what the last stage returned (a new record's id):
new RedirectOutlet('quotes.show', fn (Quote $quote) => ['id' => $quote->id])

// A stage decides, and nothing after it runs:
if ($quote === null) {
    return redirect('quotes.index', flash: 'That quote no longer exists.');
}
```

- `$to` is a route name, or a path/URL (starts with `/` or has `://`).
- A string flash is the `status` message (`$this->session()->get('status')`); pass an array for others.
- `RedirectOutlet` only acts on success. Errors pass through (`'web'` sends validation errors back to
  the form), a stage's `redirect()` keeps its own target, and JSON clients get the data, so the same
  route serves a form and the API.
- The flash travels on the response (or, for `RedirectOutlet`, in the context) and the `'web'` stack's
  `StartSession` writes it to the session. On a route without a session it is dropped.

### API tokens or JWTs?

The bearer tokens `/api/auth/login` hands out are **API tokens**: random strings whose hash is stored
in `api_tokens`. Each request costs one indexed lookup, and they can be revoked at once (logout,
"sign out everywhere", a stolen token). Keep them for your own clients (SPA, mobile app, CLI).

**JWTs** (`Pype\Auth\JwtGuard`) carry signed claims and are checked without a token lookup, but
can't be revoked before they expire. Use them for tokens from an identity provider (Auth0,
Cognito, Keycloak, Firebase: RS256 with their public key), for services that check each other's
tokens, or short-lived stateless access. Add the guard and configure `jwt` in `config/auth.php`:

```php
'guards' => [Pype\Auth\SessionGuard::class, Pype\Auth\JwtGuard::class, Pype\Auth\TokenGuard::class],

fn (Pype\Auth\Jwt $jwt, User $user) => ['token' => $jwt->issue(['sub' => (string) $user->id])];  // issue (HS256)
$router->get('/me', ['auth', fn (User $user, Pype\Auth\JwtClaims $claims) => ...]);          // verify
```

The guard accepts only the algorithm your configured key allows (so `alg: none` and key
confusion fail), checks `exp`/`nbf` (with `leeway`), and `iss`/`aud` when set. It loads the user
by `sub`; with `'user' => 'claims'` it builds a `Pype\Auth\JwtUser` from the token instead
(tokens from a provider whose users aren't in your table). Keep lifetimes short.
Providers publish their keys as a JWKS URL; pype doesn't fetch it (yet): convert the key(s) to
PEM and set `public_key` (`['kid' => PEM, ...]` for several), and update it when the provider
rotates keys.

## Validation

Validate as the first stage of a route. Bad input is a 422 (or, in the `'web'` stack, back to
the form with errors); good input arrives as `ValidatedInput`:

```php
$router->post('/register', [
    Validate::with([
        'name' => 'required|string|max:100',
        'email' => 'required|email|unique:users',
        'password' => 'required|string|min:8|confirmed',
        'terms' => 'accepted',
    ], messages: ['email.unique' => 'That email is already registered.']),
    Register::class,
]);
```

| Kind | Rules |
|---|---|
| Presence | `required`, `nullable`, `required_if:type,company`, `required_with:password` |
| Types | `string`, `int`, `numeric`, `bool`, `array`, `accepted`, `json` |
| Size | `min:n`, `max:n`, `between:a,b` (length, value, item count, or KB for files), `digits:n` |
| Format | `email`, `url`, `ip`, `uuid`, `alpha`, `alpha_num`, `alpha_dash`, `regex:/.../`, `date`, `date:d/m/Y` |
| Compare | `in:a,b`, `not_in:a,b`, `same:field`, `different:field`, `confirmed`, `before:` / `after:` / `before_or_equal:` / `after_or_equal:` (a date, `today`, or another field) |
| Files | `file`, `image`, `mimes:pdf,jpg` (the real type, not what the browser claims) |
| Database | `unique:users,email`, `exists:categories,id`; `{id}` is the route param, so `unique:users,email,{id}` lets an edit form keep its own email |

- **Arrays:** `'tags.*' => 'string|max:20'`, `'items.*.qty' => 'required|int|min:1'`; errors are keyed `items.0.qty`.
- **Your own rules:** a closure `fn ($value, $field, $data) => $ok ? null : 'message'`, or a class implementing
  `Pype\Validation\Rule`. Scope database rules with objects: `new Unique('categories', 'name', where: ['user_id' => $user->id])`.
- **A regex containing `|`** must use the array form: `['regex:/^(cat|dog)$/']`.

Numbers: `min:`/`max:` are inclusive (`min:0.01` means ≥ 0.01; there is no `gt`). "Unique per user"
(`sku` unique within one user's items): `unique:items,sku,{id},id,user_id={user.id}` (see "Roles,
ownership and per-user limits"), backed by a unique index on `(user_id, sku)`.

### Tidy the input first: `prepare()`

Forms with rows (the lines of a quote) send the rows the user left empty too. Drop them in the
`Validate` subclass, before the rules run, and **keep the keys**:

```php
final class ValidateQuote extends Validate
{
    protected function prepare(array $input): array
    {
        $input['lines'] = array_filter($input['lines'] ?? [], fn (array $row) => array_filter($row) !== []);

        return $input;
    }

    protected function rules(): array
    {
        return ['lines' => 'required|array|min:1', 'lines.*.item_id' => 'required|int', 'lines.*.quantity' => 'required|int|min:1'];
    }
}
```

- An error names the row the browser shows: a bad quantity in the third row is `lines.2.quantity`, even
  with row 1 empty, so `$error('lines.2.quantity')` sits under the right row.
- The request isn't changed: a failed form comes back exactly as typed (`$old('lines.2.quantity')`), and
  later stages still see what was sent.
- `ValidatedInput` gets the prepared data, numbered again where a list lost rows (`0, 2, 4` → `0, 1, 2`),
  so the next stage loops over clean rows. Arrays keyed on purpose (`prices[17]`) keep their keys.

## Forgotten passwords and email verification

Both flows are app code you can change (routes in `routes/web.php`, stages in
`app/Stages/Web`, emails in `app/Mail`, pages in `resources/views/auth`). The parts that are
easy to get wrong are in the framework:

- **Password reset:** `/password/forgot` emails a link; `/password/reset/{token}` sets a new
  password. `Pype\Auth\PasswordResets` stores only a hash of the token, accepts it once and for
  60 minutes, sends at most one link a minute per user, and revokes the user's API tokens after a
  reset. The form answers the same whether or not the email has an account.
- **Email verification:** the welcome email links to a signed URL (`ValidateSignature`, valid
  for a day, tied to the address so changing the email voids it). `/account` shows a reminder
  with a "send it again" button. To require a verified email for some pages, add
  `new Pype\Auth\EnsureEmailIsVerified(redirectTo: '/account')` to their group (API clients get a 403).

- **Remember me:** the login checkbox keeps a device signed in for 30 days after its session
  ends. Each device has its own row in `remember_tokens` (only a hash of the cookie's secret), the
  cookie is swapped on every use, a replayed old copy signs out all remembered devices, and
  logging out or resetting the password forgets them.

Settings in `config/auth.php`: `reset_ttl` (minutes), `reset_throttle` (seconds),
`remember_table` (null turns "remember me" off), `remember_days`.

## Encryption

For data you must read back (a user's OAuth tokens, an API key you store for them):

```php
fn (Pype\Security\Encrypter $encrypter) => $db->table('integrations')->insert([
    'user_id' => $user->id,
    'token' => $encrypter->encrypt($oauthToken),   // decrypt() throws if it was changed
]);
```

It uses libsodium with your `APP_KEY`. After changing the key, put the old one in
`APP_PREVIOUS_KEYS` so existing values still decrypt. Passwords are hashed, never encrypted.

## Mail

```php
fn (Mailer $mailer, User $user) => $mailer->send(
    Message::to($user->email, $user->name)
        ->subject('Your receipt')
        ->view('emails/receipt', ['user' => $user])   // resources/views/emails/receipt.php
        ->attach($pdfPath),
);
```

For emails sent from several places, write a class (see `app/Mail/WelcomeMail.php`) and send
`new WelcomeMail($user)`. A plain-text version is made from the HTML unless you call `->text()`.

`$mailer->later(...)` sends after the response has reached the browser (under PHP-FPM and
OpenSwoole), so a slow mail server never slows the page; failures are logged.
`$mailer->send(...)` sends now and throws `Pype\Mail\MailException` if it can't.

Configure with the usual variables: `MAIL_DRIVER=smtp`, `MAIL_HOST`, `MAIL_PORT`,
`MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` (tls/ssl/none), `MAIL_FROM_ADDRESS`. The
default `MAIL_DRIVER=log` writes emails to the log (see them in the log viewer); tests use
`array` and read them with `$app->container->get(Pype\Mail\Transport::class)->messages()`. To see
emails rendered locally, run [Mailpit](https://mailpit.axllent.org) and use `MAIL_HOST=127.0.0.1`,
`MAIL_PORT=1025`, `MAIL_ENCRYPTION=none`. Sending goes through PHPMailer.

**The dev mailbox.** With `APP_DEBUG=true`, every message the app sends is also kept (as a file in
`storage/mail`, newest 100) and listed at `/_pype/mail`: subject, recipients, the HTML in a sandboxed
frame, the text, and every link in it, clickable, so you can follow a signed link (a quote for a
customer, a password reset) without a mail server. It works with any driver except `array`/`null`.
With `MAIL_DRIVER=log` the page says plainly that nothing was delivered, and `php bin/pype serve` prints
it at start-up with the mailbox's address. Settings in `config/mail.php` `mailbox` (`MAIL_MAILBOX` turns
it off); outside debug it only runs when enabled and given `stages` that guard it, like the log viewer.

## Roles, ownership and per-user limits

pype has no roles or policy system; these are stages you write (a few lines each).

**Records that belong to a user:** load them in a stage, and answer **404 (not 403) when it
isn't theirs**, so ids don't leak. Later stages read the record from the context:

```php
#[Requires(User::class)]
#[Provides(Quote::class)]
final class LoadQuote implements Stage
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $quote = $this->db->table('quotes')->where('id', (int) $request->param('id'))->as(Quote::class)->first();
        $user = $request->context()->get(User::class);

        if ($quote === null || $quote->user_id !== $user->id) {
            throw HttpException::notFound('Quote not found');
        }

        $request->context()->put($quote);

        return $next($request);
    }
}

$router->group('/quotes/{id}', ['auth', LoadQuote::class], fn (Router $r) => $r->get('/', [QuoteJson::class]));
```

**Admins:** a column (`users.role` or `is_admin`), a stage that checks it after `Authenticate`,
and a named stack: `$app->stack('admin', ['web', Authenticate::with(redirectTo: '/login'), EnsureAdmin::class])`.

**Per-user rate limits:** `Throttle` counts per IP and route by default. `by:` counts per signed-in
user instead (all their devices together; guests fall back to their IP), and `key:` lets several
routes share one counter (otherwise `/quotes/1/send` and `/quotes/2/send` count separately):

```php
Throttle::with(20, 3600, by: User::class, key: 'send')   // 20 sends an hour per user, quotes and invoices together
```

`by:` names a context entry with an `id` (`Auth::USER` means the configured user class).

**Unique (or existing) per company / per user:** rules can read the signed-in user. In a string rule,
`col=value` pairs narrow the check and `{user.prop}` is a property of the user `Authenticate` put in
the context:

```php
Validate::with(['sku' => 'required|unique:items,sku,{id},id,company_id={user.company_id}'])   // per company; {id} skips the row being edited
Validate::with(['item_id' => 'required|exists:items,id,company_id={user.company_id}'])       // only this company's items
```

For anything more, override `rulesFor(Request $request)` in a `Validate` subclass and build rule
objects from the context: `new Unique('items', 'sku', where: ['company_id' => $user->company_id])`.
A unique index on the columns still backs it up (two requests at once).

## Cache

Type-hint `Pype\Cache\Cache` (or PSR-16's `Psr\SimpleCache\CacheInterface`):

```php
fn (User $user, Cache $cache, Reports $reports) => $cache->remember(
    "totals.{$user->id}.2026-10", 3600, fn () => $reports->totals($user->id, '2026-10'),
);
```

| Call | Does |
|---|---|
| `get`, `set($key, $value, $ttl)`, `has`, `delete`, `clear` | PSR-16; `$ttl` in seconds, a `DateInterval`, or `null` for no expiry. `null` values are cached too |
| `remember($key, $ttl, fn)` | the cached value, or the closure's result, cached |
| `add($key, $value, $ttl)` | only if missing; atomic, so of two racing requests exactly one gets `true` |
| `increment($key, $by)` / `decrement` | atomic counter, starts from 0 |
| `pull`, `forever` | get-and-delete; set with no expiry |
| `lock('send-invoices', 120)->run(fn)` | run while holding a lock (returns `null` if someone else holds it). Also `->get()` / `->release()` |
| `stale($key, fresh: 60, stale: 600, fn)` | serve a slightly old value and refresh it **after the response** has been sent; only one request refreshes |
| `group("user.{$id}")` | the same API, scoped to a group; `->flush()` empties the whole group with one write |

**Keys:** PSR-16 reserves `{}()/\@:`, so separate parts with dots: `totals.5.2026-10`. Every key
gets `CACHE_PREFIX` (default `pype.`), and `clear()` / `php bin/pype cache:clear` only remove
keys with it.

**Clearing what changed:** put a user's cached data in their group and flush it wherever the
data changes:

```php
$cache->group("user.{$user->id}")->remember('dashboard', 3600, fn () => ...);   // reading
$cache->group("user.{$user->id}")->flush();                                       // after a write
```

**Drivers** (`CACHE_DRIVER`):

| Driver | Get (hit) | Use |
|---|---|---|
| `file` (default) | ~1.4 µs | one PHP file per key in `storage/cache`; opcache keeps hits in memory. Needs nothing |
| `apcu` | ~0.4 µs | **recommended** on a single server: shared memory, no disk at all |
| `database` | a query | shared by several servers; run `php bin/pype migrate` for the `cache` table |
| `array` / `null` | | tests (phpunit.xml uses `array`) / caching off |

Things to know about **APCu**:
1. **`php bin/pype cache:clear` can't clear it.** The CLI has its own APCu memory, separate from
   PHP-FPM's, so the command says so instead of pretending. Reload PHP-FPM to clear it (deploys
   usually do); under OpenSwoole, restart the server.
2. **It is per server** (so is `file`). Behind a load balancer, use `database`.
3. **It has a fixed size**, `apc.shm_size` (32 MB by default). When it is full, old entries are
   evicted; raise it if your cache is big.
4. **Install it** with `pecl install apcu` or `apt install php-apcu`. For CLI use (commands,
   tests), also set `apc.enable_cli=1`. Without the extension, `CACHE_DRIVER=apcu` fails with a
   clear error.

With `file` under OpenSwoole, set `opcache.file_update_protection=0` in php.ini so opcache
serves cache files a worker wrote itself (safe: files appear by atomic rename). Under PHP-FPM
nothing is needed.

## Queue

Slow or unreliable work (emails, exports, calls to other services) can run in a **job** outside
the request. A job is a plain class: its constructor takes the data (ids, strings, arrays; no
services), and `handle()` gets services injected by type:

```php
final class SendReceipt
{
    public int $tries = 3;               // optional (default 1)
    public array $backoff = [10, 60];    // optional: seconds before each retry

    public function __construct(public readonly int $orderId) {}

    public function handle(Orders $orders, Mailer $mailer): void
    {
        $mailer->send(new ReceiptMail($orders->find($this->orderId)));
    }

    public function failed(Throwable $e): void {}   // optional: after the last try
}

fn (Queue $queue, Order $order) => $queue->push(new SendReceipt($order->id));   // Pype\Queue\Queue
$queue->push(new SendReceipt($id), 'mail');         // onto the 'mail' queue
$queue->later(300, new SendReceipt($id));           // in 5 minutes (seconds or DateInterval)
$mailer->queue(new WelcomeMail($user));             // email through the queue: 3 tries, waiting 30 s then 120 s
```

`QUEUE_DRIVER=sync` (the default) runs jobs right away, so nothing needs starting while you
develop. In production set `QUEUE_DRIVER=database` (run `php bin/pype migrate` for the `jobs`
tables) and keep a worker running:

```bash
php bin/pype queue:work                          # default queue; Ctrl+C stops after the current job
php bin/pype queue:work --queue=mail,default     # 'mail' first
php bin/pype queue:failed                        # jobs that gave up, with their error
php bin/pype queue:retry 12                      # or: queue:retry all
php bin/pype queue:restart                       # on deploy: workers stop after their job
```

Workers are long-running processes, so they need a supervisor that starts them again (they
stop on `queue:restart`, `--max-jobs`, `--max-time`, `--memory`, or a job running past
`--timeout`). With systemd:

```ini
# /etc/systemd/system/myapp-queue.service
[Service]
User=www-data
WorkingDirectory=/var/www/myapp
ExecStart=/usr/bin/php bin/pype queue:work --max-time=3600
Restart=always

[Install]
WantedBy=multi-user.target
```

Run as many workers as you like (a `@` template unit, or several services): each job is
handed to exactly one of them. Jobs are stored with `serialize()`, so change a job class's
constructor carefully while old jobs are still waiting.

## Schedule

Recurring tasks live in `routes/schedule.php`:

```php
$schedule->command('reports:send')->weeklyOn(1, '08:00');           // a bin/pype command
$schedule->call(fn (SessionStore $s) => $s->gc())->hourly();        // a closure, services injected
$schedule->job(new SyncRates())->everyFifteenMinutes();             // pushed onto the queue
$schedule->call(...)->dailyAt('02:00')->withoutOverlapping()->onOneServer();
```

Frequencies: `everyMinute`, `everyFiveMinutes` (also Ten, Fifteen, Thirty), `hourly`,
`hourlyAt(17)`, `daily`, `dailyAt('13:00')`, `weekdays()`, `weekly`, `weeklyOn(1, '8:00')`,
`monthly`, `monthlyOn(15, '6:00')`, or any `cron('*/10 9-17 * * 1-5')`; `timezone()` per task
or for the whole file. `withoutOverlapping()` skips a run while the last one is still going;
`onOneServer()` runs it on only one of several servers (needs `CACHE_DRIVER=database`).

One cron line runs it all:

```
* * * * * cd /var/www/myapp && php bin/pype schedule:run >> /dev/null 2>&1
```

No cron (a container, your laptop)? `php bin/pype schedule:work` does the same in the
foreground. `php bin/pype schedule:list` shows every task, and failures go to the log.

## Logs and errors

Inject the logger anywhere (it's PSR-3, so Monolog-style calls work):

```php
fn (Psr\Log\LoggerInterface $log, User $user) => $log->warning('Card declined for {user}', ['user' => $user->id]);
```

Entries go to `storage/logs/pype-YYYY-MM-DD.log`, one JSON line each, tagged with the request id
so everything from one request can be found together. Unexpected errors are logged
automatically, and in development each request gets a line too (`GET /expenses 200 3.1 ms`).

**Sending logs elsewhere:** set `LOG_FORWARD=stderr` (or `stdout`, `syslog`) and a log agent
or your platform picks the JSON lines up for Datadog, Loki, Papertrail and the like. For a
service with a PHP SDK, put a PSR-3 logger class in `log.forward` in `config/log.php` (for
example Monolog with a Sentry handler) and raise `LOG_FORWARD_LEVEL` to `warning` so only
problems are sent. The file is always written too, so the viewer and error page keep working.

**Log viewer:** open `/_pype/logs` while `APP_DEBUG=true` for a live view (new entries appear
within a second) with filters for level, request id and text. It's off in production unless you
enable it and guard it with stages in `config/log.php` (`'stages' => ['web', 'auth', EnsureAdmin::class]`).

**Error page:** with `APP_DEBUG=true`, an unexpected error in the browser shows the exception,
the code around each line of the trace, the route and its stages, the request (passwords,
tokens and cookies hidden), and the log lines from that request. API clients still get JSON, and
production shows a plain error.

**Your API's error wording:** pype's own errors (no route, malformed JSON, failed validation,
unauthenticated, a 500...) can be reworded in `config/app.php` `errors` without touching the
JSON shape, and `Validate::with($rules, status: 400)` changes a validation failure's status
(422 by default).

## Links

Name the routes you link to, then build links with `url()` instead of writing paths by hand:

```php
$router->get('/expenses/{id}/edit', [LoadExpense::class, View::with('expenses/form')])->name('expenses.edit');

$router->group('/admin', ['auth'], function (Router $r) {
    $r->get('/users', ListUsers::class)->name('users');           // named admin.users
}, name: 'admin.');
```

```php
url('expenses.edit', ['id' => 3])              // /expenses/3/edit
url('expenses.index', ['month' => '2026-10'])  // /expenses?month=2026-10 (extra params → query)
url('home', absolute: true)                    // http://localhost:8000/ (APP_URL)
Response::redirect(url('dashboard'), 303)
```

In templates: `<a href="<?= url('expenses.edit', ['id' => $expense->id]) ?>">Edit</a>`. A missing
param or an unknown name is an error, so a renamed route can't leave broken links behind.
Prefer injecting a service? `fn (Pype\Routing\Urls $urls) => $urls->to('home')`.

**Signed links** (password reset, email verification, unsubscribe) can't be changed or reused
after they expire:

```php
$link = signed_url('password.reset', ['user' => $user->id], expiresIn: 3600);   // absolute

$router->get('/password/reset/{user}', [new ValidateSignature(), ShowResetForm::class])
    ->name('password.reset');                  // 403 if tampered with or expired
```

They're signed with `APP_KEY` (HMAC-SHA256), which `create-project` sets for you
(`php bin/pype key:generate` to make one; `--force` replaces it, which invalidates old links).

## Database

SQLite by default (`database/database.sqlite`, WAL mode for fast concurrent reads). Switch to
MySQL or Postgres with `DB_CONNECTION=mysql|pgsql` in `.env` (see `.env.example` for every
setting). Type-hint `Pype\Database\Connection` anywhere:

```php
$db->table('users')->where('id', $id)->as(User::class)->first();
$db->table('posts')->insert(['title' => 'Hello']);                       // the new id
$db->table('posts')->insertReturning(['title' => 'Hi'], ['id', 'created_at']);  // columns the database filled
$db->table('posts as p')
    ->join('users as u', 'u.id', '=', 'p.user_id')
    ->select('u.name')->selectRaw('COUNT(*) AS posts')
    ->groupBy('u.id')->orderBy('posts', 'desc')->get();
$db->table('orders')->where('user_id', $id)->sum('total');
$db->select('SELECT * FROM users WHERE email LIKE ?', ['%@example.com']);
$db->transaction(fn (Connection $db) => ...);
```

Migrations live in `database/migrations` and run in filename order. `make:migration` writes a
PHP migration using the schema builder, which produces the right SQL for SQLite, MySQL and Postgres:

```php
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('posts', function (Table $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->text('body')->nullable();
            $t->boolean('published')->default(false);
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(Schema $schema): void   // undoes up(), for migrate:rollback
    {
        $schema->dropIfExists('posts');
    }
};
```

Columns: `id`, `foreignId`, `string`, `text`, `integer`, `bigInteger`, `boolean`, `decimal`,
`date`, `timestamp`, `timestamps`, `json`, `longText`. Modifiers: `nullable`, `default`, `useCurrent`,
`unique`, `primary`, `index`, `check`, `constrained`, `withoutIndex`.

`constrained()` also indexes the column on SQLite and Postgres, which (unlike MySQL) don't index
foreign keys themselves: without it, "the lines of this quote" (`WHERE quote_id = ?`) and every
cascade check on a delete scan the whole table. It is skipped when a composite index you declared
already starts with the column (`$t->unique(['company_id', 'sku'])` covers `company_id`); opt out
with `->withoutIndex()`. Dropping the column again (`down()`) drops that index first.

`$schema->table(...)` adds/renames/drops
columns and indexes, and `$schema->raw($sql)` runs anything else. Prefer plain SQL? `make:migration
add_search_view --sql` writes a `.sql` file instead; both kinds can sit side by side. In a
`.sql` file, the SQL after a `-- down` line undoes the part above it.

```bash
php bin/pype migrate                  # run what's pending (one "batch")
php bin/pype migrate:rollback         # undo the last batch, newest first
php bin/pype migrate:rollback --step=1  # or just the last migration (any number)
php bin/pype migrate:status           # what ran, in which batch
php bin/pype migrate:fresh            # drop every table and migrate again (development)
```

A migration without `down()` (or a `-- down` section) can't be rolled back: rollback stops
there and says so. `migrate:fresh` doesn't need `down()`, and refuses to run when `APP_DEBUG`
is off unless you add `--force`. On MySQL each schema change is committed as it runs, so a
migration that fails halfway may leave part of its changes behind; SQLite and Postgres undo the
whole file. Once a migration has run in production, change things with a new migration rather
than rolling back: rolling back drops data.

### Pagination

```php
fn (Request $request, Connection $db) => $db->table('quotes')->where('status', 'sent')->latest()->paginate(25, $request)
```

- Given the `Request`, the page number comes from `?page=` (anything odd is page 1), and past the end
  (`?page=99999`) you get the last page.
- Return the `Page`: JSON clients get `{"data": [...], "meta": {"pagination": {page, per_page, total, last_page}}}`;
  a view gets the items as its data and the `Page` itself as `$page`. Put it in an array or the context
  yourself and it is the same object.
- Links keep the request's other query parameters (`?status=`, `?q=`, `?month=`), so filters survive paging:

```php
<nav class="pages">
    <?php if ($url = $page->previousUrl()): ?><a href="<?= e($url) ?>">← Previous</a><?php endif ?>
    <?php foreach ($page->links() as $link): ?>
        <?php if ($link->gap): ?><span>…</span>
        <?php elseif ($link->current): ?><strong aria-current="page"><?= $link->number ?></strong>
        <?php else: ?><a href="<?= e($link->url) ?>"><?= $link->number ?></a>
        <?php endif ?>
    <?php endforeach ?>
    <?php if ($url = $page->nextUrl()): ?><a href="<?= e($url) ?>">Next →</a><?php endif ?>
</nav>
```

  `links()` gives the first and last page and two either side of the current one (`links(1)` for one):
  `1 … 7 8 [9] 10 11 … 9000`. pype ships no markup; this loop is yours.
- **Big tables.** The total is a `COUNT(*)` over the whole query, joins included. Give a cheaper one, or a
  number you have: `paginate(25, $request, count: $db->table('quotes')->where('company_id', $id))`,
  `count: $cache->remember(...)`. Or skip the total: `simplePaginate(25, $request)` fetches one extra row
  to know whether there's a next page, runs no count, and has only `previousUrl()`/`nextUrl()`
  (`{"has_more": true}` in JSON).
- `paginate(25, 3)` (an int) still works; its links are just `?page=N`. Another parameter name:
  `paginate(25, $request, pageName: 'p')`.

## Factories and seeders

**Factories** make rows for tests and demo data, one class per table in `database/factories/`
(`php bin/pype make:factory Post`):

```php
final class UserFactory extends Factory
{
    protected string $table = 'users';
    protected ?string $class = User::class;           // create() returns Users (null: arrays)

    protected function definition(Fake $fake): array
    {
        return ['name' => $fake->name(), 'email' => $fake->email(), 'password' => self::password()];
    }
}

$ada = UserFactory::new($db)->create(['name' => 'Ada']);      // inserted, id and defaults filled in
$users = UserFactory::new($db)->count(10)->create();           // list<User>
$row = UserFactory::new($db)->make();                          // an array, not inserted
PostFactory::new($db)->count(5000)->state(['user_id' => $ada->id])->insert();   // bulk: few statements, returns the count
```

`state([...])` or `state(fn (array $row, int $i) => [...])` changes columns,
`sequence(['role' => 'admin'], ['role' => 'member'])` takes turns, `afterCreating(fn ($user) => ...)`
adds related rows, and a column given as a closure (`'user_id' => fn () =>
UserFactory::new($this->db)->create()->id`) is only created when nothing else supplied it.
`self::password()` hashes "password" once per run, so seeding thousands of users stays fast.

`Pype\Database\Fake` gives names, emails (never repeated within a run), words, sentences,
numbers, dates, UUIDs, `pick()` from a list and `unique()`; `new Fake(seed: 42)` repeats exactly.
`unique()` never repeats a value; once a list is used up (about 500 words) it numbers them instead
(`desk2`, `ada.smith2@example.test`), so a seeder can ask for thousands. `create()` returns your
`$class` objects, or, for a factory without one, `Pype\Database\Row` objects: `$row->id` and
`$row['id']` both work, and a column that isn't there warns instead of quietly being `null`.
For realistic, local data (South African addresses, phone and ID numbers, 75 locales),
`composer require --dev fakerphp/faker` (MIT, ~12 MB) and call it inside `definition()`:
`\Faker\Factory::create('en_ZA')->idNumber()`. It's dev-only, so don't use it in seeders that
must run in production.

**Seeders** fill the database: `database/seeders/DatabaseSeeder.php` runs with
`php bin/pype db:seed` (or `migrate:fresh --seed`; `--class=OtherSeeder` for another). `run()`
gets services injected like a command, plus `Input` for options (`db:seed --users=50`), and
`$this->call(OtherSeeder::class)` runs others. The skeleton's seeder creates
`demo@example.com` / `password` and ten more users. With `APP_DEBUG=false`, `db:seed` needs
`--force`, so demo data never lands in production by accident.

## Testing

`composer test` runs PHPUnit. Tests extend `Tests\TestCase`, which boots the app on a fresh
in-memory SQLite database (migrated) and gives you `$this->app` and `$this->client`, a
`Pype\Testing\TestClient` that runs requests in-process (no server) and keeps cookies like a
browser:

```php
public function test_creating_a_post(): void
{
    $db = $this->app->container->get(Connection::class);
    $ada = UserFactory::new($db)->create();                                   // setup: a factory, not HTTP
    $token = $this->app->container->get(Auth::class)->issueToken($ada)->plainText;
    $api = $this->client->withHeaders(['Authorization' => "Bearer {$token}"]);

    $res = $api->post('/posts', ['title' => 'Hello']);                       // get/post/put/patch/delete
    $this->assertSame(201, $res->status(), $res->body());
    $this->assertSame('Hello', $res->json('data.title'));                    // dot paths into the JSON
    $this->assertSame(1, $db->table('posts')->count());
}
```

- **Stages on their own:** `Pype\Testing\Chain` runs a list of stages the way a route would (container,
  `::with()` arguments, stacks, outlets), without HTTP. Fill the context with what earlier stages would
  have put there, then look at what these left:

  ```php
  $result = Chain::of([LoadQuote::class, PrepareQuote::class], $this->app)
      ->with($user)                 // as if Authenticate had run
      ->params(['id' => $quote->id])
      ->run('GET /quotes/' . $quote->id);

  $this->assertTrue($result->reachedEnd());                        // every stage called $next
  $this->assertSame('Q-0001', $result->context()->get(Quote::class)->number);
  ```

  Exceptions reach the test (`expectException(HttpException::class)` for a 404); a stage that stops with
  `redirect()` shows in `$result->status()`; `$result->data()` / `->body()` give what the last stage
  returned. See the skeleton's `tests/Unit/FindUserTest.php`.
- **Browsers:** `$this->client` keeps the session cookie between requests.
  `$this->client->submit('/items/new', '/items', ['name' => 'Desk'])` loads the form page, takes its
  CSRF token and posts like a browser (the page is the Referer, so a failed validation comes back to
  it); add `'_method' => 'PATCH'` for other verbs. `$this->client->actingAs($user)` signs in without
  the login form. `withoutCookies()` is a second browser. See `tests/Feature/WebAuthTest.php`.
- **Assertions** on every response, chainable: `assertOk()`, `assertStatus(419)`, `assertCreated()`,
  `assertNoContent()`, `assertUnauthorized()`, `assertForbidden()`, `assertNotFound()`,
  `assertUnprocessable()`, `assertRedirect('/account')`, `assertSee('<h1>Ada</h1>')` /
  `assertDontSee()` (the text as it appears in the HTML), `assertJson(['data' => ['id' => 1]])` (a subset),
  `assertJsonPath('data.name', 'Ada')`, `assertHeader()`, `assertCookie()`, and `assertInvalid('email')`
  (a validation error for that field, from an API 422 or sent back to a form):

  ```php
  $this->client->submit('/login', '/login', ['email' => 'ada@example.com', 'password' => 'nope'])
      ->assertRedirect('/login')
      ->assertInvalid('password');
  ```
- **`phpunit.xml`** sets the test environment: in-memory SQLite, `MAIL_DRIVER=array`,
  `CACHE_DRIVER=array`, `QUEUE_DRIVER=sync` (jobs run at once), a fixed `APP_KEY` (signed links),
  and logs in `storage/logs/testing`.
- **Mail:** `$this->app->container->get(Pype\Mail\Transport::class)->messages()` lists what was sent.
- **Queued jobs:** to check what was pushed instead of running it, bind an array queue first:
  `$this->app->container->instance(Pype\Queue\Queue::class, $queue = new Pype\Queue\ArrayQueue())`,
  then `$queue->pushed(SendReceipt::class)`.
- **Time-based code:** `Fake`, `Cache` and the queue take a clock in their constructors for tests.

## Running in production

The default is nothing special: point nginx (or Apache) at `public/` with PHP-FPM. On each
deploy, after `composer install --no-dev --classmap-authoritative`, run:

```bash
php bin/pype optimize      # compiles the route tables to bootstrap/cache/routes.php
php bin/pype cache:clear   # if cached data changed shape (not for CACHE_DRIVER=apcu: reload PHP-FPM)
php bin/pype queue:restart # queue workers pick up the new code
```

Also add the scheduler's cron line and a supervised `queue:work` (see Queue and Schedule).

PHP-FPM then reads the compiled tables (kept in memory by opcache) instead of rebuilding them on
every request. Forgetting it after a route change is safe: pype notices the file no longer
matches and builds the tables itself. `php bin/pype optimize:clear` removes it.

Or let OpenSwoole serve the app: it boots once and keeps it in memory, skipping PHP-FPM's
per-request start-up. Install the extension (`pecl install openswoole`), then:

```bash
APP_SERVER=swoole php bin/pype serve --host=0.0.0.0 --port=8080
```

It starts one worker per CPU core (the container's CPU limit, if it has one): best when requests
are CPU-bound, as with SQLite. If requests mostly wait on a network database, an outside API or
password hashing, try `--workers` at 2-4 per core and measure.

It serves `public/` files too, so no nginx is needed (put one in front for TLS if you like). pype
serves them itself (`SwooleServer::serveStatic()`: GET/HEAD only, nothing outside `public/`, never PHP
files or dotfiles, `Last-Modified` and 304). Swoole's own static handler is off because, in Swoole
6.2.3, it left the connection corked: every later response on it, pages included, waited about 200 ms.
Set `app.swoole.static_handler` to `true` to turn it back on (e.g. if nginx serves `public/` anyway
and you want to test a newer Swoole). Any other server setting goes in `app.swoole.settings`, passed
through as is (`'package_max_length' => 16 * 1024 * 1024`, timeouts, buffers); `workers`,
`max_request` and `--workers` win over it.
Workers handle one request at a time, so your code runs exactly as under PHP-FPM. Two
differences: code changes need a restart, and anything you store in a static variable or
singleton outlives the request. Keep per-request data on the `Request` (its context), as
pype's own stages do.

## Outlets

Every pipeline ends in an outlet. It can sit anywhere in a stage list (pype moves it to the exit),
and the closest one wins: **route → group → global → app default**.

```php
$app->outlet(MyJson::class);                                      // app default (JsonOutlet otherwise)
$router->group('/admin', ['auth', View::with('admin/page')], ...);  // every route in the group
$router->get('/report.csv', [LoadReport::class, CsvOutlet::class]); // one route
```

**A JSON outlet per kind of thing** is the JSON twin of a page outlet: extend `JsonOutlet` and return
from `data()` what clients may see, read from the context. The stages stay the same for HTML and JSON:

```php
#[Requires(User::class)]
final class UserJson extends JsonOutlet                       // app/Outlets
{
    protected function data(mixed $data, Request $request): mixed
    {
        $user = $request->context()->get(User::class);

        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];   // never the password hash
    }
}

$router->get('/api/users/{id}', [FindUser::class, UserJson::class]);      // JSON
$router->get('/users/{id}/profile', [FindUser::class, UserProfile::class]); // the same stage, a page
```

`data()` gets what the last stage returned too, so it can also reshape a result. Errors and redirects
pass through unchanged.

Custom formats extend `Pype\Outlet\Outlet` (`render()` + `contentType()`), or extend `JsonOutlet` and
override `payload()` to change the JSON envelope. `php bin/pype make:outlet CsvOutlet` scaffolds one.

## Views

Plain PHP templates in `resources/views`, styled by `public/css/app.css` (no build step).

- Quick: `View::with('welcome')` at the end of a route, for a page with nothing to load or whose last
  stage returns an array of variables.
- A page outlet (the usual shape): `php bin/pype make:view QuoteView` writes `app/Views/QuoteView.php`
  and `resources/views/quote.php`. Extend `Pype\View\ViewOutlet`: choose the template, read what the
  stages loaded from the context in `data()`, ask for services in the constructor, and call its
  methods from the template as `$view->total($quote)`.

A route is then stages that load, in order, and the page that shows:

```php
$router->get('/quotes/{id}', [LoadQuote::class, LoadCustomer::class, QuoteView::class]);

final class QuoteView extends ViewOutlet
{
    protected function template(): string { return 'quotes/show'; }

    protected function data(mixed $data, Request $request): array
    {
        return [
            'quote' => $request->context()->get(Quote::class),        // throws if LoadQuote didn't run
            'customer' => $request->context()->get(Customer::class),
        ];
    }
}
```

Each class does one thing and can be tested alone. The same stages serve JSON: end the API route with
a stage that returns the data instead of a page outlet. After a form, end with `RedirectOutlet` (see
Redirects).

```php
<?php $this->layout('layouts/app', ['title' => $data->name]) ?>
<h1><?= e($data->name) ?></h1>
<?= $this->partial('users/_card', ['user' => $data]) ?>
```

Errors on HTML routes render `errors/{status}.php`, falling back to `errors/error.php`.

### Latte or Blade instead of plain PHP

The file extension picks the engine; nothing to configure, and pages can mix them:

| File | Engine | Install |
|---|---|---|
| `quotes/show.php` | plain PHP | nothing |
| `quotes/show.latte` | [Latte](https://latte.nette.org) | `composer require latte/latte` |
| `quotes/show.blade.php` | [BladeOne](https://github.com/EFTEC/BladeOne) (Blade syntax, no dependencies) | `composer require eftec/bladeone` |

```latte
{layout 'layouts/app.latte'}
{block content}
    <h1>{$quote->number}</h1>                                  {* escaped by default *}
    <form method="post">
        <input type="hidden" name="_token" value="{$csrf}">
        <input name="email" value="{$old('email')}">
        {if $error('email')}<p>{$error('email')}</p>{/if}
    </form>
{/block}
```

- Routes and outlets don't change: `View::with('quotes/show')`, or a page outlet's `template()`.
  Without an extension the name takes the first of `.blade.php`, `.latte`, `.php` that exists; write
  `quotes/show.latte` to choose.
- Every engine gets the same variables: your data, `$view`, `$meta`, `$request`, `$csrf`, `$old()`,
  `$error()`, `$errors`. Layouts and includes are the engine's own (`{layout}`/`{include}`,
  `@extends`/`@include`); names are from the views root in all three.
- Latte and BladeOne load only when one of their templates renders, and compile into
  `storage/views` (`app.view_cache`). BladeOne is built fresh per render so its sections never leak
  between requests in a worker. A template whose package is missing says which `composer require` to run.


