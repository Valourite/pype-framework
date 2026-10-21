<?php

declare(strict_types=1);

namespace Pype\Mail;

use Psr\Log\LoggerInterface;
use Pype\Application;
use Pype\Http\Request;
use Pype\Outlet\HtmlOutlet;
use Pype\Provider;
use Pype\Queue\Queue;
use Pype\Routing\Router;
use Pype\View\Views;
use Throwable;

/**
 * Binds the Mailer from config/mail.php. MAIL_DRIVER picks the transport: smtp, sendmail and
 * mail go through PHPMailer; log (default) writes messages to the log; array keeps them in
 * memory for tests; null drops them; or the class name of your own Transport.
 *
 * The dev mailbox (mail.mailbox, on with APP_DEBUG): every message sent is also kept as a file and
 * listed at /_pype/mail, with its links, whatever the driver (not array or null). Like the log
 * viewer, outside debug it only runs when enabled and given stages that guard it.
 */
final class MailProvider implements Provider
{
    public function register(Application $app): void
    {
        $container = $app->container;

        $driver = (string) $app->config('mail.driver', 'log');
        $stages = (array) $app->config('mail.mailbox.stages', []);
        $mailbox = (bool) $app->config('mail.mailbox.enabled', $app->config('app.debug', false))
            && ($app->config('app.debug') || $stages !== [])
            && !in_array($driver, ['array', 'null'], true);

        $container->singleton(Transport::class, function ($c) use ($app, $driver, $mailbox) {
            $transport = match ($driver) {
                'smtp', 'sendmail', 'mail' => new PhpMailerTransport($driver, (array) $app->config('mail.smtp', [])),
                'log' => new LogTransport($c->get(LoggerInterface::class)),
                'array' => new ArrayTransport(),
                'null' => new NullTransport(),
                default => $c->get($driver),
            };

            return $mailbox ? new MailboxTransport($transport, $c->get(Mailbox::class), $driver) : $transport;
        });

        if ($mailbox) {
            $container->singleton(Mailbox::class, fn () => new Mailbox(
                $app->path((string) $app->config('mail.mailbox.dir', 'storage/mail')),
                (int) $app->config('mail.mailbox.keep', 100),
            ));
            $container->singleton(MailboxViewer::class, fn ($c) => new MailboxViewer($c->get(Mailbox::class), $driver));

            $app->router->group(rtrim((string) $app->config('mail.mailbox.path', '/_pype/mail'), '/'), $stages, function (Router $r) {
                $r->get('/', [MailboxViewer::class, HtmlOutlet::class])->name('mail');
            }, name: 'pype.');
        }

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
