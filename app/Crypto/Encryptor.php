<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Crypto;

use RuntimeException;

use function Hyperf\Support\env;

/**
 * AES-256-GCM 字段级加密，用于 merchants.app_secret、suppliers.config、
 * order_recharges.card_no/card_pwd、merchant_qualifications.id_card_no 等加密字段。
 *
 * 密文格式：base64(nonce . ciphertext . tag)，见 database-design.md 5.3。
 */
class Encryptor
{
    private const CIPHER = 'aes-256-gcm';

    private const KEY_LENGTH = 32;

    private const NONCE_LENGTH = 12;

    private const TAG_LENGTH = 16;

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return base64_encode($nonce . $ciphertext . $tag);
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        $minLength = self::NONCE_LENGTH + self::TAG_LENGTH;
        if ($raw === false || strlen($raw) < $minLength) {
            throw new RuntimeException('Invalid ciphertext.');
        }

        $nonce = substr($raw, 0, self::NONCE_LENGTH);
        $tag = substr($raw, -self::TAG_LENGTH);
        $ciphertext = substr($raw, self::NONCE_LENGTH, -self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed: ciphertext missing, corrupted, or tampered with.');
        }

        return $plaintext;
    }

    private function key(): string
    {
        $encoded = env('APP_ENCRYPTION_KEY');
        if (! is_string($encoded) || $encoded === '') {
            throw new RuntimeException('APP_ENCRYPTION_KEY is not configured.');
        }

        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== self::KEY_LENGTH) {
            throw new RuntimeException('APP_ENCRYPTION_KEY must be a base64-encoded 32-byte key.');
        }

        return $key;
    }
}
