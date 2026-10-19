<?php

declare(strict_types=1);

namespace Pype\Stages;

use Pype\Http\Request;

/** The current request's id, as put in the context by the RequestId stage (or the logger). */
final class RequestIdValue
{
    public function __construct(public readonly string $value)
    {
    }

    /** This request's id: the one already in its context, or a new one stored there. */
    public static function for(Request $request): self
    {
        $id = $request->context()->find(self::class);

        if ($id === null) {
            $id = new self(bin2hex(random_bytes(8)));
            $request->context()->put($id);
        }

        return $id;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
