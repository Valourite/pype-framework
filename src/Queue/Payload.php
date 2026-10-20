<?php

declare(strict_types=1);

namespace Pype\Queue;

/** How jobs are stored: base64(serialize()), which keeps NUL bytes out of text columns. */
final class Payload
{
    public static function encode(object $job): string
    {
        if (!method_exists($job, 'handle')) {
            throw new \InvalidArgumentException('A job needs a handle() method: ' . $job::class);
        }

        return base64_encode(serialize($job));
    }

    public static function decode(string $payload): object
    {
        $job = @unserialize(base64_decode($payload));   // a corrupt payload is handled below

        return is_object($job) ? $job : throw new \UnexpectedValueException('Unreadable job payload.');
    }

    public static function exception(\Throwable $e): string
    {
        return substr($e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString(), 0, 60000);
    }
}
