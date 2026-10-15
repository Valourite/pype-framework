<?php

declare(strict_types=1);

namespace Pype\Stages;

use Closure;
use Pype\Exceptions\HttpException;
use Pype\Http\Request;
use Pype\Pipeline\Stage;

/**
 * Guards request bodies: rejects oversized bodies (413), malformed JSON (400)
 * and, optionally, non-JSON bodies (415).
 *
 *   $app->through(new JsonBody(maxBytes: 512 * 1024));
 *
 * Note: PHP's own post_max_size still applies before the app runs.
 */
final class JsonBody implements Stage
{
    public function __construct(
        private readonly int $maxBytes = 1_048_576,
        private readonly bool $requireJson = false,
        private readonly int $maxDepth = 64,
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $raw = $request->rawBody();
        $length = max(strlen($raw), (int) $request->header('content-length', '0'));

        if ($length > $this->maxBytes) {
            throw new HttpException(413, 'Payload Too Large', ['max_bytes' => $this->maxBytes]);
        }

        if ($raw === '') {
            return $next($request);
        }

        $isJson = (bool) preg_match('#^application/(.+\+)?json#i', $request->header('content-type', ''));

        if (!$isJson) {
            if ($this->requireJson) {
                throw new HttpException(415, 'Unsupported Media Type: send application/json');
            }

            return $next($request);
        }

        json_decode($raw, flags: 0, depth: $this->maxDepth);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpException(400, 'Malformed JSON body', ['reason' => json_last_error_msg()]);
        }

        return $next($request);
    }
}
