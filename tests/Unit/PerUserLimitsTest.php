<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Auth\Auth;
use Pype\Auth\AuthProvider;
use Pype\Database\Connection;
use Pype\Http\Request;
use Pype\Pipeline\Stage;
use Pype\RateLimit\ArrayRateLimiter;
use Pype\RateLimit\RateLimiter;
use Pype\Stages\Throttle;
use Pype\Testing\TestClient;
use Pype\Tests\Support\TestDatabase;
use Pype\Validation\Unique;
use Pype\Validation\Validate;

/** Throttle per user (by:) and shared across routes (key:); unique/exists scoped by the signed-in user. */
final class PerUserLimitsTest extends TestCase
{
    private Application $app;
    private Connection $db;

    protected function setUp(): void
    {
        $this->app = new Application(dirname(__DIR__, 2), ['auth' => ['user' => LimitUser::class]]);
        $this->db = TestDatabase::withAuthTables();
        $this->db->statement('CREATE TABLE items (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, sku VARCHAR(20) NOT NULL)');
        $this->db->table('items')->insertMany([['id' => 1, 'company_id' => 1, 'sku' => 'DESK'], ['id' => 2, 'company_id' => 2, 'sku' => 'CHAIR']]);
        $this->app->container->instance(Connection::class, $this->db);
        $this->app->container->instance(RateLimiter::class, new ArrayRateLimiter());
        $this->app->register(AuthProvider::class);
    }

    private function as(int $id, int $company): TestClient
    {
        return (new TestClient($this->app))->withHeaders(['X-User' => "{$id}:{$company}"]);
    }

    public function test_throttle_by_user_counts_each_user_across_their_ips_and_shares_a_key_between_routes(): void
    {
        $limit = Throttle::with(2, 3600, by: LimitUser::class, key: 'send');
        $this->app->router->post('/quotes/{id}/send', [FakeSignIn::class, $limit, fn () => ['sent' => true]]);
        $this->app->router->post('/invoices/{id}/send', [FakeSignIn::class, $limit, fn () => ['sent' => true]]);

        $ada = $this->as(1, 1);
        $ada->post('/quotes/1/send')->assertOk()->assertHeader('X-RateLimit-Remaining', '1');
        $ada->withHeaders(['X-Forwarded-For' => '10.0.0.9'])->post('/invoices/7/send')->assertOk();   // another route, same counter
        $ada->post('/quotes/2/send')->assertStatus(429);

        $this->as(2, 1)->post('/quotes/1/send')->assertOk();   // someone else has their own
    }

    public function test_throttle_falls_back_to_the_ip_for_guests_and_understands_auth_user(): void
    {
        $this->app->router->post('/maybe', [OptionalSignIn::class, Throttle::with(1, 60, by: Auth::USER), fn () => ['ok' => true]]);
        $guest = new TestClient($this->app);

        $guest->post('/maybe')->assertOk();
        $guest->post('/maybe')->assertStatus(429);
        $this->as(1, 1)->post('/maybe')->assertOk();   // the user is counted on their own
    }

    public function test_unique_scoped_by_the_signed_in_user_in_a_string_rule(): void
    {
        $this->app->router->post('/items', [FakeSignIn::class, Validate::with(['sku' => 'required|unique:items,sku,null,id,company_id={user.company_id}']), fn () => ['ok' => true]]);
        $this->app->router->patch('/items/{id}', [FakeSignIn::class, Validate::with(['sku' => 'required|unique:items,sku,{id},id,company_id={user.company_id}']), fn () => ['ok' => true]]);

        $this->as(1, 1)->post('/items', ['sku' => 'DESK'])->assertUnprocessable()->assertInvalid('sku');   // taken in company 1
        $this->as(1, 1)->post('/items', ['sku' => 'CHAIR'])->assertOk();                                  // CHAIR is company 2's
        $this->as(9, 2)->post('/items', ['sku' => 'DESK'])->assertOk();
        $this->as(1, 1)->patch('/items/1', ['sku' => 'DESK'])->assertOk();                                // its own row is ignored
    }

    public function test_exists_scoped_and_rules_for_a_request(): void
    {
        $this->app->router->post('/lines', [FakeSignIn::class, Validate::with(['item_id' => 'required|exists:items,id,company_id={user.company_id}']), fn () => ['ok' => true]]);
        $this->app->router->post('/copy', [FakeSignIn::class, ScopedSkuRules::class, fn () => ['ok' => true]]);

        $this->as(1, 1)->post('/lines', ['item_id' => 1])->assertOk();
        $this->as(1, 1)->post('/lines', ['item_id' => 2])->assertUnprocessable()->assertInvalid('item_id');   // another company's item

        $this->as(1, 1)->post('/copy', ['sku' => 'DESK'])->assertUnprocessable();
        $this->as(1, 2)->post('/copy', ['sku' => 'DESK'])->assertOk();
    }

    public function test_user_placeholders_need_someone_signed_in(): void
    {
        $request = Request::create('POST', '/items', ['sku' => 'X']);
        $validate = Validate::with(['sku' => 'unique:items,sku,null,id,company_id={user.company_id}']);
        $stage = $this->app->container->makeWith(Validate::class, $validate->args);
        $stage->setContainer($this->app->container);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nobody is signed in on this route');
        $stage->handle($request, fn () => null);
    }
}

final class LimitUser
{
    public function __construct(public readonly int $id, public readonly int $company_id)
    {
    }
}

/** Stands in for Authenticate: "X-User: id:company". */
final class FakeSignIn implements Stage
{
    public function handle(Request $request, Closure $next): mixed
    {
        [$id, $company] = array_map('intval', explode(':', (string) $request->header('x-user', '0:0')));
        $request->context()->put(new LimitUser($id, $company));

        return $next($request);
    }
}

final class OptionalSignIn implements Stage
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $request->header('x-user') === null ? $next($request) : (new FakeSignIn())->handle($request, $next);
    }
}

final class ScopedSkuRules extends Validate
{
    protected function rulesFor(Request $request): array
    {
        $user = $request->context()->get(LimitUser::class);

        return ['sku' => ['required', new Unique('items', 'sku', where: ['company_id' => $user->company_id])]];
    }
}
