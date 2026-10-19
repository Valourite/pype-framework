<?php

declare(strict_types=1);

namespace Pype\Mail;

use Psr\Log\LoggerInterface;

/**
 * Doesn't send: writes each message to the log (MAIL_DRIVER=log, the default). Read them in the
 * log viewer while developing. Use Mailpit (smtp to localhost:1025) to see them rendered.
 */
final class LogTransport implements Transport
{
    public function __construct(private readonly LoggerInterface $log)
    {
    }

    public function send(Message $message): void
    {
        $this->log->info('Mail to {to}: {subject}', [
            'to' => implode(', ', array_column($message->recipients('to'), 0)),
            'subject' => $message->getSubject(),
            'from' => $message->sender()[0] ?? null,
            'cc' => array_column($message->recipients('cc'), 0),
            'bcc' => array_column($message->recipients('bcc'), 0),
            'attachments' => array_column($message->attachments(), 'name'),
            'text' => $message->getText(),
        ]);
    }
}
