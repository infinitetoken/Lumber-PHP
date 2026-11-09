<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber\Internal;

/**
 * AES-256-GCM, as every Lumber plugin encrypts a log.
 *
 * @internal
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    /**
     * AES-256-GCM in Lumber's layout: the 16-byte auth tag, then the ciphertext. OpenSSL hands back the tag on its own.
     */
    public static function seal(#[\SensitiveParameter] string $key, string $plaintext, string $nonce): string
    {
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, \OPENSSL_RAW_DATA, $nonce, $tag, '', Contract::TAG_BYTES);
        if ($ciphertext === false) {
            throw new \RuntimeException('AES-256-GCM encryption failed: ' . (openssl_error_string() ?: 'unknown error'));
        }

        return $tag . $ciphertext;
    }

    /**
     * A payload as POST /logs' `encrypted`, under a fresh random nonce. Never reuse a nonce with the same key.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{nonce: string, payload: string, keyVersion: int}
     */
    public static function encrypt(#[\SensitiveParameter] string $key, array $payload): array
    {
        $nonce = random_bytes(Contract::NONCE_BYTES);

        return [
            'nonce' => base64_encode($nonce),
            'payload' => base64_encode(self::seal($key, Json::encode($payload), $nonce)),
            'keyVersion' => Contract::KEY_VERSION,
        ];
    }
}
