<?php

declare(strict_types=1);

namespace Pype\Stages;

use Closure;
use Psr\Container\ContainerInterface;
use Pype\Container\ContainerAware;
use Pype\Database\Connection;
use Pype\Exceptions\HttpException;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Pipeline\Stage;

/**
 * Fixed-window rate limit per client IP + route, stored in the database
 * (table: rate_limits(key PRIMARY KEY, hits, reset_at)).
 *
 *   $router->post('/auth/login', [new Throttle(maxAttempts: 5, decaySeconds: 60), ...]);
 */
final class Throttle implements Stage, ContainerAware
{
    private ?Connection $db = null;

    public function __construct(
        private readonly int $maxAttempts = 60,
        private readonly int $decaySeconds = 60,
        private readonly string $prefix = '',
        private readonly string $table = 'rate_limits',
    ) {
    }

    public function setContainer(ContainerInterface $container): void
    {
        $this->db = $container->get(Connection::class);
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $db = $this->db ?? throw new \LogicException('Throttle needs the container. Is DatabaseProvider registered?');
        $key = hash('sha256', implode('|', [$this->prefix, $request->method(), $request->path(), $request->ip()]));
        $now = time();

        [$hits, $resetAt] = $db->transaction(function (Connection $db) use ($key, $now) {
            $row = $db->table($this->table)->where('key', $key)->first();

            if ($row === null) {
                $db->table($this->table)->insert(['key' => $key, 'hits' => 1, 'reset_at' => $now + $this->decaySeconds]);

                return [1, $now + $this->decaySeconds];
            }

            if ((int) $row['reset_at'] <= $now) {
                $db->table($this->table)->where('key', $key)->update(['hits' => 1, 'reset_at' => $now + $this->decaySeconds]);

                return [1, $now + $this->decaySeconds];
            }

            $db->table($this->table)->where('key', $key)->update(['hits' => (int) $row['hits'] + 1]);

            return [(int) $row['hits'] + 1, (int) $row['reset_at']];
        });

        if ($hits > $this->maxAttempts) {
            throw HttpException::tooManyRequests(max(1, $resetAt - $now));
        }

        /** @var Response $response */
        $response = $next($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxAttempts)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $this->maxAttempts - $hits));
    }
}
