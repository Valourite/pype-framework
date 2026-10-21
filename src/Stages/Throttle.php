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
use Pype\Pipeline\WithArguments;
use Pype\RateLimit\DatabaseRateLimiter;
use Pype\RateLimit\RateLimiter;

/**
 * Fixed-window rate limit per client IP + route, or per signed-in user (by:), or shared by several
 * routes (key:). Counters live in the bound RateLimiter
 * (RATE_LIMIT_DRIVER: the rate_limits table by default, or APCu).
 *
 *   $router->post('/auth/login', [Throttle::with(maxAttempts: 5, decaySeconds: 60), ...]);
 *   new Throttle(5, 60)   // the same, built while the routes file runs
 *
 *   // 20 an hour per signed-in user, counted together for every route with key 'send'
 *   // (without key, /quotes/1/send and /quotes/2/send would count separately):
 *   Throttle::with(20, 3600, by: User::class, key: 'send')
 *
 * by: names a context entry with an id: User::class (or Auth::USER for the configured user class).
 * Where it's missing (a guest on an optional-auth route), the client IP counts instead.
 */
final class Throttle implements Stage, ContainerAware
{
    use WithArguments;

    private ?RateLimiter $limiter = null;

    private ?string $byClass = null;

    public function __construct(
        private readonly int $maxAttempts = 60,
        private readonly int $decaySeconds = 60,
        private readonly string $prefix = '',
        private readonly ?string $by = null,
        private readonly ?string $key = null,
    ) {
    }

    public function setContainer(ContainerInterface $container): void
    {
        if ($this->by === \Pype\Auth\Auth::USER && $container->has(\Pype\Auth\Auth::class)) {
            $this->byClass = $container->get(\Pype\Auth\Auth::class)->userClass();
        }

        $this->limiter = $container->has(RateLimiter::class)
            ? $container->get(RateLimiter::class)
            : new DatabaseRateLimiter($container->get(Connection::class));
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $limiter = $this->limiter ?? throw new \LogicException('Throttle needs the container. Is DatabaseProvider registered?');
        $key = hash('sha256', implode('|', [
            $this->prefix,
            ...($this->key !== null ? ['key', $this->key] : [$request->method(), $request->path()]),
            $this->who($request),
        ]));

        [$hits, $resetAt] = $limiter->hit($key, $this->decaySeconds);

        if ($hits > $this->maxAttempts) {
            throw HttpException::tooManyRequests(max(1, $resetAt - time()))->withKey('throttled');
        }
        /** @var Response $response */
        $response = $next($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxAttempts)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $this->maxAttempts - $hits));
    }

    /** Whose attempts these are: the context entry named by $by (its id), else the client IP. */
    private function who(Request $request): string
    {
        $entry = $this->by === null ? null : $request->context()->find($this->byClass ?? $this->by);

        if ($entry === null) {
            return 'ip:' . $request->ip();   // no $by, or a guest on a route that allows them
        }

        $id = match (true) {
            isset($entry->id) => $entry->id,
            method_exists($entry, 'id') => $entry->id(),
            default => throw new \LogicException('Throttle by: ' . $entry::class . ' needs an id (property or method) to count per ' . $entry::class . '.'),
        };

        return 'by:' . $entry::class . ':' . $id;
    }
}
