<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Mail\ArrayTransport;
use Pype\Mail\Mailbox;
use Pype\Mail\MailboxTransport;
use Pype\Mail\MailboxViewer;
use Pype\Mail\Mailer;
use Pype\Mail\MailProvider;
use Pype\Mail\Message;
use Pype\Mail\Transport;
use Pype\Routing\Urls;
use Pype\Http\Request;
use Pype\Testing\TestClient;

/** The dev mailbox: copies of sent mail at /_pype/mail, on with APP_DEBUG. */
final class MailboxTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/pype-mailbox-' . bin2hex(random_bytes(4));
        mkdir($this->base);
        CapturingTransport::$sent = 0;
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    private function app(array $config): Application
    {
        $app = new Application($this->base, $config);
        $app->register(MailProvider::class);

        return $app;
    }

    private static function message(string $subject = 'Your quote Q-0001'): Message
    {
        return (new Message())->from('shop@example.com', 'Shop')->addTo('ada@example.com', 'Ada')->subject($subject)
            ->html('<p>View it: <a href="https://app.test/q/1?signature=abc&amp;expires=9">open</a></p>')
            ->text('View it (https://app.test/q/1?signature=abc&expires=9).');
    }

    public function test_in_debug_sent_mail_is_delivered_and_kept_with_its_links(): void
    {
        $app = $this->app(['app' => ['debug' => true], 'mail' => ['driver' => CapturingTransport::class]]);
        $this->assertInstanceOf(MailboxTransport::class, $app->container->get(Transport::class));

        $app->container->get(Mailer::class)->send(self::message());
        $this->assertSame(1, CapturingTransport::$sent, 'still delivered by the real transport');

        $client = new TestClient($app);
        $list = $client->get('/_pype/mail')->assertOk()->assertSee('Your quote Q-0001')->assertSee('Ada &lt;ada@example.com&gt;');
        $this->assertSame('noindex', $list->header('X-Robots-Tag'));

        preg_match('/\?id=([0-9a-f-]+)/', $list->body(), $m);
        $client->get('/_pype/mail?id=' . $m[1])
            ->assertSee('href="https://app.test/q/1?signature=abc&amp;expires=9"')   // the signed link, clickable
            ->assertSee('<iframe sandbox')
            ->assertDontSee('nothing here was delivered');

        $client->get('/_pype/mail?id=20000101-000000-000000-abcdef')->assertSee('That message is gone');
    }

    public function test_the_log_driver_gets_a_warning(): void
    {
        $viewer = new MailboxViewer(new Mailbox($this->base . '/mail'), 'log');
        $app = $this->app(['app' => ['debug' => true], 'mail' => ['driver' => CapturingTransport::class]]);

        $html = (string) $viewer->page(Request::create('GET', '/_pype/mail'), $app->container->get(Urls::class))->data();
        $this->assertStringContainsString('MAIL_DRIVER is <strong>log</strong>: nothing here was delivered', $html);
    }

    public function test_off_outside_debug_and_for_test_drivers(): void
    {
        $prod = $this->app(['app' => ['debug' => false], 'mail' => ['driver' => CapturingTransport::class]]);
        $this->assertInstanceOf(CapturingTransport::class, $prod->container->get(Transport::class));
        $this->assertNull($prod->router->named('pype.mail'));

        $tests = $this->app(['app' => ['debug' => true], 'mail' => ['driver' => 'array']]);
        $this->assertInstanceOf(ArrayTransport::class, $tests->container->get(Transport::class));
        $this->assertNull($tests->router->named('pype.mail'));

        $guarded = $this->app(['app' => ['debug' => false], 'mail' => ['driver' => CapturingTransport::class, 'mailbox' => ['enabled' => true, 'stages' => [fn () => 'no']]]]);
        $this->assertNotNull($guarded->router->named('pype.mail'));
    }

    public function test_only_the_newest_are_kept(): void
    {
        $mailbox = new Mailbox($this->base . '/mail', keep: 2);
        foreach (['one', 'two', 'three'] as $subject) {
            $mailbox->store(self::message($subject), 'smtp');
        }

        $this->assertSame(['three', 'two'], array_column($mailbox->all(), 'subject'));
        $this->assertSame(['https://app.test/q/1?signature=abc&expires=9'], $mailbox->all()[0]['links']);
        $this->assertNull($mailbox->find('../../etc/passwd'));

        $mailbox->clear();
        $this->assertSame([], $mailbox->all());
    }
}

final class CapturingTransport implements Transport
{
    public static int $sent = 0;

    public function send(Message $message): void
    {
        self::$sent++;
    }
}
