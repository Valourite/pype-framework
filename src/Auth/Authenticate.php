<?php

declare(strict_types=1);

namespace Pype\Auth;

use Closure;
use Psr\Container\ContainerInterface;
use Pype\Container\ContainerAware;
use Pype\Exceptions\HttpException;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Pipeline\Provides;
use Pype\Pipeline\Stage;
use Pype\Pipeline\WithArguments;
use Pype\Session\Session;

/**
 * Requires a user. Asks each guard in turn (default: config auth.guards, i.e. session then
 * bearer token) and puts the first user found in the context, so later stages can inject
 * `User $user`.
 *
 *   ['auth', ShowProfile::class]                                // 'auth' stack = new Authenticate()
 *   [new Authenticate(guards: [TokenGuard::class]), ...]        // API only
 *   ['web', new Authenticate(redirectTo: '/login'), ...]        // browsers go to the login page
 *   [new Authenticate(optional: true), Feed::class]             // guests allowed; user injected if present
 */
// The user, plus what the guard that recognised them leaves: the API token (TokenGuard) or the JWT's
// claims (JwtGuard). Trusted as declared: a route signed in by session has no token.
#[Provides(Auth::USER, AccessToken::class, JwtClaims::class)]
final class Authenticate implements Stage, ContainerAware
{
    use WithArguments;

    private ?ContainerInterface $container = null;

    /**
     * @param list<class-string<Guard>|Guard>|null $guards  null = config('auth.guards')
     * @param string|null $redirectTo  send browsers here instead of a 401 (JSON clients still get 401)
     */
    public function __construct(
        private readonly ?array $guards = null,
        private readonly bool $optional = false,
        private readonly ?string $redirectTo = null,
    ) {
    }

    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $container = $this->container ?? throw new \LogicException('Authenticate needs the container. Is AuthProvider registered?');

        foreach ($this->guards ?? $container->get(Auth::class)->guards() as $guard) {
            $guard = is_string($guard) ? $container->get($guard) : $guard;

            if (($user = $guard->authenticate($request)) !== null) {
                $request->context()->put($user);

                return $next($request);
            }
        }

        if ($this->optional) {
            return $next($request);
        }

        if ($this->redirectTo !== null && !$request->expectsJson()) {
            $session = $request->context()->find(Session::class);

            if ($session !== null && $request->method() === 'GET') {
                $container->get(SessionGuard::class)->setIntended($session, $request->path() . $this->queryString($request));
            }

            return Response::redirect($this->redirectTo);
        }

        throw HttpException::unauthorized()->withKey('unauthenticated');
    }

    private function queryString(Request $request): string
    {
        $query = $request->query();

        return $query === [] ? '' : '?' . http_build_query($query);
    }
}
