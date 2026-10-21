<?php

declare(strict_types=1);

namespace Pype\Auth;

use Closure;
use Psr\Container\ContainerInterface;
use Pype\Container\ContainerAware;
use Pype\Exceptions\HttpException;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Pipeline\Requires;
use Pype\Pipeline\Stage;
use Pype\Pipeline\WithArguments;

/**
 * Lets only users with a verified email through. Put it after Authenticate:
 *
 *   ['web', new Authenticate(redirectTo: '/login'), new EnsureEmailIsVerified(redirectTo: '/email/verify'), ...]
 *
 * Browsers go to $redirectTo (a page explaining what to do, with a "resend" button); API
 * clients get a 403.
 */
#[Requires(Auth::USER)]
final class EnsureEmailIsVerified implements Stage, ContainerAware
{
    use WithArguments;

    private ?Auth $auth = null;

    public function __construct(private readonly ?string $redirectTo = null)
    {
    }

    public function setContainer(ContainerInterface $container): void
    {
        $this->auth = $container->get(Auth::class);
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $auth = $this->auth ?? throw new \LogicException('EnsureEmailIsVerified needs AuthProvider.');
        $user = $request->context()->find($auth->userClass())
            ?? throw new \LogicException('EnsureEmailIsVerified needs a signed-in user: put Authenticate before it.');

        if ($auth->isEmailVerified($user)) {
            return $next($request);
        }

        if ($this->redirectTo !== null && !$request->expectsJson()) {
            return Response::redirect($this->redirectTo);
        }

        throw HttpException::forbidden('Your email address is not verified.')->withKey('unverified_email');
    }
}
