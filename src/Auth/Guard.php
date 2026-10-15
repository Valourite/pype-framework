<?php

declare(strict_types=1);

namespace Pype\Auth;

use Pype\Http\Request;

/**
 * One way of recognising who made a request. Authenticate asks its guards in order and
 * uses the first user returned.
 *
 * Built in: SessionGuard (browser login via the session), TokenGuard (Authorization: Bearer).
 * Write your own for API keys, JWTs, signed URLs, basic auth, an upstream proxy header...
 *
 *   final class ApiKeyGuard implements Guard
 *   {
 *       public function __construct(private Connection $db) {}
 *
 *       public function authenticate(Request $request): ?object
 *       {
 *           $key = $request->header('x-api-key');
 *           return $key === null ? null : $this->db->table('clients')->where('key_hash', hash('sha256', $key))->as(Client::class)->first();
 *       }
 *   }
 *
 *   new Authenticate(guards: [ApiKeyGuard::class])
 */
interface Guard
{
    /** The user, or null if this guard doesn't recognise the request. May add extra objects to the context. */
    public function authenticate(Request $request): ?object;
}
