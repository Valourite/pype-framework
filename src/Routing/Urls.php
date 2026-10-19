<?php

declare(strict_types=1);

namespace Pype\Routing;

use InvalidArgumentException;
use LogicException;
use Pype\Http\Request;
use Stringable;

/**
 * Builds links to named routes, so paths are written once, in the routes file.
 *
 *   url('expenses.edit', ['id' => 3])                    // /expenses/3/edit
 *   url('expenses.index', ['month' => '2026-10'])        // /expenses?month=2026-10 (extra params → query)
 *   url('dashboard', absolute: true)                     // https://app.test/dashboard (APP_URL)
 *   signed_url('password.reset', ['user' => 5], 3600)    // absolute, expires in an hour, tamper-proof
 *
 * Inject Urls where you prefer a service: fn (Urls $urls) => $urls->to('dashboard').
 *
 * url() and signed_url() reach this through Urls::current(). That's a process-wide reference,
 * which is fine under OpenSwoole too: it only holds the route table and app config, the same
 * for every request, never anything about a request.
 */
final class Urls
{
    private static ?self $current = null;

    public function __construct(
        private readonly Router $router,
        private readonly string $baseUrl = '',
        #[\SensitiveParameter] private readonly string $key = '',
    ) {
    }

    /** The app's Urls, used by the url() and signed_url() helpers. Set by the Application. */
    public static function current(): self
    {
        return self::$current ?? throw new LogicException('url() needs a pype Application to be created first.');
    }

    public static function setCurrent(self $urls): void
    {
        self::$current = $urls;
    }

    /**
     * The path (or, with $absolute, the full URL) of a named route. Params fill the route's
     * {placeholders}; any others become the query string. Null params are left out.
     *
     * @param array<string, scalar|Stringable|array|null> $params
     */
    public function to(string $name, array $params = [], bool $absolute = false): string
    {
        $route = $this->router->named($name) ?? throw new InvalidArgumentException("No route is named [{$name}].");

        $path = preg_replace_callback('#\{(\w+)\}#', static function (array $m) use (&$params, $name): string {
            if (!isset($params[$m[1]])) {
                throw new InvalidArgumentException("Route [{$name}] needs the [{$m[1]}] param.");
            }
            $value = $params[$m[1]];
            unset($params[$m[1]]);

            return rawurlencode(is_bool($value) ? (string) (int) $value : (string) $value);
        }, $route->path());

        $query = http_build_query(array_filter($params, static fn ($v) => $v !== null), '', '&', PHP_QUERY_RFC3986);
        $url = $query === '' ? $path : "{$path}?{$query}";

        return $absolute ? $this->absolute($url) : $url;
    }

    /**
     * A link nobody can change or reuse after $expiresIn seconds (null = never expires). Guard
     * the route with the ValidateSignature stage. Absolute by default: signed links are usually
     * sent by email.
     */
    public function signed(string $name, array $params = [], ?int $expiresIn = null, bool $absolute = true): string
    {
        unset($params['signature']);

        if ($expiresIn !== null) {
            $params['expires'] = time() + $expiresIn;
        }

        $url = $this->to($name, $params);
        [$path, $query] = array_pad(explode('?', $url, 2), 2, '');
        parse_str($query, $queryParams);

        $url .= ($query === '' ? '?' : '&') . 'signature=' . $this->sign($path, $queryParams);

        return $absolute ? $this->absolute($url) : $url;
    }

    /** Whether this request came through an untouched, unexpired signed link. */
    public function hasValidSignature(Request $request): bool
    {
        $query = $request->query();
        $signature = $query['signature'] ?? null;
        unset($query['signature']);

        if (!is_string($signature) || !hash_equals($this->sign($request->path(), $query), $signature)) {
            return false;
        }

        return !isset($query['expires']) || (is_numeric($query['expires']) && (int) $query['expires'] > time());
    }

    /**
     * HMAC-SHA256 of the path and the query params (sorted, so their order doesn't matter).
     * Only path and query are signed, not the host, so links keep working behind proxies.
     */
    private function sign(string $path, array $query): string
    {
        if ($this->key === '') {
            throw new LogicException('Signed URLs need APP_KEY. Run: php bin/pype key:generate');
        }

        ksort($query);

        return hash_hmac('sha256', $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), $this->key);
    }

    private function absolute(string $url): string
    {
        return rtrim($this->baseUrl, '/') . $url;
    }

    /** APP_KEY as stored in .env ("base64:...") → raw key bytes. */
    public static function decodeKey(string $key): string
    {
        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
    }
}
