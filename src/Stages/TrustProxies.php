<?php

declare(strict_types=1);

namespace Pype\Stages;

use Closure;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Pype\Application;
use Pype\Container\ContainerAware;
use Pype\Http\ForwardedClient;
use Pype\Http\Request;
use Pype\Pipeline\Stage;
use Pype\Pipeline\WithArguments;

/**
 * Behind a proxy or load balancer: the real client IP, scheme and host from X-Forwarded-For,
 * X-Forwarded-Proto and X-Forwarded-Host, but only when the request came from a trusted proxy.
 * Anyone else's X-Forwarded-* headers are ignored, so clients can't pick their own IP.
 *
 *   $app->through(new TrustProxies());                        // app.trusted_proxies (TRUSTED_PROXIES)
 *   $app->through(TrustProxies::with(['10.0.0.0/8', '::1']));  // or a list of its own
 *
 * Entries are IPv4/IPv6 addresses or CIDR ranges. '*' trusts whatever address connected (only
 * when nothing but the proxy can reach the app), but not the hops listed in X-Forwarded-For.
 * X-Forwarded-For is read right to left, skipping trusted proxies: the first address that
 * isn't one is the client. Proto and host come from the last value, the one the nearest proxy set.
 * The result goes in the context as ForwardedClient; $request->ip(), scheme(), host() and
 * isSecure() read it. An empty list does nothing.
 */
final class TrustProxies implements Stage, ContainerAware
{
    use WithArguments;

    /** @var list<array{string, int}>|null packed address + prefix length; null until configured */
    private ?array $ranges = null;

    private bool $anyPeer = false;

    /** @param list<string>|string|null $proxies  null: config('app.trusted_proxies') */
    public function __construct(array|string|null $proxies = null)
    {
        if ($proxies !== null) {
            $this->configure($proxies);
        }
    }

    public function setContainer(ContainerInterface $container): void
    {
        // Once: the list is app config, the same for every request.
        if ($this->ranges === null) {
            $this->configure($container->has(Application::class)
                ? $container->get(Application::class)->config('app.trusted_proxies', [])
                : []);
        }
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->ranges === [] && !$this->anyPeer) {
            return $next($request);
        }

        $remote = $request->remoteAddr();
        if (!$this->anyPeer && !$this->listed($remote)) {
            return $next($request);
        }

        $request->context()->put(new ForwardedClient(
            $this->client($remote, (string) $request->header('x-forwarded-for', '')),
            self::scheme((string) $request->header('x-forwarded-proto', '')),
            self::host((string) $request->header('x-forwarded-host', '')),
        ));

        return $next($request);
    }

    /** Right to left: skip trusted proxies; a malformed hop stops the walk at the last good one. */
    private function client(string $remote, string $forwardedFor): string
    {
        $client = $remote;

        foreach (array_reverse(explode(',', $forwardedFor)) as $hop) {
            $ip = self::address(trim($hop));
            if ($ip === null) {
                break;
            }
            $client = $ip;
            if (!$this->listed($ip)) {
                break;
            }
        }

        return $client;
    }

    private function listed(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        foreach ($this->ranges ?? [] as [$network, $bits]) {
            if (strlen($network) === strlen($packed) && self::prefixMatches($packed, $network, $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function prefixMatches(string $a, string $b, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if (strncmp($a, $b, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    /** @param list<string>|string $proxies */
    private function configure(array|string $proxies): void
    {
        $entries = is_string($proxies) ? explode(',', $proxies) : $proxies;
        $this->ranges = [];

        foreach ($entries as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            if ($entry === '*') {
                $this->anyPeer = true;
                continue;
            }

            [$address, $bits] = str_contains($entry, '/') ? explode('/', $entry, 2) : [$entry, null];
            $packed = @inet_pton($address);
            $max = $packed === false ? 0 : strlen($packed) * 8;
            if ($packed === false || ($bits !== null && (!ctype_digit($bits) || (int) $bits > $max))) {
                throw new InvalidArgumentException("Trusted proxy '{$entry}' is not an IP address or CIDR range (TRUSTED_PROXIES).");
            }
            $this->ranges[] = [$packed, $bits === null ? $max : (int) $bits];
        }
    }

    /** "1.2.3.4", "1.2.3.4:5678", "[::1]:80", "::1" → the canonical address, or null. */
    private static function address(string $hop): ?string
    {
        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $hop, $m)) {
            $hop = $m[1];
        } elseif (substr_count($hop, ':') === 1 && str_contains($hop, '.')) {
            $hop = explode(':', $hop)[0];
        }

        if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return (string) inet_ntop((string) inet_pton($hop));
    }

    private static function lastValue(string $header): string
    {
        $values = explode(',', $header);

        return strtolower(trim((string) end($values)));
    }

    private static function scheme(string $header): ?string
    {
        $scheme = self::lastValue($header);

        return in_array($scheme, ['http', 'https'], true) ? $scheme : null;
    }

    private static function host(string $header): ?string
    {
        $host = self::lastValue($header);

        return preg_match('/^([a-z0-9](?:[a-z0-9.-]*[a-z0-9])?|\[[0-9a-f:.]+\])(?::\d{1,5})?$/', $host, $m) ? $m[1] : null;
    }
}
