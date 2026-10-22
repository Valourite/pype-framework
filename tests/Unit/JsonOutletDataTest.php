<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use Closure;
use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Database\Page;
use Pype\Database\PageUrls;
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
        $app->router->post('/members', [fn () => ['id' => 8], JsonOutlet::with(status: 201)]);
        $app->router->post('/members/{id}/notes', [LoadMember::class, CreatedMemberJson::class]);
        $app->router->post('/fails', [fn () => throw HttpException::notFound(), JsonOutlet::with(status: 201)]);
        $app->router->get('/members', [LoadMemberPage::class, MembersJson::class]);
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

    public function test_the_status_on_success(): void
    {
        $this->client->post('/members')->assertStatus(201)->assertJsonPath('data.id', 8);
        $this->client->post('/members/7/notes')->assertStatus(201)->assertJsonPath('data.name', 'Member 7');   // set in a subclass
        $this->client->post('/members/404/notes')->assertNotFound();   // errors keep theirs
        $this->client->post('/fails')->assertNotFound();
    }

    public function test_a_page_from_the_context_keeps_its_pagination(): void
    {
        $this->client->get('/members?team=red&page=2')->assertOk()
            ->assertJson(['data' => [['id' => 3, 'name' => 'Member 3'], ['id' => 4, 'name' => 'Member 4']]])
            ->assertJsonPath('meta.pagination.total', 5)
            ->assertJsonPath('meta.pagination.last_page', 3)
            ->assertJsonPath('meta.pagination.next', '/members?team=red&page=3')
            ->assertJsonPath('meta.pagination.previous', '/members?team=red')
            ->assertDontSee('secret');
    }
}

final class LoadMemberPage implements Stage
{
    public function handle(Request $request, Closure $next): mixed
    {
        $members = array_map(static fn (int $id) => new Member($id, "Member {$id}", 'secret'), range(1, 5));
        $number = PageUrls::pageFrom($request);
        $request->context()->put(new Page(array_slice($members, ($number - 1) * 2, 2), 5, 2, $number, PageUrls::from($request)));

        return $next($request);
    }
}

final class MembersJson extends JsonOutlet
{
    protected function data(mixed $data, Request $request): mixed
    {
        return $request->context()->get(Page::class)->map(static fn (Member $m) => ['id' => $m->id, 'name' => $m->name]);
    }
}

final class CreatedMemberJson extends JsonOutlet
{
    protected ?int $status = 201;

    protected function data(mixed $data, Request $request): mixed
    {
        return ['name' => $request->context()->get(Member::class)->name];
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
