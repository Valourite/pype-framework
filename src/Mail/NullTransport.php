<?php

declare(strict_types=1);

namespace Pype\Mail;

/** Drops every message (MAIL_DRIVER=null). */
final class NullTransport implements Transport
{
    public function send(Message $message): void
    {
    }
}
