<?php

declare(strict_types=1);

namespace Pype\Testing;

use Pype\Http\Context;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Outlet\JsonOutlet;

/** What Chain::run() left behind: the request (and its context) and the response. */
final readonly class ChainResult
{
    public function __construct(
        private Request $request,
        private Response $response,
        private bool $reachedEnd,
    ) {
    }

    public function request(): Request
    {
        return $this->request;
    }

    /** What the stages put in the context (and what with() put there before them). */
    public function context(): Context
    {
        return $this->request->context();
    }

    public function response(): Response
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->response->status();
    }

    /** What the last stage returned. */
    public function data(): mixed
    {
        return $this->response->data();
    }

    /** True when every stage called $next (none stopped the chain early). */
    public function reachedEnd(): bool
    {
        return $this->reachedEnd;
    }

    /** The response as its outlet writes it (JSON when no outlet claimed it). */
    public function body(): string
    {
        return ($this->response->outlet() ?? new JsonOutlet())->render($this->response, $this->request);
    }
}
