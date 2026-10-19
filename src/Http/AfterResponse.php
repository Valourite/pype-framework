<?php

declare(strict_types=1);

namespace Pype\Http;

use Closure;

/**
 * Work to do once the response has reached the client: sending mail, pinging a webhook...
 * Kept in the request's context, so it belongs to one request only.
 *
 *   AfterResponse::of($request)->push(fn () => $mailer->send($message));
 *
 * Application::terminate() runs it: after fastcgi_finish_request() under PHP-FPM, after the
 * response is written under OpenSwoole, right after handle() in tests and the CLI.
 */
final class AfterResponse
{
    /** @var list<Closure> */
    private array $callbacks = [];

    public static function of(Request $request): self
    {
        $after = $request->context()->find(self::class);

        if ($after === null) {
            $after = new self();
            $request->context()->put($after);
        }

        return $after;
    }

    public function push(Closure $callback): void
    {
        $this->callbacks[] = $callback;
    }

    /** @return list<Closure> the callbacks, emptied so they only run once */
    public function take(): array
    {
        [$callbacks, $this->callbacks] = [$this->callbacks, []];

        return $callbacks;
    }
}
