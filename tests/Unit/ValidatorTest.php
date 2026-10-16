<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Validation\Validator;

final class ValidatorTest extends TestCase
{
    private function errors(array $data, array $rules): array
    {
        return Validator::validate($data, $rules)[1];
    }

    public function test_basic_rules(): void
    {
        [$valid, $errors] = Validator::validate(
            ['name' => 'Ada', 'age' => '36', 'role' => 'admin', 'extra' => 'dropped'],
            ['name' => 'required|string|max:10', 'age' => 'int|min:18', 'role' => 'in:admin,user'],
        );

        $this->assertSame([], $errors);
        $this->assertSame(['name' => 'Ada', 'age' => '36', 'role' => 'admin'], $valid);
        $this->assertSame(['age', 'role'], array_keys($this->errors(['age' => '12', 'role' => 'root'], ['age' => 'int|min:18', 'role' => 'in:admin,user'])));
    }

    public function test_optional_fields_for_partial_updates(): void
    {
        $rules = ['amount' => 'numeric', 'note' => 'nullable|string'];

        // Missing: skipped entirely.
        $this->assertSame([[], []], Validator::validate([], $rules));

        // Sent empty: only allowed when nullable.
        [$valid, $errors] = Validator::validate(['amount' => null, 'note' => ''], $rules);
        $this->assertSame(['amount' => ['The amount field must not be empty.']], $errors);
        $this->assertSame(['note' => null], $valid);
    }

    public function test_dates(): void
    {
        $this->assertSame([], $this->errors(['on' => '2024-02-29'], ['on' => 'date']));

        foreach (['2026-02-30', '2026-1-5', '15/10/2026', 20261015] as $bad) {
            $this->assertArrayHasKey('on', $this->errors(['on' => $bad], ['on' => 'date']), var_export($bad, true));
        }

        $this->assertSame([], $this->errors(['at' => '15/10/2026 09:30'], ['at' => 'date:d/m/Y H:i']));
        $this->assertSame(['The at field must be a date like 01/01/1970 00:00.'], $this->errors(['at' => '2026-10-15'], ['at' => 'date:d/m/Y H:i'])['at']);
    }

    public function test_url_and_confirmed(): void
    {
        $this->assertSame([], $this->errors(['site' => 'https://example.com/a?b=1'], ['site' => 'url']));
        $this->assertArrayHasKey('site', $this->errors(['site' => 'javascript:alert(1)'], ['site' => 'url']));
        $this->assertArrayHasKey('site', $this->errors(['site' => 'example.com'], ['site' => 'url']));

        $this->assertSame([], $this->errors(['password' => 'secret12', 'password_confirmation' => 'secret12'], ['password' => 'confirmed']));
        $this->assertSame(['password' => ['The password confirmation does not match.']], $this->errors(['password' => 'secret12'], ['password' => 'confirmed']));
    }

    public function test_closure_rules(): void
    {
        $even = static fn (mixed $v) => (int) $v % 2 === 0 ? null : 'Must be even.';

        $this->assertSame(['n' => ['Must be even.']], $this->errors(['n' => 3], ['n' => ['required', $even]]));
    }
}
