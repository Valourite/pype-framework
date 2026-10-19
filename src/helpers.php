<?php

declare(strict_types=1);

use Pype\Routing\Urls;
use Pype\Support\Env;

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('e')) {
    /** Escape a value for HTML output. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /**
     * Link to a named route: url('expenses.edit', ['id' => 3]) → /expenses/3/edit.
     * Params fill {placeholders}; the rest become the query string. $absolute prefixes APP_URL.
     */
    function url(string $name, array $params = [], bool $absolute = false): string
    {
        return Urls::current()->to($name, $params, $absolute);
    }
}

if (!function_exists('signed_url')) {
    /**
     * A tamper-proof (and optionally expiring) absolute link to a named route, for emails:
     * signed_url('password.reset', ['user' => 5], expiresIn: 3600). Guard the route with ValidateSignature.
     */
    function signed_url(string $name, array $params = [], ?int $expiresIn = null): string
    {
        return Urls::current()->signed($name, $params, $expiresIn);
    }
}
