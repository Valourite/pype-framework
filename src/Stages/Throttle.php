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
use Pype\RateLimit\DatabaseRateLimiter;
use Pype\RateLimit\RateLimiter;

/**
 * Fixed-window rate limit per client IP + route. Counters live in the bound RateLimiter
 * (RATE_LIMIT_DRIVER: the rate_limits table by default, or APCu).
 *
 *   $router->post('/auth/login', [new Throttle(maxAttempts: 5, decaySeconds: 60), ...]);
 */
final class Throttle implements Stage, ContainerAware
{
    private ?RateLimiter $limiter = null;

    public function __construct(
        private readonly int $maxAttempts = 60,
        private readonly int $decaySeconds = 60,
        private readonly string $prefix = '',
    ) {
    }

    public function setContainer(ContainerInterface $container): void
    {
        $this->limiter = $container->has(RateLimiter::class)
            ? $container->get(RateLimiter::class)
            : new DatabaseRateLimiter($container->get(Connection::class));
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $limiter = $this->limiter ?? throw new \LogicException('Throttle needs the container. Is DatabaseProvider registered?');
        $key = hash('sha256', implode('|', [$this->prefix, $request->method(), $request->path(), $request->ip()]));

        [$hits, $resetAt] = $limiter->hit($key, $this->decaySeconds);

        if ($hits > $this->maxAttempts) {
            throw HttpException::tooManyRequests(max(1, $resetAt - time()));
        }
        /** @var Response $response */
        $response = $next($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxAttempts)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $this->maxAttempts - $hits));
    }
}
