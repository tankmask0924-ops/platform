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

namespace HyperfTest\Cases\Crypto;

use App\Crypto\Encryptor;
use Hyperf\Testing\TestCase;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class EncryptorTest extends TestCase
{
    public function testEncryptDecryptRoundTrip()
    {
        $encryptor = new Encryptor();
        $plaintext = 'super-secret-app-secret';

        $ciphertext = $encryptor->encrypt($plaintext);

        $this->assertNotSame($plaintext, $ciphertext);
        $this->assertSame($plaintext, $encryptor->decrypt($ciphertext));
    }

    public function testCiphertextIsNotDeterministic()
    {
        $encryptor = new Encryptor();
        $plaintext = 'same-input';

        $first = $encryptor->encrypt($plaintext);
        $second = $encryptor->encrypt($plaintext);

        // 每次加密用随机 nonce，同样的明文两次加密结果不同
        $this->assertNotSame($first, $second);
        $this->assertSame($plaintext, $encryptor->decrypt($first));
        $this->assertSame($plaintext, $encryptor->decrypt($second));
    }

    public function testTamperedCiphertextFailsToDecrypt()
    {
        $encryptor = new Encryptor();
        $ciphertext = $encryptor->encrypt('card-secret-1234');

        $raw = base64_decode($ciphertext, true);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === "\x00" ? "\x01" : "\x00";
        $tampered = base64_encode($raw);

        $this->expectException(RuntimeException::class);
        $encryptor->decrypt($tampered);
    }

    public function testInvalidBase64FailsToDecrypt()
    {
        $encryptor = new Encryptor();

        $this->expectException(RuntimeException::class);
        $encryptor->decrypt('not-valid-base64-!!!');
    }
}
