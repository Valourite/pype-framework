<?php

declare(strict_types=1);

namespace Pype\Http;

/** A result that knows how to become a Response. Return one from any stage or handler. */
interface Responsable
{
    public function toResponse(): Response;
}
