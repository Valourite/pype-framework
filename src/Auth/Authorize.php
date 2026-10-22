<?php

declare(strict_types=1);

namespace Pype\Auth;

use Closure;
use LogicException;
use Psr\Container\ContainerInterface;
use Pype\Container\ContainerAware;
use Pype\Exceptions\HttpException;
use Pype\Http\Context;
use Pype\Http\Request;
use Pype\Pipeline\DeclaresContext;
use Pype\Pipeline\Invoker;
use Pype\Pipeline\Provides;
use Pype\Pipeline\Requires;
use Pype\Pipeline\Stage;
use Pype\Pipeline\WithArguments;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * May this user do this? A stage you extend with one method, allows(), whose parameters come from
 * the request context by type (the user, the record a Load stage put there) and route params by
 * name. Return true to go on; false stops the route with a 403, a 404 or a redirect.
 *
 *   final class EnsureAdmin extends Authorize          // php bin/pype make:authorize EnsureAdmin
 *   {
 *       protected ?string $message = 'Only admins can do that.';
 *
 *       public function allows(User $user): bool
 *       {
 *           return $user->role === 'admin';
 *       }
 *   }
 *
 *   final class CanEditTicket extends Authorize
 *   {
 *       protected int $status = 404;                   // hide it rather than say it's there
 *
 *       public function allows(User $user, Ticket $ticket): bool
 *       {
 *           return $ticket->team_id === $user->team_id && $ticket->status !== 'closed';
 *       }
 *   }
 *
 *   $router->patch('/tickets/{id}', ['auth', LoadTicket::class, CanEditTicket::class, ValidateTicket::class, UpdateTicket::class]);
 *
 * It runs where it is listed, like any stage. routes:check reads allows()'s parameters, so a check
 * listed before the stage that loads its record is reported. Services go in the constructor (they
 * are autowired); allows() only takes what's in the context. A nullable parameter is optional.
 *
 * Per route: EnsureAdmin::with(status: 404), ::with(redirectTo: 'home', message: 'Admins only.')
 * (browsers are redirected with the message flashed; JSON clients still get the status).
 *
 * Elsewhere (a page hiding a button): (new EnsureAdmin())->allows($user), or ->check($request).
 */
abstract class Authorize implements Stage, ContainerAware, DeclaresContext
{
    use WithArguments;

    private ?ContainerInterface $container = null;

    /** 403 (forbidden) or 404 (not found: doesn't reveal that the record exists). */
    protected int $status = 403;

    /** The error's message (default: Forbidden / Not Found), or the flash when redirecting. */
    protected ?string $message = null;

    /** Browsers go here instead (a route name or a path); JSON clients still get $status. */
    protected ?string $redirectTo = null;

    // Not promoted: a subclass with its own constructor (for services) doesn't have to call this one.
    public function __construct(?int $status = null, ?string $message = null, ?string $redirectTo = null)
    {
        $this->status = $status ?? $this->status;
        $this->message = $message ?? $this->message;
        $this->redirectTo = $redirectTo ?? $this->redirectTo;
    }

    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->check($request)) {
            return $next($request);
        }

        if ($this->redirectTo !== null && !$request->expectsJson()) {
            return redirect($this->redirectTo, flash: $this->message ?? []);
        }

        throw new HttpException($this->status, $this->message ?? ($this->status === 404 ? 'Not Found' : 'Forbidden'));
    }

    /** Runs allows() against this request: its context, route params and services. */
    public function check(Request $request): bool
    {
        if (!method_exists($this, 'allows')) {
            throw new LogicException(static::class . ' extends Authorize but has no allows() method: public function allows(User $user): bool');
        }

        $container = $this->container ?? throw new LogicException(static::class . ' needs the container: list it in a route, or call allows() yourself.');

        return (new Invoker($container))->call([$this, 'allows'], $request, static fn () => null) === true;
    }

    /**
     * What routes:check expects before this stage: every class allows() takes (except the request,
     * its context and nullable ones), plus any #[Requires] on the class.
     *
     * @return list<string>
     */
    public static function requires(): array
    {
        $keys = [];

        if (method_exists(static::class, 'allows')) {
            foreach ((new ReflectionMethod(static::class, 'allows'))->getParameters() as $param) {
                $type = $param->getType();
                if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && !$type->allowsNull()
                    && !in_array($type->getName(), [Request::class, Context::class, Closure::class], true)) {
                    $keys[] = $type->getName();
                }
            }
        }

        for ($class = new ReflectionClass(static::class); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getAttributes(Requires::class) as $attribute) {
                array_push($keys, ...$attribute->newInstance()->keys);
            }
        }

        return array_values(array_unique($keys));
    }

    /** @return list<string> */
    public static function provides(): array
    {
        $keys = [];

        for ($class = new ReflectionClass(static::class); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getAttributes(Provides::class) as $attribute) {
                array_push($keys, ...$attribute->newInstance()->keys);
            }
        }

        return array_values(array_unique($keys));
    }
}
