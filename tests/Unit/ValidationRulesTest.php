<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Database\Connection;
use Pype\Database\Schema\Schema;
use Pype\Database\Schema\Table;
use Pype\Testing\TestClient;
use Pype\Tests\Support\TestDatabase;
use Pype\Validation\Exists;
use Pype\Validation\Rule;
use Pype\Validation\Unique;
use Pype\Validation\Validate;
use Pype\Validation\Validator;

final class ValidationRulesTest extends TestCase
{
    private function errors(array $data, array $rules, array $messages = [], ?Connection $db = null): array
    {
        return Validator::validate($data, $rules, $messages, $db)[1];
    }

    private function passes(mixed $value, string|array $rules): bool
    {
        return $this->errors(['f' => $value], ['f' => $rules]) === [];
    }

    public function test_format_rules(): void
    {
        $cases = [
            ['regex:/^[A-Z]{3}\d{2}$/', 'ABC12', 'abc12'],
            ['digits:4', '0042', '42'],
            ['alpha', 'Zoë', 'Zoë1'],
            ['alpha_num', 'Agent007', 'Agent 007'],
            ['alpha_dash', 'my-slug_2', 'my slug'],
            ['uuid', '7f3a2b10-4c5d-4e6f-8a9b-0c1d2e3f4a5b', '7f3a2b10-4c5d'],
            ['ip', '2001:db8::1', '999.1.1.1'],
            ['json', '{"a":[1,2]}', '{a:1}'],
            ['accepted', 'on', 'no'],
            ['not_in:admin,root', 'ada', 'root'],
            ['between:3,5', 'abcd', 'ab'],
            ['between:1,10', '10', '11'],
        ];

        foreach ($cases as [$rule, $good, $bad]) {
            $this->assertTrue($this->passes($good, $rule), "{$rule} should accept {$good}");
            $this->assertFalse($this->passes($bad, $rule), "{$rule} should reject {$bad}");
        }

        $this->assertTrue($this->passes('cat', ['regex:/^(cat|dog)$/']), 'regex with | in the array form');
    }

    public function test_comparing_fields_and_dates(): void
    {
        $this->assertSame([], $this->errors(['a' => 'x', 'b' => 'x'], ['a' => 'same:b']));
        $this->assertArrayHasKey('a', $this->errors(['a' => 'x', 'b' => 'y'], ['a' => 'same:b']));
        $this->assertArrayHasKey('a', $this->errors(['a' => 'x', 'b' => 'x'], ['a' => 'different:b']));

        $trip = ['start' => '2026-11-01', 'end' => '2026-11-05'];
        $this->assertSame([], $this->errors($trip, ['end' => 'date|after:start', 'start' => 'after_or_equal:2026-11-01']));
        $this->assertSame(['The start field must be a date before 2026-10-01.'], $this->errors($trip, ['start' => 'before:2026-10-01'])['start']);
        $this->assertArrayHasKey('end', $this->errors(['start' => '2026-11-05', 'end' => '2026-11-05'], ['end' => 'after:start']));
        $this->assertSame([], $this->errors(['start' => '2026-11-05', 'end' => '2026-11-05'], ['end' => 'after_or_equal:start']));
        $this->assertArrayHasKey('d', $this->errors(['d' => '2000-01-01'], ['d' => 'after:today']));
        $this->assertArrayHasKey('d', $this->errors(['d' => 'soon'], ['d' => 'after:today']));
    }

    public function test_conditional_required(): void
    {
        $rules = ['company' => 'required_if:type,business|string', 'password_confirmation' => 'required_with:password'];

        $this->assertSame([], $this->errors(['type' => 'person'], $rules));
        $this->assertSame(['company', 'password_confirmation'], array_keys($this->errors(['type' => 'business', 'password' => 'secret123'], $rules)));
    }

    public function test_arrays_with_wildcards(): void
    {
        $data = [
            'tags' => ['php', 'fast', str_repeat('x', 30)],
            'items' => [['sku' => 'A1', 'qty' => 2], ['sku' => 'B2', 'qty' => 0], ['qty' => 1]],
            'address' => ['city' => 'Cape Town'],
        ];
        $rules = [
            'tags' => 'array|max:5',
            'tags.*' => 'string|max:20',
            'items.*.sku' => 'required|alpha_num',
            'items.*.qty' => 'required|int|min:1',
            'address.city' => 'required|string',
            'address.zip' => 'nullable|digits:4',
        ];

        [$valid, $errors] = Validator::validate($data, $rules);

        $this->assertEqualsCanonicalizing(['tags.2', 'items.1.qty', 'items.2.sku'], array_keys($errors));

        // All valid: nested input comes out in the same shape.
        $good = ['tags' => ['php', 'fast'], 'items' => [['sku' => 'A1', 'qty' => 2]], 'address' => ['city' => 'Cape Town']];
        [$valid, $errors] = Validator::validate($good, $rules);
        $this->assertSame([], $errors);
        $this->assertSame($good, $valid);
    }

