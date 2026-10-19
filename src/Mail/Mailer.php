<?php

declare(strict_types=1);

namespace Pype\Mail;

use Closure;
use LogicException;
use Psr\Log\LoggerInterface;
use Pype\Http\AfterResponse;
use Pype\Http\Request;
use Pype\View\Views;
use Throwable;

/**
 * Sends Messages (or Mail classes) through the configured Transport.
 *
 *   fn (Mailer $mailer) => $mailer->send(Message::to('ada@example.com')->subject('Hi')->html('<p>Hello</p>'));
 *   $mailer->later(new WelcomeMail($user));   // after the response, so the user doesn't wait
 *
 * Before sending it fills in the default sender (MAIL_FROM_ADDRESS / MAIL_FROM_NAME), renders a
 * view() into HTML, and makes the plain-text part from the HTML if none was given.
 */
final class Mailer
{
    /**
     * @param array{0: string, 1: string}|null $from default sender
     * @param Closure(): ?Request|null         $request the request being handled, if any
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly ?array $from = null,
        private readonly ?Views $views = null,
        private readonly ?LoggerInterface $log = null,
        private readonly ?Closure $request = null,
    ) {
    }

    /** Send now: the request waits for the mail server, and failures throw MailException. */
    public function send(Message|Mail $mail): void
    {
        $message = $this->prepare($mail);
        $this->transport->send($message);
        $this->logSent($message);
    }

    /**
     * Send once the response has reached the client (no request: right away). The message is
     * built and rendered now; only the delivery waits. Failures are logged, not thrown.
     */
    public function later(Message|Mail $mail): void
    {
        $message = $this->prepare($mail);
        $request = $this->request?->__invoke();

        $deliver = function () use ($message): void {
            try {
                $this->transport->send($message);
                $this->logSent($message);
            } catch (Throwable $e) {
                $this->log?->error('Mail to {to} failed: {error}', ['to' => self::to($message), 'error' => $e->getMessage(), 'subject' => $message->getSubject()]);
                if ($this->log === null) {
                    error_log('[pype] mail failed: ' . $e->getMessage());
                }
            }
        };

        $request === null ? $deliver() : AfterResponse::of($request)->push($deliver);
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    /** A copy of the message, complete: sender, rendered HTML, text part. */
    public function prepare(Message|Mail $mail): Message
    {
        $message = clone ($mail instanceof Mail ? $mail->build() : $mail);

        if ($message->recipients('to') === [] && $message->recipients('cc') === [] && $message->recipients('bcc') === []) {
            throw new LogicException('The message has no recipients.');
        }

        if ($message->sender() === null && $this->from !== null) {
            $message->from(...$this->from);
        }

        if ($message->getView() !== null && $message->getHtml() === null) {
            [$template, $data] = $message->getView();
            $views = $this->views ?? throw new LogicException('Message::view() needs Pype\View\ViewProvider in config/app.php.');
            $message->html($views->render($template, $data));
        }

        if ($message->getText() === null && $message->getHtml() !== null) {
            $message->text(self::textFrom($message->getHtml()));
        }

        return $message;
    }

    /** A readable plain-text version of an HTML email: links kept as "label (url)", blocks as lines. */
    public static function textFrom(string $html): string
    {
        $text = (string) preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', '', $html);
        $text = (string) preg_replace_callback('#<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', static function (array $m): string {
            $label = trim(strip_tags($m[3]));

            return $label === '' || $label === $m[2] ? $m[2] : "{$label} ({$m[2]})";
        }, $text);
        $text = (string) preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = (string) preg_replace('#</(p|div|h[1-6]|li|tr|table|blockquote)>#i', "\n\n", $text);
        $text = (string) preg_replace('#<li\b[^>]*>#i', '- ', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/ *\n */', "\n", $text);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    private function logSent(Message $message): void
    {
        if (!$this->transport instanceof LogTransport) {
            $this->log?->info('Mail sent to {to}: {subject}', ['to' => self::to($message), 'subject' => $message->getSubject()]);
        }
    }

    private static function to(Message $message): string
    {
        return implode(', ', array_column([...$message->recipients('to'), ...$message->recipients('cc'), ...$message->recipients('bcc')], 0));
    }
}
