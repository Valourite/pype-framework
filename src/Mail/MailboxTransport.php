<?php

declare(strict_types=1);

namespace Pype\Mail;

/**
 * Sends through the configured transport, then keeps a copy in the Mailbox for /_pype/mail.
 * MailProvider wraps the transport with it while the dev mailbox is on (APP_DEBUG by default).
 */
final class MailboxTransport implements Transport
{
    public function __construct(
        private readonly Transport $inner,
        private readonly Mailbox $mailbox,
        private readonly string $driver,
    ) {
    }

    public function send(Message $message): void
    {
        $this->inner->send($message);
        $this->mailbox->store($message, $this->driver);
    }

    public function inner(): Transport
    {
        return $this->inner;
    }
}
