<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Database\Fake;

final class FakeTest extends TestCase
{
    public function test_a_seed_repeats_exactly(): void
    {
        $run = function (): array {
            $f = new Fake(seed: 7);

            return [$f->name(), $f->int(1, 1000), $f->sentence(), $f->uuid(), $f->pick(['a', 'b', 'c']), $f->float(1, 10), $f->bool()];
        };

        $this->assertSame($run(), $run());
        $this->assertNotSame((new Fake(seed: 7))->words(8), (new Fake(seed: 8))->words(8));
    }

    public function test_formats_and_ranges(): void
    {
        $f = new Fake();

        for ($i = 0; $i < 200; $i++) {
            $this->assertMatchesRegularExpression('/^[a-z_.]+\.\d+@example\.test$/', $f->email());
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $f->uuid());
            $this->assertMatchesRegularExpression('/^INV-\d{4}$/', $f->numerify('INV-####'));
            $n = $f->int(5, 9);
            $this->assertTrue($n >= 5 && $n <= 9);
            $x = $f->float(1.5, 2.5);
            $this->assertTrue($x >= 1.5 && $x <= 2.5);
            $date = $f->date('2026-01-01', '2026-01-31');
            $this->assertTrue($date >= '2026-01-01' && $date <= '2026-01-31');
        }

        $this->assertMatchesRegularExpression('/^[A-Z][a-z ]+\.$/', $f->sentence(5));
        $this->assertSame(5, count(explode(' ', rtrim($f->sentence(5), '.'))));
        $this->assertCount(3, $f->pickMany(range(1, 10), 3));
        $this->assertSame(3, count(explode('-', $f->slug(3))));
    }

    public function test_emails_never_collide_across_fakes(): void
    {
        $emails = [];
        foreach ([new Fake(seed: 1), new Fake(seed: 1), new Fake()] as $fake) {
            for ($i = 0; $i < 300; $i++) {
                $emails[] = $fake->email();
            }
        }

        $this->assertSame(count($emails), count(array_unique($emails)));
    }

    public function test_unique_never_repeats_and_says_when_it_runs_out(): void
    {
        $fake = new Fake();
        $seen = [];

        for ($i = 0; $i < 10; $i++) {
            $seen[] = $fake->unique()->int(1, 10);   // a fresh unique() call each time: still one proxy
        }

        sort($seen);
        $this->assertSame(range(1, 10), $seen);

        $this->expectException(\OverflowException::class);
        $fake->unique()->int(1, 10);
    }
}
