<?php

declare(strict_types=1);

namespace Pype\Mail;

use Closure;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Log\LogViewer;
use Pype\Pipeline\Stage;
use Pype\Routing\Urls;

/** The dev mailbox page (/_pype/mail): the list, or one message with ?id=. Registered by MailProvider. */
final class MailboxViewer implements Stage
{
    public function __construct(
        private readonly Mailbox $mailbox,
        private readonly string $driver,
        private readonly ?Urls $urls = null,
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        return $this->page($request, $this->urls ?? Urls::current());
    }

    public function page(Request $request, Urls $urls): Response
    {
        $id = (string) $request->query('id', '');
        $message = $id === '' ? null : $this->mailbox->find($id);

        $html = LogViewer::render(__DIR__ . '/views/mailbox.php', [
            'messages' => $this->mailbox->all(),
            'message' => $message,
            'missing' => $id !== '' && $message === null,
            'driver' => $this->driver,
            'listUrl' => $urls->to('pype.mail'),
        ]);

        return Response::make($html, 200, ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }
}
