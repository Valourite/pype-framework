<?php

declare(strict_types=1);

namespace Pype\Mail;

use RuntimeException;

/** A message could not be delivered (connection, authentication, rejected address...). */
final class MailException extends RuntimeException
{
}
