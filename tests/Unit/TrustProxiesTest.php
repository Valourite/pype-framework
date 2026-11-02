<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Database\DatabaseProvider;
use Pype\Http\Request;
use Pype\Server\SwooleServer;
use Pype\Stages\Throttle;
use Pype\Stages\TrustProxies;
use Pype\Testing\TestClient;

final class TrustProxiesTest extends TestCase
{
    /** An app whose /who route answers with what the request says about its client. */
    private static function app(object $stage, array $config = []): Application
    {
        $app = new Application(__DIR__, $config);
        $app->through($stage);
        $app->router->get('/who', fn (Request $r) => [
            'ip' => $r->ip(), 'remote' => $r->remoteAddr(), 'scheme' => $r->scheme(), 'host' => $r->host(), 'secure' => $r->isSecure(),
        ]);

        return $app;
    }

    /** What /who answers for a request from $remote with $headers. */
    private static function who(object $stage, string $remote, array $headers = []): array
    {
        return (new TestClient(self::app($stage)))->withIp($remote)->get('/who', $headers)->json('data');
    }

    public function test_an_untrusted_peer_keeps_its_own_address_and_headers_are_ignored(): void
    {
        $who = self::who(TrustProxies::with(['10.0.0.1']), '203.0.113.5', [
            'X-Forwarded-For' => '1.1.1.1', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.test', 'Host' => 'app.test',
        ]);

        $this->assertSame(['ip' => '203.0.113.5', 'remote' => '203.0.113.5', 'scheme' => 'http', 'host' => 'app.test', 'secure' => false], $who);
    }

    public function test_an_empty_list_trusts_nobody(): void
    {
        $this->assertSame('10.0.0.1', self::who(new TrustProxies([]), '10.0.0.1', ['X-Forwarded-For' => '1.1.1.1'])['ip']);
        $this->assertSame('10.0.0.1', self::who(new TrustProxies(''), '10.0.0.1', ['X-Forwarded-For' => '1.1.1.1'])['ip']);
    }

    public function test_a_trusted_proxy_forwards_ip_scheme_and_host(): void
    {
        $who = self::who(new TrustProxies(['10.0.0.1']), '10.0.0.1', [
            'X-Forwarded-For' => '198.51.100.7', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'Shop.Example.com:8443', 'Host' => 'internal:8000',
        ]);

        $this->assertSame(['ip' => '198.51.100.7', 'remote' => '10.0.0.1', 'scheme' => 'https', 'host' => 'shop.example.com', 'secure' => true], $who);
    }

    public function test_ipv4_cidr_ranges(): void
    {
        $stage = new TrustProxies('10.0.0.0/8, 192.168.1.0/24, 172.16.0.0/12');
        $headers = ['X-Forwarded-For' => '198.51.100.7'];

        foreach (['10.255.3.4', '192.168.1.200', '172.31.255.255'] as $proxy) {
            $this->assertSame('198.51.100.7', self::who($stage, $proxy, $headers)['ip'], $proxy);
        }
        foreach (['11.0.0.1', '192.168.2.1', '172.32.0.1'] as $outside) {
            $this->assertSame($outside, self::who($stage, $outside, $headers)['ip'], $outside);
        }
    }

    public function test_ipv6_addresses_and_ranges(): void
    {
        $stage = new TrustProxies(['2001:db8:1::/48', '::1']);

        $this->assertSame('2001:db8:ffff::9', self::who($stage, '2001:db8:1:abcd::5', ['X-Forwarded-For' => '2001:DB8:FFFF:0:0:0:0:9'])['ip'], 'canonical form');
        $this->assertSame('203.0.113.1', self::who($stage, '::1', ['X-Forwarded-For' => '203.0.113.1'])['ip']);
        $this->assertSame('2001:db8:2::5', self::who($stage, '2001:db8:2::5', ['X-Forwarded-For' => '203.0.113.1'])['ip'], 'outside the /48');
        // An IPv4 proxy never matches an IPv6 range, and the other way round.
        $this->assertSame('10.0.0.1', self::who(new TrustProxies(['::/0']), '10.0.0.1', ['X-Forwarded-For' => '1.1.1.1'])['ip']);
        $this->assertSame('::1', self::who(new TrustProxies(['0.0.0.0/0']), '::1', ['X-Forwarded-For' => '1.1.1.1'])['ip']);
    }

