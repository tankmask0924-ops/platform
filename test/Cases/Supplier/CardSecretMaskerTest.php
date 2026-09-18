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

namespace HyperfTest\Cases\Supplier;

use App\Supplier\CardSecretMasker;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class CardSecretMaskerTest extends TestCase
{
    public function testMasksNestedKeysAndJsonStringBodies()
    {
        $raw = [
            'http_status' => 200,
            // 驱动快照里的原始响应体是 JSON 字符串
            'body' => json_encode(['code' => 200, 'data' => ['status' => 3, 'card_list' => [['card_no' => '8800123', 'card_password' => 'PWD-中文']]]], JSON_UNESCAPED_UNICODE),
            'card_pwd' => 'plain',
            'empty' => ['card_no' => ''],
        ];

        $masked = CardSecretMasker::mask($raw);

        $this->assertSame(200, $masked['http_status']);
        $this->assertSame('******', $masked['card_pwd']);
        $this->assertSame('', $masked['empty']['card_no']);
        $body = json_decode($masked['body'], true);
        $this->assertSame(['card_no' => '******', 'card_password' => '******'], $body['data']['card_list'][0]);
        $this->assertSame(3, $body['data']['status']);
    }

    public function testLeavesUnrelatedValuesUntouched()
    {
        $this->assertSame('not json {', CardSecretMasker::mask('not json {'));
        $this->assertSame('{"code":200}', CardSecretMasker::mask('{"code":200}'));
        $this->assertNull(CardSecretMasker::mask(null));
        $this->assertSame(['a' => 1], CardSecretMasker::mask(['a' => 1]));
    }
}
