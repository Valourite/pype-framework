<?php

declare(strict_types=1);

namespace Pype\Stages;

use Closure;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Pipeline\Stage;

/**
 * Gives every request an id (reusing a sane incoming X-Request-Id), exposes it to later
 * stages via the context, and echoes it on the response — including error responses.
 *
 *   $app->through(new RequestId());
 *   fn (RequestIdValue $id) => ...   // or $request->context()->get(RequestIdValue::class)
 */
final class RequestId implements Stage
{
    public function __construct(
        private readonly string $header = 'X-Request-Id',
        private readonly bool $trustIncoming = true,
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $incoming = $request->header($this->header);
        $id = $this->trustIncoming && $incoming !== null && preg_match('/^[A-Za-z0-9._\-]{8,128}$/', $incoming)
            ? $incoming
            : bin2hex(random_bytes(16));

        $request->context()->put(new RequestIdValue($id));

        /** @var Response $response */
        $response = $next($request);

        return $response->withHeader($this->header, $id);
    }
}