    public function test_closures_rule_objects_and_messages(): void
    {
        $even = fn (mixed $value, string $field) => (int) $value % 2 === 0 ? null : "The {$field} must be even.";
        $notOwn = fn (mixed $value, string $field, array $data) => $value !== ($data['email'] ?? null) ? null : 'Use another address.';
        $postcode = new class () implements Rule {
            public function check(string $field, mixed $value, array $data): ?string
            {
                return preg_match('/^\d{4}$/', (string) $value) ? null : "The {$field} must be a 4-digit postcode.";
            }
        };

        $errors = $this->errors(
            ['n' => 3, 'email' => 'a@x.test', 'backup' => 'a@x.test', 'zip' => '12', 'name' => ''],
            ['n' => [$even], 'backup' => ['email', $notOwn], 'zip' => [$postcode], 'name' => 'required', 'age' => 'required'],
            ['name.required' => 'Tell us your name.', 'required' => '{field} is needed.', 'zip' => 'Postcodes have 4 digits.'],
        );

        $this->assertSame(['The n must be even.'], $errors['n']);
        $this->assertSame(['Use another address.'], $errors['backup']);
        $this->assertSame(['Postcodes have 4 digits.'], $errors['zip']);
        $this->assertSame(['Tell us your name.'], $errors['name']);
        $this->assertSame(['age is needed.'], $errors['age']);
    }

    public function test_unknown_rules_and_bad_regexes_are_mistakes_not_failures(): void
    {
        try {
            $this->errors(['f' => 'x'], ['f' => 'regex:/(/']);
            $this->fail();
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid regex', $e->getMessage());
        }

        $this->expectExceptionMessage('Unknown validation rule [nope]');
        $this->errors(['f' => 'x'], ['f' => 'nope']);
    }

    private function db(): Connection
    {
        $db = TestDatabase::fresh();
        (new Schema($db))->create('users', function (Table $t) {
            $t->id();
            $t->string('email')->unique();
            $t->integer('team');
        });
        $db->execute("INSERT INTO users (email, team) VALUES ('ada@x.test', 1), ('bob@x.test', 2)");

        return $db;
    }

    public function test_unique_and_exists(): void
    {
        $db = $this->db();

        $this->assertSame(['The email has already been taken.'], $this->errors(['email' => 'ada@x.test'], ['email' => 'unique:users'], db: $db)['email']);
        $this->assertSame([], $this->errors(['email' => 'new@x.test'], ['email' => 'unique:users,email'], db: $db));
        $this->assertSame([], $this->errors(['email' => 'ada@x.test'], ['email' => 'unique:users,email,1'], db: $db), 'ignoring row 1 (editing it)');
        $this->assertSame([], $this->errors(['email' => 'ada@x.test'], ['email' => [new Unique('users', where: ['team' => 2])]], db: $db), 'scoped to team 2');

        $this->assertSame([], $this->errors(['user_id' => 2], ['user_id' => 'exists:users,id'], db: $db));
        $this->assertSame(['The selected user_id is invalid.'], $this->errors(['user_id' => 9], ['user_id' => 'exists:users,id'], db: $db)['user_id']);
        $this->assertArrayHasKey('user_id', $this->errors(['user_id' => 2], ['user_id' => [new Exists('users', 'id', where: ['team' => 1])]], db: $db));
        $this->assertArrayHasKey('email', $this->errors(['email' => ['ada@x.test']], ['email' => 'unique:users'], db: $db), 'arrays never reach the query');
    }

    public function test_validate_stage_uses_the_app_database_and_route_params(): void
    {
        $app = new Application(__DIR__);
        $app->container->instance(Connection::class, $this->db());
        $app->router->patch('/users/{id}', [
            new Validate(['email' => 'required|email|unique:users,email,{id}'], messages: ['email.unique' => 'That email belongs to someone else.']),
            fn () => 'saved',
        ]);
        $client = new TestClient($app);

        $this->assertSame(200, $client->request('PATCH', '/users/1', ['email' => 'ada@x.test'])->status(), 'own email is fine');
        $res = $client->request('PATCH', '/users/2', ['email' => 'ada@x.test']);
        $this->assertSame(422, $res->status());
        $this->assertSame(['That email belongs to someone else.'], $res->json('error.errors.email'));
    }

    public function test_subclasses_with_their_own_constructor_still_work(): void
    {
        $stage = new class ('x') extends Validate {
            public function __construct(private readonly string $field)
            {
            }

            protected function rules(): array
            {
                return [$this->field => 'required'];
            }
        };
        $app = new Application(__DIR__);
        $app->router->post('/x', [$stage, fn () => 'ok']);

        $this->assertSame(422, (new TestClient($app))->post('/x', [])->status());
        $this->assertSame(200, (new TestClient($app))->post('/x', ['x' => '1'])->status());
    }
}
