<?php

declare(strict_types=1);

namespace Pype\Database;

use DateTimeImmutable;
use Random\Engine\Mt19937;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Fake values for factories and seeders. Small on purpose: what tests and demo data need, with
 * no package. Give a seed to get the same values every run.
 *
 *   $fake = new Fake(seed: 42);
 *   $fake->name();                     // "Thandi Naidoo"
 *   $fake->email();                    // "thandi.naidoo.17@example.test" (never repeats in this process)
 *   $fake->unique()->pick($codes);     // never the same value twice from this unique() proxy
 *
 * For realistic, local data (addresses, phone or ID numbers, 75 locales) use fakerphp/faker
 * (composer require --dev fakerphp/faker) inside a factory's definition(): dev-only, so not in
 * seeders that must run in production.
 */
class Fake
{
    private const FIRST = [
        'Ada', 'Alan', 'Amahle', 'Anele', 'Ayanda', 'Bongani', 'Bryn', 'Carmen', 'Chloe', 'Daniel',
        'Dineo', 'Elena', 'Emma', 'Ethan', 'Fatima', 'Grace', 'Hannah', 'Imran', 'Isabel', 'Jabu',
        'James', 'Johan', 'Kagiso', 'Karabo', 'Kayla', 'Lebo', 'Lerato', 'Liam', 'Lindiwe', 'Luca',
        'Mandla', 'Maria', 'Mia', 'Mpho', 'Naledi', 'Noah', 'Nomvula', 'Olivia', 'Pieter', 'Priya',
        'Rayan', 'Refilwe', 'Ruan', 'Sam', 'Sarah', 'Sipho', 'Sofia', 'Tariq', 'Thabo', 'Thandi',
        'Tumi', 'Uma', 'Victor', 'Wei', 'Xolani', 'Yusuf', 'Zainab', 'Zanele', 'Zara', 'Zoe',
    ];

    private const LAST = [
        'Adams', 'Botha', 'Brown', 'Cele', 'Chen', 'Daniels', 'Dlamini', 'du Plessis', 'Fourie', 'Garcia',
        'Govender', 'Hendricks', 'Jacobs', 'Jansen', 'Khan', 'Khumalo', 'Kruger', 'Lee', 'Lewis', 'Mabaso',
        'Mahlangu', 'Martin', 'Mokoena', 'Moloi', 'Moodley', 'Mthembu', 'Naidoo', 'Ndlovu', 'Nel', 'Ngcobo',
        'Nguyen', 'Nkosi', 'Patel', 'Pillay', 'Pretorius', 'Rossouw', 'Sithole', 'Smith', 'Steyn', 'Taylor',
        'Thomas', 'van der Merwe', 'van Wyk', 'Venter', 'Visser', 'Williams', 'Wilson', 'Xaba', 'Zulu', 'Zwane',
        'Abrahams', 'Booysen', 'Coetzee', 'Erasmus', 'Ferreira', 'Mahomed', 'Molefe', 'Ramaphosa', 'Shabalala', 'Tshabalala',
    ];

    private const WORDS = [
        'able', 'about', 'account', 'across', 'after', 'again', 'air', 'almost', 'along', 'always', 'answer',
        'around', 'away', 'back', 'base', 'became', 'before', 'began', 'behind', 'best', 'better', 'between',
        'big', 'black', 'blue', 'boat', 'body', 'book', 'both', 'bread', 'bright', 'bring', 'build', 'busy',
        'call', 'came', 'care', 'carry', 'cause', 'change', 'check', 'city', 'class', 'clear', 'close', 'cold',
        'color', 'come', 'common', 'complete', 'cook', 'cool', 'could', 'country', 'course', 'cover', 'cross',
        'dark', 'day', 'deep', 'did', 'different', 'direct', 'does', 'done', 'door', 'down', 'draw', 'dream',
        'drive', 'during', 'each', 'early', 'earth', 'easy', 'eight', 'end', 'enough', 'even', 'every', 'example',
        'fact', 'fall', 'family', 'far', 'farm', 'fast', 'feel', 'field', 'figure', 'fine', 'fire', 'first',
        'fish', 'five', 'fly', 'follow', 'food', 'form', 'found', 'four', 'free', 'friend', 'full', 'game',
        'garden', 'gave', 'girl', 'give', 'gold', 'good', 'great', 'green', 'ground', 'group', 'grow', 'half',
        'hand', 'happy', 'hard', 'head', 'hear', 'heat', 'help', 'here', 'high', 'hold', 'home', 'horse',
        'hour', 'house', 'idea', 'important', 'inch', 'island', 'just', 'keep', 'kind', 'king', 'know', 'land',
        'large', 'last', 'late', 'learn', 'leave', 'left', 'less', 'letter', 'life', 'light', 'line', 'list',
        'little', 'live', 'long', 'look', 'made', 'make', 'many', 'map', 'mark', 'measure', 'might', 'mile',
        'mind', 'minute', 'money', 'moon', 'more', 'morning', 'most', 'mountain', 'move', 'much', 'music',
        'name', 'near', 'need', 'never', 'next', 'night', 'north', 'note', 'notice', 'number', 'ocean', 'often',
        'open', 'order', 'other', 'page', 'paper', 'part', 'pass', 'people', 'picture', 'place', 'plain', 'plan',
        'plant', 'play', 'point', 'power', 'press', 'problem', 'pull', 'quick', 'rain', 'reach', 'read', 'ready',
        'real', 'record', 'remember', 'rest', 'river', 'road', 'rock', 'room', 'round', 'rule', 'run', 'sail',
        'school', 'science', 'sea', 'second', 'seem', 'serve', 'several', 'shape', 'ship', 'short', 'show',
        'side', 'simple', 'since', 'size', 'slow', 'small', 'snow', 'song', 'soon', 'sound', 'south', 'space',
        'special', 'stand', 'star', 'start', 'stay', 'step', 'still', 'stone', 'stop', 'story', 'street', 'strong',
        'study', 'sun', 'sure', 'surface', 'table', 'tail', 'talk', 'teach', 'tell', 'test', 'thing', 'think',
        'three', 'time', 'together', 'took', 'top', 'toward', 'town', 'travel', 'tree', 'true', 'turn', 'under',
        'unit', 'until', 'usual', 'very', 'voice', 'walk', 'warm', 'watch', 'water', 'week', 'weight', 'west',
        'wheel', 'white', 'whole', 'wind', 'winter', 'wonder', 'wood', 'word', 'work', 'world', 'write', 'year', 'young',
    ];

