<?php

declare(strict_types=1);

namespace Pype\Session;

use Pype\Http\Request;
use Pype\Http\Response;

/**
 * Values to flash if the route succeeds, left in the context by a stage that runs outside the
 * session (RedirectOutlet: outlets wrap every other stage, so it only sees the result after
 * StartSession has saved). StartSession applies them when appliesTo() says the outlet will
 * redirect.
 */
final readonly class FlashOnSuccess
{
    /** @param array<string, mixed> $values */
    public function __construct(public array $values)
    {
    }

    /** A browser request whose pipeline succeeded: the case a success redirect handles. */
    public function appliesTo(Response $response, Request $request): bool
    {
        return !$response->isError() && $response->status() < 300 && !$request->expectsJson();
    }
}