    public function test_forwarded_for_is_read_right_to_left_so_a_spoofed_first_entry_is_ignored(): void
    {
        $stage = new TrustProxies(['10.0.0.0/8']);

        // The client sent "X-Forwarded-For: 6.6.6.6"; the edge proxy appended the real peer, an inner proxy its own.
        $this->assertSame('203.0.113.9', self::who($stage, '10.0.0.1', ['X-Forwarded-For' => '6.6.6.6, 203.0.113.9, 10.0.0.2'])['ip']);
        // Every hop is a trusted proxy: the leftmost is the best answer.
        $this->assertSame('10.0.0.3', self::who($stage, '10.0.0.1', ['X-Forwarded-For' => '10.0.0.3, 10.0.0.2'])['ip']);
        // No header at all: the proxy itself.
        $this->assertSame('10.0.0.1', self::who($stage, '10.0.0.1')['ip']);
    }

    public function test_a_malformed_hop_stops_the_walk_at_the_last_good_address(): void
    {
        $stage = new TrustProxies(['10.0.0.0/8']);

        $this->assertSame('10.0.0.2', self::who($stage, '10.0.0.1', ['X-Forwarded-For' => '203.0.113.9, unknown, 10.0.0.2'])['ip']);
        $this->assertSame('10.0.0.1', self::who($stage, '10.0.0.1', ['X-Forwarded-For' => '<script>'])['ip']);
        $this->assertSame('10.0.0.1', self::who($stage, '10.0.0.1', ['X-Forwarded-For' => ''])['ip']);
    }

    public function test_hops_with_ports_and_brackets(): void
    {
        $stage = new TrustProxies(['10.0.0.1']);

        $this->assertSame('203.0.113.9', self::who($stage, '10.0.0.1', ['X-Forwarded-For' => '203.0.113.9:51234'])['ip']);
        $this->assertSame('2001:db8::1', self::who($stage, '10.0.0.1', ['X-Forwarded-For' => '[2001:db8::1]:443'])['ip']);
    }

    public function test_star_trusts_the_connecting_peer_but_not_the_listed_hops(): void
    {
        $stage = new TrustProxies('*');

        $this->assertSame('203.0.113.9', self::who($stage, '34.1.2.3', ['X-Forwarded-For' => '6.6.6.6, 203.0.113.9'])['ip']);
        $this->assertSame('https', self::who($stage, '34.1.2.3', ['X-Forwarded-Proto' => 'https'])['scheme']);
    }

    public function test_proto_and_host_take_the_nearest_proxys_value_and_reject_junk(): void
    {
        $stage = new TrustProxies(['10.0.0.1']);
        $who = fn (array $h) => self::who($stage, '10.0.0.1', $h + ['Host' => 'app.test']);

        $this->assertSame('http', $who(['X-Forwarded-Proto' => 'https, http'])['scheme'], 'the client-sent first value loses');
        $this->assertSame('http', $who(['X-Forwarded-Proto' => 'javascript'])['scheme']);
        $this->assertSame('real.test', $who(['X-Forwarded-Host' => 'evil.test, real.test'])['host']);
        $this->assertSame('app.test', $who(['X-Forwarded-Host' => 'evil.test/path'])['host'], 'invalid: the Host header stays');
        $this->assertSame('app.test', $who(['X-Forwarded-Host' => 'a b'])['host']);
        $this->assertSame('[::1]', $who(['X-Forwarded-Host' => '[::1]:8080'])['host']);
    }

