<?php

declare(strict_types=1);

namespace Pype\Session;

/**
 * Server-side data for one browser, keyed by a random id in a cookie. StartSession puts it
 * in the context; inject `Session $session` in any later stage or handler.
 *
 *   $session->put('cart', [...]);          $session->get('cart');
 *   $session->flash('status', 'Saved!');   // available on the next request only
 *   $session->regenerate();                // new id, same data (do this on login)
 *   $session->invalidate();                // new id, no data (do this on logout)
 */
final class Session
{
    private const FLASH_NEW = '_flash.new';
    private const FLASH_OLD = '_flash.old';
    private const TOKEN = '_token';

    /** When the store last saved this session (unix time). Kept by StartSession, hidden from all(). */
    public const WRITTEN = '_written';

    /** @var list<string> ids replaced during this request, to delete from the store */
    private array $replaced = [];

    /** @var list<\Pype\Http\Cookie> extra cookies for this response (not stored) */
    private array $cookies = [];

    /** @param array<string, mixed> $data */
    public function __construct(
        private string $id,
        private array $data = [],
        private readonly bool $isNew = true,
    ) {
    }

    public static function generateId(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Ids look like generateId() output; anything else from a cookie is ignored. */
    public static function isValidId(string $id): bool
    {
        return strlen($id) === 64 && ctype_xdigit($id);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }

    /** @return list<string> */
    public function replacedIds(): array
    {
        return $this->replaced;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $data = $this->data;
        unset($data[self::WRITTEN]);

        return $data;
    }

    public function put(string $key, mixed $value): self
    {
        $this->data[$key] = $value;

        return $this;
    }

    public function forget(string $key): self
    {
        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
        }

        return $this;
    }

    /** Get a value and remove it. */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    /** Store a value for this request and the next one only (status messages, form errors). */
    public function flash(string $key, mixed $value): self
    {
        $this->put($key, $value);
        $this->data[self::FLASH_NEW] = array_values(array_unique([...($this->data[self::FLASH_NEW] ?? []), $key]));
        $this->data[self::FLASH_OLD] = array_values(array_diff($this->data[self::FLASH_OLD] ?? [], [$key]));

        return $this;
    }

    /**
     * The data to save at the end of this request. Values flashed on the previous request
     * are dropped and values flashed on this one survive one more request. The session
     * itself is left alone, because views render after it is saved and still need them.
     *
     * @return array<string, mixed>
     */
    public function dataToStore(): array
    {
        $data = $this->data;
        $new = $data[self::FLASH_NEW] ?? [];

        foreach ($data[self::FLASH_OLD] ?? [] as $key) {
            unset($data[$key]);
        }

        unset($data[self::FLASH_NEW], $data[self::FLASH_OLD]);

        if ($new !== []) {
            $data[self::FLASH_OLD] = $new;
        }

        return $data;
    }

    /** Give the session a new id, keeping its data. Prevents session fixation on login. */
    public function regenerate(): self
    {
        $this->replaced[] = $this->id;
        $this->id = self::generateId();

        return $this;
    }

    /** Drop all data and get a new id (and so a new CSRF token). */
    public function invalidate(): self
    {
        $this->data = [];
        $this->regenerate();
        $this->token();

        return $this;
    }

    /** The CSRF token for this session, created on first use. */
    public function token(): string
    {
        if (!is_string($this->data[self::TOKEN] ?? null)) {
            $this->put(self::TOKEN, bin2hex(random_bytes(32)));
        }

        return $this->data[self::TOKEN];
    }

    /** Send a cookie with this response (StartSession adds it). Used by the "remember me" login. */
    public function queueCookie(\Pype\Http\Cookie $cookie): self
    {
        $this->cookies[$cookie->name] = $cookie;

        return $this;
    }

    /** @return list<\Pype\Http\Cookie> */
    public function queuedCookies(): array
    {
        return array_values($this->cookies);
    }

    public function regenerateToken(): self
    {
        return $this->put(self::TOKEN, bin2hex(random_bytes(32)));
    }
}
