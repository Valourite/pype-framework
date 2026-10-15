<?php

declare(strict_types=1);

namespace Pype\Auth;

use Closure;
use Psr\Container\ContainerInterface;
use Pype\Container\ContainerAware;
use Pype\Exceptions\HttpException;
use Pype\Http\Request;
use Pype\Pipeline\Stage;

/**
 * Bearer-token guard. On success, puts the user (as your configured user class) and the
 * AccessToken in the context, so later stages can inject `User $user` / `AccessToken $token`.
 *
 *   ['auth', ShowProfile::class]                       // 'auth' stack = this stage (AuthProvider)
 *   [new Authenticate(optional: true), Feed::class]    // anonymous allowed; user injected if present
 */
final class Authenticate implements Stage, ContainerAware
{
    private ?Auth $auth = null;

    public function __construct(private readonly bool $optional = false)
    {
    }

    public function setContainer(ContainerInterface $container): void
    {
        $this->auth = $container->get(Auth::class);
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $auth = $this->auth ?? throw new \LogicException('Authenticate needs the container. Is AuthProvider registered?');

        if (preg_match('/^Bearer\s+(\S+)$/i', (string) $request->header('authorization'), $m)
            && ($found = $auth->authenticateToken($m[1])) !== null
        ) {
            [$user, $token] = $found;
            $request->context()->put($user)->put($token);

            return $next($request);
        }

        if ($this->optional) {
            return $next($request);
        }

        throw HttpException::unauthorized();
    }
}