    public function test_the_list_comes_from_config_when_not_given(): void
    {
        $app = self::app(new TrustProxies(), ['app' => ['trusted_proxies' => '10.0.0.0/8,  ::1']]);
        $client = new TestClient($app);

        $this->assertSame('198.51.100.7', $client->withIp('10.9.9.9')->get('/who', ['X-Forwarded-For' => '198.51.100.7'])->json('data.ip'));
        $this->assertSame('198.51.100.8', $client->withIp('::1')->get('/who', ['X-Forwarded-For' => '198.51.100.8'])->json('data.ip'));
        $this->assertSame('11.0.0.1', $client->withIp('11.0.0.1')->get('/who', ['X-Forwarded-For' => '198.51.100.7'])->json('data.ip'));
    }

    public function test_invalid_entries_fail_loudly(): void
    {
        foreach (['10.0.0.300', '10.0.0.0/33', '::1/129', 'proxy.internal', '10.0.0.0/x'] as $bad) {
            try {
                new TrustProxies([$bad]);
                $this->fail("{$bad} was accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($bad, $e->getMessage());
            }
        }
    }

    public function test_throttle_counts_each_forwarded_client_separately(): void
    {
        $app = new Application(__DIR__, ['database' => ['rate_limits' => ['driver' => 'array']], 'app' => ['trusted_proxies' => ['10.0.0.1']]]);
        $app->register(DatabaseProvider::class);
        $app->through(new TrustProxies());
        $app->router->get('/x', [Throttle::with(maxAttempts: 1), fn () => 'ok']);
        $proxy = (new TestClient($app))->withIp('10.0.0.1');

        $this->assertSame(200, $proxy->get('/x', ['X-Forwarded-For' => '198.51.100.1'])->status());
        $this->assertSame(429, $proxy->get('/x', ['X-Forwarded-For' => '198.51.100.1'])->status());
        $this->assertSame(200, $proxy->get('/x', ['X-Forwarded-For' => '198.51.100.2'])->status(), 'another client behind the same proxy');
        // Without the proxy's trust, the spoofed header changes nothing: one counter for the peer.
        $direct = (new TestClient($app))->withIp('203.0.113.5');
        $this->assertSame(200, $direct->get('/x', ['X-Forwarded-For' => '198.51.100.3'])->status());
        $this->assertSame(429, $direct->get('/x', ['X-Forwarded-For' => '198.51.100.4'])->status());
    }

    public function test_requests_from_openswoole_resolve_the_same_way_and_do_not_leak_into_the_next(): void
    {
        $app = self::app(new TrustProxies(['10.0.0.1']));
        $server = new SwooleServer($app);
        $req = static fn (string $remote, array $headers) => new class (['request_method' => 'GET', 'request_uri' => '/who', 'remote_addr' => $remote], $headers) {
            public array $get = [];
            public ?array $post = null;
            public array $cookie = [];
            public array $files = [];

            public function __construct(public array $server, public array $header)
            {
            }

            public function rawContent(): string
            {
                return '';
            }
        };

        $forwarded = $app->handle($server->toRequest($req('10.0.0.1', ['x-forwarded-for' => '198.51.100.7', 'x-forwarded-proto' => 'https'])));
        $direct = $app->handle($server->toRequest($req('203.0.113.5', ['x-forwarded-for' => '198.51.100.7'])));

        $this->assertSame(['198.51.100.7', 'https'], [$forwarded->data()['ip'], $forwarded->data()['scheme']]);
        $this->assertSame(['203.0.113.5', 'http'], [$direct->data()['ip'], $direct->data()['scheme']], 'the same worker, the next request');
    }

    public function test_request_knows_https_from_the_uri_and_server(): void
    {
        $this->assertTrue(Request::create('GET', 'https://app.test/x')->isSecure());
        $this->assertFalse(Request::create('GET', '/x')->isSecure());
        $this->assertSame('app.test', Request::create('GET', '/x', headers: ['Host' => 'APP.test:8000'])->host());
        $this->assertSame('', Request::create('GET', '/x')->host());
        // withInput() keeps it.
        $this->assertTrue(Request::create('GET', 'https://app.test/x')->withInput(['a' => 1])->isSecure());
    }
}
