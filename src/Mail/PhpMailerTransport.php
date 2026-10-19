<?php

declare(strict_types=1);

namespace Pype\Mail;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Sends through PHPMailer (composer require phpmailer/phpmailer): over SMTP (MAIL_DRIVER=smtp),
 * the local sendmail binary ('sendmail') or PHP's mail() ('mail').
 */
final class PhpMailerTransport implements Transport
{
    /**
     * @param array{host?: string, port?: int, username?: string, password?: string, encryption?: string, timeout?: int} $smtp
     */
    public function __construct(
        private readonly string $method = 'smtp',
        #[\SensitiveParameter] private readonly array $smtp = [],
    ) {
        if (!class_exists(PHPMailer::class)) {
            throw new MailException("MAIL_DRIVER={$method} needs PHPMailer: composer require phpmailer/phpmailer");
        }
    }

    public function send(Message $message): void
    {
        try {
            $this->build($message)->send();
        } catch (PHPMailerException $e) {
            throw new MailException('Mail could not be sent: ' . $e->getMessage(), 0, $e);
        }
    }

    /** The configured PHPMailer for a message, ready to send (separate so tests can inspect it). */
    public function build(Message $message): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->XMailer = ' ';   // don't advertise the library

        match ($this->method) {
            'smtp' => $this->smtp($mail),
            'sendmail' => $mail->isSendmail(),
            'mail' => $mail->isMail(),
            default => throw new MailException("Unknown PHPMailer method [{$this->method}]."),
        };

        [$fromAddress, $fromName] = $message->sender() ?? throw new MailException('The message has no sender. Set MAIL_FROM_ADDRESS.');
        $mail->setFrom($fromAddress, $fromName);

        foreach ($message->recipients('to') as [$address, $name]) {
            $mail->addAddress($address, $name);
        }
        foreach ($message->recipients('cc') as [$address, $name]) {
            $mail->addCC($address, $name);
        }
        foreach ($message->recipients('bcc') as [$address, $name]) {
            $mail->addBCC($address, $name);
        }
        foreach ($message->recipients('reply_to') as [$address, $name]) {
            $mail->addReplyTo($address, $name);
        }

        $mail->Subject = $message->getSubject();

        if ($message->getHtml() !== null) {
            $mail->isHTML();
            $mail->Body = $message->getHtml();
            $mail->AltBody = (string) $message->getText();
        } else {
            $mail->Body = (string) $message->getText();
        }

        foreach ($message->attachments() as $file) {
            isset($file['path'])
                ? $mail->addAttachment($file['path'], $file['name'], PHPMailer::ENCODING_BASE64, $file['mime'])
                : $mail->addStringAttachment($file['data'], $file['name'], PHPMailer::ENCODING_BASE64, $file['mime']);
        }

        return $mail;
    }

    private function smtp(PHPMailer $mail): void
    {
        $mail->isSMTP();
        $mail->Host = (string) ($this->smtp['host'] ?? 'localhost');
        $mail->Port = (int) ($this->smtp['port'] ?? 587);
        $mail->Timeout = (int) ($this->smtp['timeout'] ?? 10);

        if (($this->smtp['username'] ?? '') !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = (string) $this->smtp['username'];
            $mail->Password = (string) ($this->smtp['password'] ?? '');
        }

        // tls = STARTTLS (usually port 587), ssl = TLS from the start (465), none = plain (local Mailpit).
        $encryption = strtolower((string) ($this->smtp['encryption'] ?? 'tls'));
        $mail->SMTPSecure = match ($encryption) {
            'ssl', 'smtps' => PHPMailer::ENCRYPTION_SMTPS,
            'tls', 'starttls' => PHPMailer::ENCRYPTION_STARTTLS,
            default => '',
        };
        $mail->SMTPAutoTLS = $mail->SMTPSecure !== '';
    }
}
