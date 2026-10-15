<?php

declare(strict_types=1);

namespace Pype\Stages;

use Closure;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Pipeline\Stage;

/**
 * CORS headers + preflight handling. Register as a *global* stage so OPTIONS
 * preflights are answered before route lookup:
 *
 *   $app->through(new Cors(origins: ['https://app.example.com'], credentials: true));
 */
final class Cors implements Stage
{
    /**
     * @param list<string> $origins  exact origins, or ['*']
     * @param list<string> $methods
     * @param list<string> $headers  allowed request headers, or ['*'] to echo what the browser asks for
     * @param list<string> $expose   response headers readable by browser JS
     */
    public function __construct(
        private readonly array $origins = ['*'],
        private readonly array $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        private readonly array $headers = ['*'],
        private readonly array $expose = [],
        private readonly bool $credentials = false,
        private readonly int $maxAge = 600,
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $origin = $request->header('origin');

        if ($origin === null) {
            return $next($request);
        }

        $allowed = $this->allowedOrigin($origin);

        if ($request->method() === 'OPTIONS' && $request->header('access-control-request-method') !== null) {
            $response = Response::noContent()->withHeader('Vary', 'Origin, Access-Control-Request-Headers');

            if ($allowed === null) {
                return $response;
            }

            $response = $this->decorate($response, $allowed)
                ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->methods))
                ->withHeader('Access-Control-Max-Age', (string) $this->maxAge);

            $headers = $this->headers === ['*']
                ? (string) $request->header('access-control-request-headers', '')
                : implode(', ', $this->headers);

            return $headers === '' ? $response : $response->withHeader('Access-Control-Allow-Headers', $headers);
        }

        /** @var Response $response */
        $response = $next($request);
        $response = $response->withHeader('Vary', 'Origin');

        if ($allowed === null) {
            return $response;
        }

        if ($this->expose !== []) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->expose));
        }

        return $this->decorate($response, $allowed);
    }

    private function allowedOrigin(string $origin): ?string
    {
        if (in_array('*', $this->origins, true)) {
            // Browsers reject "*" together with credentials, so echo the origin instead.
            return $this->credentials ? $origin : '*';
        }

        return in_array($origin, $this->origins, true) ? $origin : null;
    }

    private function decorate(Response $response, string $origin): Response
    {
        $response = $response->withHeader('Access-Control-Allow-Origin', $origin);

        return $this->credentials ? $response->withHeader('Access-Control-Allow-Credentials', 'true') : $response;
    }
}
