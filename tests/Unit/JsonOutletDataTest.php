<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use Closure;
use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Exceptions\HttpException;
use Pype\Http\Request;
use Pype\Outlet\JsonOutlet;
use Pype\Pipeline\Stage;
use Pype\Testing\TestClient;

/** A JsonOutlet subclass answers from the context, the way a page outlet does for HTML. */
final class JsonOutletDataTest extends TestCase
{
    private TestClient $client;

    protected function setUp(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->router->get('/members/{id}', [LoadMember::class, MemberJson::class]);
        $app->router->get('/plain', [fn () => null, JsonOutlet::class]);
        $this->client = new TestClient($app);
    }

    public function test_the_outlet_shapes_what_the_stages_loaded(): void
    {
        $this->client->get('/members/7')->assertOk()
            ->assertJson(['data' => ['id' => 7, 'name' => 'Member 7']])
            ->assertDontSee('secret');   // the outlet decides what clients see
    }

    public function test_errors_still_come_out_as_json_errors(): void
    {
        $this->client->get('/members/404')->assertNotFound()->assertJsonPath('error.status', 404);
    }

    public function test_the_plain_outlet_is_unchanged(): void
    {
        $this->client->get('/plain')->assertNoContent();
    }
}

final readonly class Member
{
    public function __construct(public int $id, public string $name, public string $password)
    {
    }
}

final class LoadMember implements Stage
{
    public function handle(Request $request, Closure $next): mixed
    {
        $id = (int) $request->param('id');
        if ($id === 404) {
            throw HttpException::notFound();
        }
        $request->context()->put(new Member($id, "Member {$id}", 'secret'));

        return $next($request);
    }
}

final class MemberJson extends JsonOutlet
{
    protected function data(mixed $data, Request $request): mixed
    {
        $member = $request->context()->get(Member::class);

        return ['id' => $member->id, 'name' => $member->name];
    }
}
