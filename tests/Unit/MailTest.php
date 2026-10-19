<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Pype\Application;
use Pype\Http\Request;
use Pype\Mail\ArrayTransport;
use Pype\Mail\Mail;
use Pype\Mail\Mailer;
use Pype\Mail\MailProvider;
use Pype\Mail\Message;
use Pype\Mail\PhpMailerTransport;
use Pype\Mail\Transport;
use Pype\Testing\TestClient;
use Pype\View\ViewProvider;

final class MailTest extends TestCase
{
    private function app(array $mail = []): Application
    {
        return (new Application(dirname(__DIR__), [
            'app' => ['views' => 'fixtures/views'],
            'mail' => $mail + ['driver' => 'array', 'from' => ['address' => 'hello@app.test', 'name' => 'Spendwise']],
        ]))->register(ViewProvider::class)->register(MailProvider::class);
    }

    private function sent(Application $app): array
    {
        return $app->container->get(Transport::class)->messages();
    }

    public function test_messages_validate_addresses_and_strip_line_breaks(): void
    {
        $message = Message::to('ada@x.test', "Ada\r\nBcc: evil@x.test")->subject("Hi\r\nBcc: evil@x.test");

        $this->assertSame([['ada@x.test', 'Ada  Bcc: evil@x.test']], $message->recipients());
        $this->assertSame('Hi  Bcc: evil@x.test', $message->getSubject());

        $this->expectException(\InvalidArgumentException::class);
        Message::to("ada@x.test\r\nBcc: evil@x.test");
    }

    public function test_send_fills_sender_renders_view_and_text(): void
    {
        $app = $this->app();
        $app->container->get(Mailer::class)->send(Message::to('ada@x.test')->subject('Welcome')->view('emails/welcome', ['name' => 'Ada <3']));

        [$message] = $this->sent($app);
        $this->assertSame(['hello@app.test', 'Spendwise'], $message->sender());
        $this->assertStringContainsString('Welcome, Ada &lt;3!', $message->getHtml());
        $this->assertSame("Welcome, Ada <3!\n\nYour account is ready.\nStart by adding your first expense.\n\nAdd an expense (https://app.test/expenses/new)", $message->getText());
    }

    public function test_mail_classes(): void
    {
        $mail = new class ('bob@x.test') extends Mail {
            public function __construct(private readonly string $email)
            {
            }

            public function build(): Message
            {
                return Message::to($this->email)->subject('Receipt')->text('Thanks!')->attachData('a,b', 'receipt.csv', 'text/csv');
            }
        };
        $app = $this->app();
        $app->container->get(Mailer::class)->send($mail);

        [$message] = $this->sent($app);
        $this->assertSame('Receipt', $message->getSubject());
        $this->assertSame('Thanks!', $message->getText());
        $this->assertSame('receipt.csv', $message->attachments()[0]['name']);
    }

    public function test_later_waits_until_the_response_is_sent(): void
    {
        $app = $this->app();
        $app->router->post('/signup', fn (Mailer $mailer) => $mailer->later(Message::to('ada@x.test')->subject('Welcome')->text('Hi')) ?? 'ok');
        $request = Request::create('POST', '/signup');

        $response = $app->handle($request);
        $this->assertSame(200, $response->status());
        $this->assertSame([], $this->sent($app), 'not sent while the response is being built');

        $app->terminate($request);
        $this->assertCount(1, $this->sent($app));

        $app->terminate($request);
        $this->assertCount(1, $this->sent($app), 'runs once');

        // Outside a request (CLI): sent right away.
        $app->container->get(Mailer::class)->later(Message::to('bob@x.test')->text('x'));
        $this->assertCount(2, $this->sent($app));
    }

    public function test_a_failing_later_send_is_logged_and_the_response_is_fine(): void
    {
        $log = new class () extends AbstractLogger {
            public array $lines = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = [$level, strtr((string) $message, ['{to}' => $context['to'] ?? '', '{error}' => $context['error'] ?? ''])];
            }
        };
        $broken = new class () implements Transport {
            public function send(Message $message): void
            {
                throw new \Pype\Mail\MailException('Connection refused');
            }
        };
        $app = $this->app();
        $app->container->instance(Transport::class, $broken);
        $app->container->instance(\Psr\Log\LoggerInterface::class, $log);
        $app->router->post('/signup', fn (Mailer $mailer) => $mailer->later(Message::to('ada@x.test')->text('Hi')) ?? 'ok');

        $this->assertSame(200, (new TestClient($app))->post('/signup')->status());
        $this->assertSame([['error', 'Mail to ada@x.test failed: Connection refused']], $log->lines);
    }

    public function test_phpmailer_builds_the_real_message(): void
    {
        $transport = new PhpMailerTransport('smtp', ['host' => 'smtp.example.com', 'port' => 465, 'username' => 'u', 'password' => 'p', 'encryption' => 'ssl']);
        $message = (new Mailer(new ArrayTransport(), ['hello@app.test', 'Spendwise']))->prepare(
            Message::to('ada@x.test', 'Ada Lovelace')->cc('bob@x.test')->bcc('audit@x.test')->replyTo('help@app.test')
                ->subject('Your receipt – Oct')->html('<p>Total: <b>R 120</b></p>')->attachData('a,b', 'receipt.csv', 'text/csv'),
        );

        $mail = $transport->build($message);
        $this->assertSame(['smtp.example.com', 465, 'ssl', true], [$mail->Host, $mail->Port, $mail->SMTPSecure, $mail->SMTPAuth]);

        $mail->preSend();
        $mime = $mail->getSentMIMEMessage();
        $this->assertStringContainsString('From: Spendwise <hello@app.test>', $mime);
        $this->assertStringContainsString('To: Ada Lovelace <ada@x.test>', $mime);
        $this->assertStringContainsString('Cc: bob@x.test', $mime);
        $this->assertStringNotContainsString('audit@x.test', $mime, 'Bcc is not a header');
        $this->assertStringContainsStringIgnoringCase('Subject: =?utf-8?', $mime, 'non-ASCII subject is encoded');
        $this->assertStringContainsString('multipart/alternative', $mime);
        $this->assertStringContainsString('Total: R 120', $mime, 'text part');
        $this->assertStringContainsString('filename=receipt.csv', $mime);
        $this->assertStringNotContainsString('PHPMailer', $mime);
    }
}
