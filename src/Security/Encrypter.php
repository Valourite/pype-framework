<?php

declare(strict_types=1);

namespace Pype\Security;

use JsonException;
use LogicException;

/**
 * Authenticated encryption with libsodium (XSalsa20-Poly1305), keyed by APP_KEY. For data your
 * app must read back: a user's OAuth tokens, an API key stored for them, a private cookie.
 * Passwords are never encrypted; they're hashed (Auth::hash).
 *
 *   $box = $encrypter->encrypt(['token' => $token, 'expires' => 1767225600]);
 *   $data = $encrypter->decrypt($box);   // DecryptException if it was changed or isn't ours
 *
 * Values travel as JSON (never unserialize(), so decrypting can't create objects). Every call
 * uses a fresh random nonce, so the same value encrypts differently each time. After changing
 * APP_KEY, list the old key in APP_PREVIOUS_KEYS so existing data still decrypts.
 */
final class Encrypter
{
    private const PREFIX = 'pype1.';

    /** @param list<string> $previousKeys raw 32-byte keys that may still decrypt (key rotation) */
    public function __construct(
        #[\SensitiveParameter] private readonly string $key,
        #[\SensitiveParameter] private readonly array $previousKeys = [],
    ) {
        foreach ([$key, ...$previousKeys] as $k) {
            if (strlen($k) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new LogicException('Encryption needs a 32-byte APP_KEY. Run: php bin/pype key:generate');
            }
        }
    }

    public function encrypt(mixed $value): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $nonce, $this->key);

        return self::PREFIX . sodium_bin2base64($nonce . $box, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /** @throws DecryptException when the value was changed, is malformed, or was made with another key */
    public function decrypt(string $payload): mixed
    {
        if (!str_starts_with($payload, self::PREFIX)) {
            throw new DecryptException('Not an encrypted value.');
        }

        try {
            $raw = sodium_base642bin(substr($payload, strlen(self::PREFIX)), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException) {
            throw new DecryptException('Not an encrypted value.');
        }

        if (strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new DecryptException('Not an encrypted value.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        foreach ([$this->key, ...$this->previousKeys] as $key) {
            $json = sodium_crypto_secretbox_open($box, $nonce, $key);
            if ($json !== false) {
                try {
                    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new DecryptException('The value could not be decoded.');
                }
            }
        }

        throw new DecryptException('The value was changed, or was encrypted with another key.');
    }
}
