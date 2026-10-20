<?php

declare(strict_types=1);

namespace Pype\Routing;

use Closure;
use Psr\Container\ContainerInterface;
use Pype\Container\ContainerAware;
use Pype\Exceptions\HttpException;
use Pype\Http\Request;
use Pype\Pipeline\Stage;

/**
 * Lets a request through only if it came from an untouched, unexpired signed_url().
 * Anything else is a 403.
 *
 *   $router->get('/password/reset/{user}', [new ValidateSignature(), ShowResetForm::class])
 *       ->name('password.reset');
 */
final class ValidateSignature implements Stage, ContainerAware
{
    private ?Urls $urls = null;

    public function __construct(private readonly string $message = 'This link is invalid or has expired.')
    {
    }

    public function setContainer(ContainerInterface $container): void
    {
        $this->urls = $container->get(Urls::class);
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if (!($this->urls ?? Urls::current())->hasValidSignature($request)) {
            throw HttpException::forbidden($this->message)->withKey('invalid_signature');
        }

        return $next($request);
    }
}
