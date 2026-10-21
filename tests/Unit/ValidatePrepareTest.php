<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Auth\AuthProvider;
use Pype\Database\Connection;
use Pype\Http\Request;
use Pype\Outlet\RedirectOutlet;
use Pype\Session\SessionProvider;
use Pype\Testing\TestClient;
use Pype\Tests\Support\TestDatabase;
use Pype\Validation\Validate;
use Pype\Validation\ValidatedInput;
use Pype\View\View;
use Pype\View\ViewProvider;

/** Validate::prepare(): tidy rows before the rules, errors still name the browser's rows. */
final class ValidatePrepareTest extends TestCase
{
    private TestClient $client;

    /** Rows 0, 2 and 4 filled in; 1 and 3 left empty; row 2's quantity is wrong. */
    private const LINES = [
        ['item_id' => '3', 'quantity' => '2'],
        ['item_id' => '', 'quantity' => ''],
        ['item_id' => '7', 'quantity' => 'abc'],
        ['item_id' => '', 'quantity' => ''],
        ['item_id' => '9', 'quantity' => '1'],
    ];

    protected function setUp(): void
    {
        $app = new Application(dirname(__DIR__, 2), ['app' => ['views' => 'tests/fixtures/views'], 'auth' => ['user' => \stdClass::class]]);
        $app->container->instance(Connection::class, TestDatabase::withAuthTables());
        $app->register(AuthProvider::class)->register(SessionProvider::class)->register(ViewProvider::class);

        $handler = fn (ValidatedInput $in, Request $request) => ['lines' => $in->get('lines'), 'sent' => count($request->input('lines')), 'prices' => $in->get('prices')];
        $app->router->post('/api/quotes', [ValidateQuoteLines::class, $handler]);
        $app->router->group('/', ['web'], function ($r) {
            $r->get('/quotes/new', [View::with('rows')]);
            $r->post('/quotes', [ValidateQuoteLines::class, RedirectOutlet::with('/quotes/new')]);
        });

        $this->client = new TestClient($app);
    }

    public function test_errors_name_the_row_the_browser_shows(): void
    {
        $this->client->post('/api/quotes', ['lines' => self::LINES])
            ->assertUnprocessable()
            ->assertInvalid('lines.2.quantity');   // not lines.1: the blank rows were dropped, not re-numbered

        $this->assertSame(['lines.2.quantity'], array_keys($this->client->post('/api/quotes', ['lines' => self::LINES])->json('error.errors')));
    }

    public function test_the_handler_gets_the_rows_numbered_again_and_the_request_is_untouched(): void
    {
        $lines = self::LINES;
        $lines[2]['quantity'] = '5';

        $res = $this->client->post('/api/quotes', ['lines' => $lines, 'prices' => ['17' => '100', '18' => '']])->assertOk();

        $this->assertSame([0, 1, 2], array_keys($res->json('data.lines')));
        $this->assertSame(['3', '7', '9'], array_column($res->json('data.lines'), 'item_id'));
        $this->assertSame(5, $res->json('data.sent'));                     // later stages still see what was sent
        $this->assertSame(['17' => '100'], $res->json('data.prices'));    // keyed on purpose: keys kept
    }

    public function test_a_form_comes_back_with_the_rows_as_typed_and_the_error_on_the_right_one(): void
    {
        $this->client->submit('/quotes/new', '/quotes', ['lines' => self::LINES])->assertRedirect('/quotes/new')->assertInvalid('lines.2.quantity');

        $this->client->get('/quotes/new')->assertSee('row1=|row2=abc|err1=-|err2=The lines.2.quantity field must be an integer.');   // row 1 refilled empty, as typed
    }
}

final class ValidateQuoteLines extends Validate
{
    protected function prepare(array $input): array
    {
        $input['lines'] = array_filter($input['lines'] ?? [], fn (array $row) => array_filter($row) !== []);
        $input['prices'] = array_filter($input['prices'] ?? [], fn ($price) => $price !== '');

        return $input;
    }

    protected function rules(): array
    {
        return [
            'lines' => 'required|array|min:1',
            'lines.*.item_id' => 'required|int',
            'lines.*.quantity' => 'required|int|min:1',
            'prices' => 'array',
        ];
    }
}
