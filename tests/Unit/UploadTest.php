<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Http\UploadedFile;
use Pype\Testing\TestClient;
use Pype\Validation\Validate;
use Pype\Validation\ValidatedInput;

final class UploadTest extends TestCase
{
    private const FILES = __DIR__ . '/../fixtures/files';

    private string $storage;
    private TestClient $client;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/pype-upload-' . bin2hex(random_bytes(4));
        $app = new Application(__DIR__);

        $app->router->post('/receipts', [
            new Validate(['receipt' => 'required|file|mimes:pdf,jpg,png|max:10', 'note' => 'nullable|string']),
            fn (ValidatedInput $in) => ['stored' => $in->get('receipt')->store($this->storage), 'type' => $in->get('receipt')->mimeType(), 'note' => $in->get('note')],
        ]);
        $app->router->post('/avatar', [new Validate(['avatar' => 'required|image']), fn () => ['ok' => true]]);
        $app->router->get('/receipts/{name}', fn (string $name) => Response::file($this->storage . '/' . basename($name), 'My receipt.pdf'));

        $this->client = new TestClient($app);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->storage . '/*') ?: []);
        @rmdir($this->storage);
    }

    public function test_upload_validate_store_and_serve(): void
    {
        $res = $this->client->post('/receipts', ['note' => 'lunch'], [], ['receipt' => UploadedFile::fake(self::FILES . '/receipt.pdf', 'Lunch "receipt".pdf')]);

        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame('application/pdf', $res->json('data.type'));
        $this->assertSame('lunch', $res->json('data.note'));
        $stored = $res->json('data.stored');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', $stored);   // random name, real extension
        $this->assertFileExists($this->storage . '/' . $stored);
        $this->assertFileExists(self::FILES . '/receipt.pdf');                     // fake() copied, didn't move

        $file = $this->client->get('/receipts/' . $stored);
        $this->assertSame('application/pdf', $file->header('Content-Type'));
        $this->assertSame('inline; filename="My receipt.pdf"', $file->header('Content-Disposition'));
        $this->assertSame('nosniff', $file->header('X-Content-Type-Options'));
        $this->assertStringStartsWith('%PDF', $file->body());

        $this->assertSame(404, $this->client->get('/receipts/nope.pdf')->status());
    }

    public function test_type_is_checked_from_contents_not_name(): void
    {
        $evil = $this->client->post('/receipts', [], [], ['receipt' => UploadedFile::fake(self::FILES . '/evil.png')]);
        $this->assertSame(422, $evil->status());
        $this->assertSame(['The receipt must be a file of type: pdf, jpg, png.'], $evil->json('error.errors.receipt'));

        $this->assertSame(422, $this->client->post('/avatar', [], [], ['avatar' => UploadedFile::fake(self::FILES . '/receipt.pdf')])->status());
        $this->assertSame(200, $this->client->post('/avatar', [], [], ['avatar' => UploadedFile::fake(self::FILES . '/pixel.png')])->status());
    }

    public function test_size_limit_missing_file_and_failed_upload(): void
    {
        $big = tempnam(sys_get_temp_dir(), 'big');
        file_put_contents($big, '%PDF-1.4' . str_repeat('x', 11 * 1024));
        $res = $this->client->post('/receipts', [], [], ['receipt' => UploadedFile::fake($big)]);
        unlink($big);
        $this->assertSame(['The receipt may not be larger than 10 KB.'], $res->json('error.errors.receipt'));

        $this->assertSame(['The receipt field is required.'], $this->client->post('/receipts', ['note' => 'x'])->json('error.errors.receipt'));

        $failed = new UploadedFile('/nonexistent', 'a.pdf', 'application/pdf', 0, UPLOAD_ERR_INI_SIZE);
        $res = $this->client->post('/receipts', [], [], ['receipt' => $failed]);
        $this->assertStringStartsWith('The receipt upload failed: The file is larger than the server allows', $res->json('error.errors.receipt.0'));
    }

    public function test_real_uploads_must_come_from_php(): void
    {
        // Not created by PHP's upload handling (e.g. a path smuggled into $_FILES): rejected.
        $this->assertFalse((new UploadedFile(self::FILES . '/receipt.pdf', 'r.pdf'))->isValid());
    }

    public function test_files_from_globals_are_normalised(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/'];
        $_FILES = [
            'one' => ['name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/a', 'error' => UPLOAD_ERR_OK, 'size' => 10],
            'empty' => ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0],
            'many' => ['name' => ['b.png', ''], 'type' => ['image/png', ''], 'tmp_name' => ['/tmp/b', ''], 'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE], 'size' => [5, 0]],
        ];

        $request = Request::fromGlobals();
        $_FILES = [];

        $this->assertSame('a.pdf', $request->file('one')->clientName());
        $this->assertNull($request->file('empty'));
        $this->assertCount(1, $request->file('many'));
        $this->assertSame('b.png', $request->file('many')[0]->clientName());
    }
}