    /** Shared by every Fake in this process, so emails and usernames never collide between factories. */
    private static int $serial = 0;

    private readonly Randomizer $random;

    private ?UniqueFake $unique = null;

    public function __construct(?int $seed = null)
    {
        $this->random = new Randomizer($seed === null ? new Xoshiro256StarStar() : new Mt19937($seed));
    }

    public function int(int $min = 0, int $max = PHP_INT_MAX): int
    {
        return $this->random->getInt($min, $max);
    }

    public function float(float $min = 0, float $max = 1, int $decimals = 2): float
    {
        return round($min + $this->random->getInt(0, PHP_INT_MAX) / PHP_INT_MAX * ($max - $min), $decimals);
    }

    /** @param int $chance  percent */
    public function bool(int $chance = 50): bool
    {
        return $this->random->getInt(1, 100) <= $chance;
    }

    /**
     * @template T
     * @param array<T> $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        if ($items === []) {
            throw new \InvalidArgumentException('Nothing to pick from.');
        }

        return $items[$this->random->pickArrayKeys($items, 1)[0]];
    }

    /** @return list<mixed> $count different items, in their original order */
    public function pickMany(array $items, int $count): array
    {
        return array_values(array_intersect_key($items, array_flip($this->random->pickArrayKeys($items, $count))));
    }

    public function word(): string
    {
        return $this->pick(self::WORDS);
    }

    public function words(int $count = 3): string
    {
        $words = [];
        for ($i = 0; $i < $count; $i++) {
            $words[] = $this->word();
        }

        return implode(' ', $words);
    }

    public function sentence(int $words = 0): string
    {
        return ucfirst($this->words($words > 0 ? $words : $this->int(4, 12))) . '.';
    }

    public function paragraph(int $sentences = 0): string
    {
        $out = [];
        for ($i = 0, $n = $sentences > 0 ? $sentences : $this->int(3, 6); $i < $n; $i++) {
            $out[] = $this->sentence();
        }

        return implode(' ', $out);
    }

    public function firstName(): string
    {
        return $this->pick(self::FIRST);
    }

    public function lastName(): string
    {
        return $this->pick(self::LAST);
    }

    public function name(): string
    {
        return $this->firstName() . ' ' . $this->lastName();
    }

    /** Unique for the life of the process: "thandi.naidoo.17@example.test". */
    public function email(string $domain = 'example.test'): string
    {
        return $this->username('.') . '@' . $domain;
    }

    /** Unique for the life of the process: "thandi_naidoo_17". */
    public function username(string $separator = '_'): string
    {
        $base = strtolower(preg_replace('/[^A-Za-z]+/', $separator, $this->firstName() . ' ' . $this->lastName()));

        return $base . $separator . ++self::$serial;
    }

    public function slug(int $words = 3): string
    {
        return str_replace(' ', '-', $this->words($words));
    }

    /** A random (version 4) UUID. */
    public function uuid(): string
    {
        $b = $this->random->getBytes(16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
        $b[8] = chr(ord($b[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** A date between two moments (anything DateTimeImmutable understands), formatted. */
    public function date(string $from = '-1 year', string $to = 'now', string $format = 'Y-m-d'): string
    {
        return $this->dateTime($from, $to)->format($format);
    }

    public function dateTime(string $from = '-1 year', string $to = 'now'): DateTimeImmutable
    {
        $start = (new DateTimeImmutable($from))->getTimestamp();
        $end = (new DateTimeImmutable($to))->getTimestamp();

        return (new DateTimeImmutable())->setTimestamp($this->int(min($start, $end), max($start, $end)));
    }

    /** Every # becomes a digit: numerify('INV-####') → "INV-4817". */
    public function numerify(string $pattern): string
    {
        return preg_replace_callback('/#/', fn () => (string) $this->int(0, 9), $pattern);
    }

    /**
     * Values this Fake hasn't returned through unique() before, per method (throws after 1,000
     * tries). The same proxy every call, so $fake->unique()->word() in a factory's definition()
     * stays unique across its rows.
     */
    public function unique(): UniqueFake
    {
        return $this->unique ??= new UniqueFake($this);
    }
}
