<?php

declare(strict_types=1);

namespace Pype\Mail;

/**
 * Delivers a ready message (HTML and text already rendered, from set). Built in: PhpMailerTransport
 * (smtp, sendmail, mail), LogTransport, ArrayTransport (tests), NullTransport. Implement it for
 * anything else (an HTTP API) and bind it in a provider, or name its class in MAIL_DRIVER.
 */
interface Transport
{
    /** @throws MailException when the message can't be delivered */
    public function send(Message $message): void;
}
