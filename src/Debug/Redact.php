<?php

declare(strict_types=1);

namespace Pype\Debug;

/** Hides what should never be kept or shown by a development screen: passwords, tokens, cookies. */
final class Redact
{
    private const HIDDEN = '•••• hidden';
    private const MAX = 2000;

    /**
     * @param array<int|string, mixed> $data
     * @return array<int|string, mixed>
     */
    public static function fields(array $data): array
    {
        foreach ($data as $key => $value) {
            // The name decides first: card[number] is as secret as card.
            if (is_string($key) && preg_match(ErrorPage::SECRET_FIELDS, $key)) {
                $data[$key] = self::HIDDEN;
            } elseif (is_array($value)) {
                $data[$key] = self::fields($value);
            } elseif (is_string($value) && strlen($value) > self::MAX) {
                $data[$key] = mb_strcut($value, 0, self::MAX) . '… (' . strlen($value) . ' bytes)';
            } elseif (is_object($value)) {
                $data[$key] = get_debug_type($value);   // an uploaded file
            }
        }

        return $data;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public static function headers(array $headers): array
    {
        $out = [];

        foreach ($headers as $name => $value) {
            $out[$name] = in_array(strtolower((string) $name), ErrorPage::SECRET_HEADERS, true) ? self::HIDDEN : (string) $value;
        }

        ksort($out);

        return $out;
    }
}
