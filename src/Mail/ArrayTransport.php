<?php

declare(strict_types=1);

namespace Pype\Mail;

/** Keeps sent messages in memory (MAIL_DRIVER=array), for tests: assert on messages(). */
final class ArrayTransport implements Transport
{
    /** @var list<Message> */
    private array $messages = [];

    public function send(Message $message): void
    {
        $this->messages[] = $message;
    }

    /** @return list<Message> */
    public function messages(): array
    {
        return $this->messages;
    }

    public function clear(): void
    {
        $this->messages = [];
    }
}
