<?php

declare(strict_types=1);

namespace Pype\Session;

use Closure;
use Psr\Container\ContainerInterface;
use Pype\Container\ContainerAware;
use Pype\Http\Cookie;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Pipeline\Stage;

/**
 * Loads the session from its cookie, puts it in the context, and saves it afterwards.
 *
 * Every session gets its CSRF token up front: templates render after the pipeline has
 * finished (and the session is saved), so a token first created there would be lost.
 *
 *   ['web', ...]                                   // 'web' stack (SessionProvider) starts with this
 *   [new StartSession(lifetimeMinutes: 30), ...]   // or configure it yourself
 */
final class StartSession implements Stage, ContainerAware
{
    private ?SessionStore $store = null;

    public function __construct(
        private readonly string $cookie = 'pype_session',
        private readonly int $lifetimeMinutes = 120,
        private readonly bool $secure = false,
        private readonly string $sameSite = 'Lax',
        private readonly ?string $domain = null,
        private readonly int $gcChance = 100,   // 1 in N requests prunes expired sessions; 0 = never
        private readonly int $touchSeconds = 60, // an unchanged session is re-saved (expiry slid) at most this often
    ) {
    }

    public function setContainer(ContainerInterface $container): void
    {
        $this->store = $container->get(SessionStore::class);
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $store = $this->store ?? throw new \LogicException('StartSession needs the container. Is SessionProvider registered?');

        $session = $this->load($store, $request->cookie($this->cookie));
        $loaded = $session->all();
        $lastWritten = (int) $session->get(Session::WRITTEN, 0);
        $session->token();
        $request->context()->put($session);

        $response = Response::from($next($request));

        foreach ($session->replacedIds() as $old) {
            $store->destroy($old);
        }

        if ($this->gcChance > 0 && random_int(1, $this->gcChance) === 1) {
            $store->gc();
        }

        // Most page views change nothing, so skip the write unless the data changed or the
        // expiry needs sliding forward. Saves a store write (a DB write) on nearly every click.
        $data = $session->dataToStore();
        unset($data[Session::WRITTEN]);
        $now = time();

        if ($session->isNew() || $session->replacedIds() !== [] || $data !== $loaded || $now - $lastWritten >= $this->touchSeconds) {
            $data[Session::WRITTEN] = $now;
            $store->write($session->id(), $data, $this->lifetimeMinutes * 60);
        }

        return $response->withCookie(new Cookie(
            $this->cookie,
            $session->id(),
            maxAge: $this->lifetimeMinutes * 60,
            domain: $this->domain,
            secure: $this->secure,
            sameSite: $this->sameSite,
        ));
    }

    private function load(SessionStore $store, ?string $id): Session
    {
        if ($id !== null && Session::isValidId($id) && ($data = $store->read($id)) !== null) {
            return new Session($id, $data, isNew: false);
        }

        return new Session(Session::generateId());
    }
}
