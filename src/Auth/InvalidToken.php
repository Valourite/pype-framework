<?php

declare(strict_types=1);

namespace Pype\Auth;

/** A JWT that failed verification; the message says why (for logs, not for clients). */
final class InvalidToken extends \RuntimeException
{
}
