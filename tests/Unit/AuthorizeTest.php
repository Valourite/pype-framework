<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Auth\Authorize;
use Pype\Console\Console;
use Pype\Http\Request;
use Pype\Pipeline\Provides;
use Pype\Pipeline\Requires;
use Pype\Pipeline\Stage;
use Pype\Routing\RouteCheck;
use Pype\Testing\TestClient;

/** Authorize: a stage you extend with allows(), filled from the context; 403, 404 or a redirect when false. */
final class AuthorizeTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application(dirname(__DIR__, 2), ['auth' => ['user' => AuthzUser::class]]);
    }

    private function as(string $role, int $team = 1): TestClient
    {
        return (new TestClient($this->app))->withHeaders(['X-User' => "{$role}:{$team}", 'Accept' => 'application/json']);
    }

    public function test_allows_gets_the_user_from_the_context_and_a_false_answer_is_403(): void
    {
        $this->app->router->post('/team/invite', [AuthzSignIn::class, EnsureTeamAdmin::class, fn () => ['invited' => true]]);

        $this->as('admin')->post('/team/invite')->assertOk()->assertJsonPath('data.invited', true);
        $this->as('member')->post('/team/invite')->assertStatus(403)->assertSee('Only team admins can do that.');
    }

    public function test_records_from_a_load_stage_and_route_params_reach_allows_and_404_hides_them(): void
    {
        $this->app->router->patch('/tickets/{id}', [AuthzSignIn::class, AuthzLoadTicket::class, CanEditTicket::class, fn (AuthzTicket $t) => ['id' => $t->id]]);

        $this->as('member', team: 1)->patch('/tickets/1')->assertOk()->assertJsonPath('data.id', 1);
        $this->as('member', team: 2)->patch('/tickets/1')->assertNotFound()->assertSee('Not Found');   // another team's
        $this->as('member', team: 1)->patch('/tickets/2')->assertNotFound();                           // closed (route param id = 2)
    }

    public function test_with_overrides_status_message_and_redirects_browsers(): void
    {
        $this->app->router->get('/admin', [AuthzSignIn::class, EnsureTeamAdmin::with(redirectTo: '/home', message: 'Admins only.'), fn () => 'ok']);
        $this->app->router->get('/hidden', [AuthzSignIn::class, EnsureTeamAdmin::with(status: 404), fn () => 'ok']);

        (new TestClient($this->app))->withHeaders(['X-User' => 'member:1'])->get('/admin')->assertRedirect('/home');
        $this->as('member')->get('/admin')->assertStatus(403)->assertSee('Admins only.');   // JSON clients get the status
        $this->as('member')->get('/hidden')->assertNotFound();
    }

    public function test_routes_check_reads_allows_parameters(): void
    {
        $this->assertSame([[AuthzUser::class, AuthzTicket::class], []], RouteCheck::declarations(CanEditTicket::class));
        $this->assertSame([[AuthzUser::class, 'extra'], ['seen']], RouteCheck::declarations(WithAttributes::class));
        $this->assertSame([[], []], RouteCheck::declarations(GuestsToo::class));   // nullable = optional

        $this->app->router->patch('/good/{id}', [AuthzSignIn::class, AuthzLoadTicket::class, CanEditTicket::class]);
        $this->app->router->patch('/bad/{id}', [AuthzSignIn::class, CanEditTicket::class, AuthzLoadTicket::class]);

        $this->assertSame(
            ['PATCH /bad/{id}: CanEditTicket needs [' . AuthzTicket::class . ']; nothing before it provides it.'],
            $this->app->checkRoutes(),
        );
    }

    public function test_nullable_parameters_are_null_when_missing(): void
    {
        $this->app->router->get('/feed', [GuestsToo::class, fn () => 'ok']);

        (new TestClient($this->app))->get('/feed')->assertOk();
    }

    public function test_allows_can_be_called_directly_and_a_missing_allows_says_so(): void
    {
        $this->assertTrue((new EnsureTeamAdmin())->allows(new AuthzUser(1, 'admin', 1)));
        $this->assertFalse((new EnsureTeamAdmin())->allows(new AuthzUser(1, 'member', 1)));

        $stage = new NoAllows();
        $stage->setContainer($this->app->container);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no allows() method');
        $stage->check(Request::create('GET', '/'));
    }

    public function test_make_authorize_writes_a_working_class(): void
    {
        $base = sys_get_temp_dir() . '/pype-authz-' . bin2hex(random_bytes(4));
        mkdir($base);

        ob_start();
        $code = (new Console(new Application($base)))->run(['pype', 'make:authorize', 'EnsureOwner']);
        ob_end_clean();
        $file = "{$base}/app/Stages/EnsureOwner.php";

        $this->assertSame(0, $code);
        $this->assertStringContainsString('final class EnsureOwner extends Authorize', (string) file_get_contents($file));
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file), $lint, $lintCode);
        $this->assertSame(0, $lintCode, implode("\n", $lint));

        unlink($file);
        rmdir("{$base}/app/Stages");
        rmdir("{$base}/app");
        rmdir($base);
    }
}

final class AuthzUser
{
    public function __construct(public readonly int $id, public readonly string $role, public readonly int $team_id)
    {
    }
}

final class AuthzTicket
{
    public function __construct(public readonly int $id, public readonly int $team_id, public readonly string $status)
    {
    }
}

/** Stands in for Authenticate: "X-User: role:team". */
#[Provides(AuthzUser::class)]
final class AuthzSignIn implements Stage
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (($header = $request->header('x-user')) !== null) {
            [$role, $team] = explode(':', $header);
            $request->context()->put(new AuthzUser(1, $role, (int) $team));
        }

        return $next($request);
    }
}

/** Ticket 1 is open, ticket 2 closed; both team 1's. */
#[Provides(AuthzTicket::class)]
final class AuthzLoadTicket implements Stage
{
    public function handle(Request $request, Closure $next): mixed
    {
        $id = (int) $request->param('id');
        $request->context()->put(new AuthzTicket($id, 1, $id === 2 ? 'closed' : 'open'));

        return $next($request);
    }
}

final class EnsureTeamAdmin extends Authorize
{
    protected ?string $message = 'Only team admins can do that.';

    public function allows(AuthzUser $user): bool
    {
        return $user->role === 'admin';
    }
}

final class CanEditTicket extends Authorize
{
    protected int $status = 404;

    public function allows(AuthzUser $user, AuthzTicket $ticket, int $id, Request $request): bool
    {
        return $ticket->team_id === $user->team_id && $ticket->status !== 'closed' && $id === $ticket->id && $request->method() === 'PATCH';
    }
}

#[Requires('extra')]
#[Provides('seen')]
final class WithAttributes extends Authorize
{
    public function allows(AuthzUser $user): bool
    {
        return true;
    }
}

final class GuestsToo extends Authorize
{
    public function allows(?AuthzUser $user): bool
    {
        return $user === null;
    }
}

final class NoAllows extends Authorize
{
}
