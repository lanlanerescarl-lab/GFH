<?php

declare(strict_types=1);

namespace Gfh\Core\Security;

use Gfh\Core\Config\Config;

/**
 * Encrypts small secrets (organization connection strings) with libsodium's secretbox.
 * Stored layout: 1 version byte, 24 nonce bytes, then the encrypted and authenticated text.
 * The key comes from APP_KEY in .env ("base64:" followed by 32 random bytes, base64 encoded).
 */
final class SecretBox
{
    private const VERSION = "\x01";

    private function __construct(private readonly string $key)
    {
    }

    public static function fromConfig(Config $config): self
    {
        return self::fromEncodedKey($config->required('APP_KEY'));
    }

    public static function fromEncodedKey(string $encoded): self
    {
        if (!extension_loaded('sodium')) {
            throw new SecretBoxException('The sodium PHP extension is not enabled.');
        }

        if (!str_starts_with($encoded, 'base64:')) {
            throw new SecretBoxException('APP_KEY must start with "base64:". See .env.example for how to generate one.');
        }

        $key = base64_decode(substr($encoded, 7), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new SecretBoxException('APP_KEY must hold exactly 32 random bytes, base64 encoded.');
        }

        return new self($key);
    }

    public function encrypt(string $plainText): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::VERSION . $nonce . sodium_crypto_secretbox($plainText, $nonce, $this->key);
    }

    public function decrypt(string $payload): string
    {
        $minimum = 1 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        if (strlen($payload) < $minimum || $payload[0] !== self::VERSION) {
            throw new SecretBoxException('The stored secret is not in a format this version understands.');
        }

        $nonce = substr($payload, 1, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipherText = substr($payload, 1 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plainText = sodium_crypto_secretbox_open($cipherText, $nonce, $this->key);

        if ($plainText === false) {
            throw new SecretBoxException('The stored secret could not be decrypted. Is APP_KEY the key it was encrypted with?');
        }

        return $plainText;
    }
}
