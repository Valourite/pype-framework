<?php

declare(strict_types=1);

namespace Pype\Mail;

use InvalidArgumentException;

/**
 * One email. Build it fluently and hand it to the Mailer:
 *
 *   $mailer->send(Message::to($user->email, $user->name)
 *       ->subject('Your receipt')
 *       ->view('emails/receipt', ['order' => $order])   // resources/views/emails/receipt.php
 *       ->attach($pdfPath, 'receipt.pdf'));
 *
 * Without a text body, the Mailer makes one from the HTML. Without from(), the configured
 * MAIL_FROM_ADDRESS is used. Addresses are validated; names can't carry line breaks.
 */
final class Message
{
    /** @var list<array{0: string, 1: string}> address, name */
    private array $to = [];
    private array $cc = [];
    private array $bcc = [];
    private array $replyTo = [];
    private ?array $from = null;
    private string $subject = '';
    private ?string $html = null;
    private ?string $text = null;

    /** @var array{0: string, 1: array}|null template name, data */
    private ?array $view = null;

    /** @var list<array{path?: string, data?: string, name: string, mime: string}> */
    private array $attachments = [];

    public static function to(string $address, string $name = ''): self
    {
        return (new self())->addTo($address, $name);
    }

    public function addTo(string $address, string $name = ''): self
    {
        $this->to[] = self::address($address, $name);

        return $this;
    }

    public function cc(string $address, string $name = ''): self
    {
        $this->cc[] = self::address($address, $name);

        return $this;
    }

    public function bcc(string $address, string $name = ''): self
    {
        $this->bcc[] = self::address($address, $name);

        return $this;
    }

    public function replyTo(string $address, string $name = ''): self
    {
        $this->replyTo[] = self::address($address, $name);

        return $this;
    }

    public function from(string $address, string $name = ''): self
    {
        $this->from = self::address($address, $name);

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = str_replace(["\r", "\n"], ' ', $subject);

        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;

        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    /** HTML from a template in resources/views, rendered when the message is sent. */
    public function view(string $template, array $data = []): self
    {
        $this->view = [$template, $data];

        return $this;
    }

    public function attach(string $path, ?string $name = null, string $mime = ''): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException("Cannot attach {$path}: not a readable file.");
        }

        $this->attachments[] = ['path' => $path, 'name' => $name ?? basename($path), 'mime' => $mime];

        return $this;
    }

    /** Attach generated content (a CSV, a PDF built in memory...). */
    public function attachData(string $data, string $name, string $mime = 'application/octet-stream'): self
    {
        $this->attachments[] = ['data' => $data, 'name' => $name, 'mime' => $mime];

        return $this;
    }

    /** @return list<array{0: string, 1: string}> */
    public function recipients(string $kind = 'to'): array
    {
        return match ($kind) {
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'reply_to' => $this->replyTo,
            default => throw new InvalidArgumentException("Unknown recipient kind [{$kind}]."),
        };
    }

    public function sender(): ?array
    {
        return $this->from;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getHtml(): ?string
    {
        return $this->html;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getView(): ?array
    {
        return $this->view;
    }

    /** A copy without the view() template and data (once rendered, e.g. before queueing). */
    public function withoutView(): self
    {
        $copy = clone $this;
        $copy->view = null;

        return $copy;
    }

    public function attachments(): array
    {
        return $this->attachments;
    }

    /** @return array{0: string, 1: string} */
    private static function address(string $address, string $name): array
    {
        $address = trim($address);

        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("Invalid email address [{$address}].");
        }

        return [$address, trim(str_replace(["\r", "\n"], ' ', $name))];
    }
}
