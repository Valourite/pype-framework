<?php

declare(strict_types=1);

namespace Pype\Mail;

/** The job Mailer::queue() pushes: delivers an already rendered Message. */
final class SendQueuedMail
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly Message $message)
    {
    }

    public function handle(Mailer $mailer): void
    {
        $mailer->send($this->message);
    }
}
