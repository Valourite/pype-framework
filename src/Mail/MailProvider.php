<?php

declare(strict_types=1);

namespace Pype\Mail;

use Psr\Log\LoggerInterface;
use Pype\Application;
use Pype\Http\Request;
use Pype\Provider;
use Pype\Queue\Queue;
use Pype\View\Views;
use Throwable;

/**
 * Binds the Mailer from config/mail.php. MAIL_DRIVER picks the transport: smtp, sendmail and
 * mail go through PHPMailer; log (default) writes messages to the log; array keeps them in
 * memory for tests; null drops them; or the class name of your own Transport.
 */
final class MailProvider implements Provider
{
    public function register(Application $app): void
    {
        $container = $app->container;

        $container->singleton(Transport::class, fn ($c) => match ($driver = (string) $app->config('mail.driver', 'log')) {
            'smtp', 'sendmail', 'mail' => new PhpMailerTransport($driver, (array) $app->config('mail.smtp', [])),
            'log' => new LogTransport($c->get(LoggerInterface::class)),
            'array' => new ArrayTransport(),
            'null' => new NullTransport(),
            default => $c->get($driver),
        });

        $container->singleton(Mailer::class, fn ($c) => new Mailer(
            $c->get(Transport::class),
            ($address = (string) $app->config('mail.from.address', '')) === '' ? null : [$address, (string) $app->config('mail.from.name', '')],
            self::optional($c, Views::class),
            self::optional($c, LoggerInterface::class),
            // One request at a time per process, so the bound request is the current one.
            static fn (): ?Request => $container->resolved(Request::class) ? $container->get(Request::class) : null,
            $container->has(Queue::class) ? static fn (): Queue => $container->get(Queue::class) : null,
        ));
    }

    private static function optional($container, string $id): ?object
    {
        try {
            return $container->get($id);
        } catch (Throwable) {
            return null;
        }
    }
}
