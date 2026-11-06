<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;
use Pype\Database\Hydrator;
use Pype\Database\Schema\Schema;
use Pype\Database\Schema\Table;
use Pype\Tests\Support\TestDatabase;

enum HydratedStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}

final readonly class HydratedReply
{
    public function __construct(
        public int $id,
        public bool $internal,
        public ?bool $pinned,
        public float $hours,
        public string $code,
        public DateTimeImmutable $created_at,
        public ?DateTimeInterface $read_at,
        public HydratedStatus $status,
        public int|string $ref,
        public mixed $extra = null,
    ) {
    }
}

final class HydratorTest extends TestCase
{
    public function test_values_are_cast_to_the_declared_types(): void
    {
        $reply = Hydrator::make(HydratedReply::class, [
            'id' => '7',                         // MySQL/Postgres numeric string
            'internal' => 1,                     // SQLite boolean
            'pinned' => null,                    // null stays null
            'hours' => '1.5',
            'code' => 42,                        // a number into a string column's parameter
            'created_at' => '2026-10-22 08:30:00',
            'read_at' => '2026-10-22',
            'status' => 'closed',
            'ref' => 9,                          // union: as is
            'extra' => '0',                      // mixed: as is
            'password' => 'secret',              // not a parameter: left out
        ]);

        $this->assertSame(7, $reply->id);
        $this->assertTrue($reply->internal);
        $this->assertNull($reply->pinned);
        $this->assertSame(1.5, $reply->hours);
        $this->assertSame('42', $reply->code);
        $this->assertSame('2026-10-22 08:30:00', $reply->created_at->format('Y-m-d H:i:s'));
        $this->assertInstanceOf(DateTimeImmutable::class, $reply->read_at);
        $this->assertSame(HydratedStatus::Closed, $reply->status);
        $this->assertSame(9, $reply->ref);
        $this->assertSame('0', $reply->extra);
    }

    public function test_query_builder_rows_from_the_database(): void
    {
        // Whatever the driver returns (strings from PDO, ints from SQLite) is cast to the declared types.
        $db = TestDatabase::fresh();
        (new Schema($db))->create('replies', function (Table $t) {
            $t->id();
            $t->integer('internal');
            $t->integer('pinned')->nullable();
            $t->decimal('hours', 8, 2)->nullable();
            $t->string('code')->nullable();
            $t->string('created_at')->nullable();
            $t->string('read_at')->nullable();
            $t->string('status')->nullable();
            $t->integer('ref')->nullable();
            $t->string('extra')->nullable();
        });
        $db->table('replies')->insert(['internal' => 0, 'pinned' => 1, 'hours' => 2, 'code' => '0042', 'created_at' => '2026-10-22 08:30:00', 'read_at' => null, 'status' => 'open', 'ref' => 1]);

        $reply = $db->table('replies')->as(HydratedReply::class)->first();

        $this->assertFalse($reply->internal);
        $this->assertTrue($reply->pinned);
        $this->assertSame(2.0, $reply->hours);
        $this->assertSame('0042', $reply->code);
        $this->assertNull($reply->read_at);
        $this->assertSame(HydratedStatus::Open, $reply->status);
    }
}
