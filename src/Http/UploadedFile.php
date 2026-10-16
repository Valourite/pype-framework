<?php

declare(strict_types=1);

namespace Pype\Http;

use RuntimeException;

/**
 * A file sent with a multipart form. Read with $request->file('receipt').
 *
 * Never trust what the browser says about the file: clientName() and clientType() are only
 * labels. mimeType() and extension() look at the actual bytes, and store() picks a random
 * name, so an upload can't choose where it lands or pretend to be something it isn't.
 *
 *   $path = $request->file('receipt')->store($app->path('storage/receipts'));
 */
final class UploadedFile
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'image/avif' => 'avif', 'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/csv' => 'csv',
        'application/zip' => 'zip', 'application/json' => 'json',
    ];

    private ?string $mime = null;

    private bool $moved = false;

    public function __construct(
        private readonly string $path,
        private readonly string $clientName,
        private readonly string $clientType = '',
        private readonly int $size = 0,
        private readonly int $error = UPLOAD_ERR_OK,
        // Not from PHP's own upload handling (a test fake, or a long-running server like
        // OpenSwoole that writes its own temp files): skip is_uploaded_file(), move with rename().
        private readonly bool $trusted = false,
    ) {
    }

    /** A file on disk dressed up as an upload, for tests. The original is copied, never moved. */
    public static function fake(string $path, ?string $clientName = null): self
    {
        $copy = tempnam(sys_get_temp_dir(), 'pype');
        copy($path, $copy);

        return new self($copy, $clientName ?? basename($path), '', (int) filesize($copy), UPLOAD_ERR_OK, trusted: true);
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && !$this->moved && ($this->trusted || is_uploaded_file($this->path));
    }

    public function error(): int
    {
        return $this->error;
    }

    public function errorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_OK => $this->moved ? 'The file was already stored.' : 'The file is not a valid upload.',
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than the server allows (' . ini_get('upload_max_filesize') . ').',
            UPLOAD_ERR_PARTIAL => 'The file was only partly uploaded. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            default => 'The file could not be uploaded.',
        };
    }

    /** The name the user's computer gave it. Display only; never use it as a path. */
    public function clientName(): string
    {
        return $this->clientName;
    }

    /** The type the browser claimed. Use mimeType() for decisions. */
    public function clientType(): string
    {
        return $this->clientType;
    }

    /** Size in bytes. */
    public function size(): int
    {
        return $this->size;
    }

    /** The real type, detected from the file's contents. */
    public function mimeType(): string
    {
        return $this->mime ??= (string) (new \finfo(FILEINFO_MIME_TYPE))->file($this->path);
    }

    /** Extension for the real type ('pdf', 'jpg', ...), or 'bin' when unknown. */
    public function extension(): string
    {
        return self::EXTENSIONS[$this->mimeType()] ?? 'bin';
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Move the file into $directory under a random name with the real extension.
     * Returns the stored file name (relative to $directory).
     */
    public function store(string $directory, ?string $name = null): string
    {
        if (!$this->isValid()) {
            throw new RuntimeException($this->errorMessage());
        }

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create directory {$directory}.");
        }

        $name ??= bin2hex(random_bytes(16)) . '.' . $this->extension();
        $target = rtrim($directory, '/') . '/' . basename($name);
        $this->mimeType();   // detect before the temp file goes away

        if (!($this->trusted ? $this->moveTrusted($target) : move_uploaded_file($this->path, $target))) {
            throw new RuntimeException('Could not store the uploaded file.');
        }

        $this->moved = true;

        return basename($target);
    }

    /** rename(), or copy + delete when the temp dir is on another filesystem. */
    private function moveTrusted(string $target): bool
    {
        if (@rename($this->path, $target)) {
            return true;
        }

        return copy($this->path, $target) && (@unlink($this->path) || true);
    }
}
