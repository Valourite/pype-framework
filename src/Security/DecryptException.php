<?php

declare(strict_types=1);

namespace Pype\Security;

use RuntimeException;

/** A value could not be decrypted: tampered with, malformed, or made with another key. */
final class DecryptException extends RuntimeException
{
}
