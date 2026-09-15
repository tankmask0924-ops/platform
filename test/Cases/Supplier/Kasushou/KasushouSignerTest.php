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

namespace HyperfTest\Cases\Supplier\Kasushou;

use App\Supplier\Kasushou\KasushouSigner;
use Hyperf\Testing\TestCase;

/**
 * KasushouSigner 覆盖 kasushou.md 第 1 节"签名"行的两套算法。没有真实卡速售账号/
 * 测试环境（kasushou.md 第 5 节："域名等信息待实际配置供应商时录入"），所以这里
 * 用两种互补的方式验证，而不是依赖官方报文样例：.
 *
 * 1. 自洽性（self-consistency）：测试自己按文档公式独立拼字符串、调用 PHP 原生
 *    sha1()，跟 KasushouSigner 的输出比对——注意这里独立拼接的逻辑不调用
 *    KasushouSigner 的任何内部方法，纯粹是测试自己重新按公式实现一遍。
 * 2. 固定的手算向量：字面量输入 + 字面量期望 sha1 值（下面注释写了推导过程），
 *    用 `docker exec pf php -r '...'` 单独跑一遍确认了这个字面量哈希值确实正确
 *    （跟 KasushouSigner 的实现完全无关地独立验证），而不是让测试从 KasushouSigner
 *    的输出反推期望值。
 *
 * @internal
 * @coversNothing
 */
class KasushouSignerTest extends TestCase
{
    private const API_KEY = 'secret';

    public function testSignRequestMatchesHandDerivedFixedVector()
    {
        // 手算推导：
        // Timestamp = "1700000000000"
        // 请求体 = ["a" => "1"]，只有一个顶层键，排序不影响顺序
        // JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE 编码 -> {"a":"1"}
        // 拼接 = "1700000000000" . '{"a":"1"}' . "secret"
        //      = '1700000000000{"a":"1"}secret'
        // sha1(...) 用 `docker exec pf php -r 'echo sha1("1700000000000".json_encode(["a"=>"1"]).\"secret\");'`
        // 独立验证得到 ca795ad3e2b6cb75a88ec5a5bfe74282ad150cad
        $signer = new KasushouSigner();

        $sign = $signer->signRequest(['a' => '1'], self::API_KEY, '1700000000000');

        $this->assertSame('ca795ad3e2b6cb75a88ec5a5bfe74282ad150cad', $sign);
    }

    public function testSignRequestEmptyBodyEncodesAsEmptyJsonObject()
    {
        // 手算推导：空请求体按文档要求编码成 "{}"（不是 PHP json_encode([]) 默认的 "[]"）。
        // 拼接 = "1700000000000" . "{}" . "secret"，独立验证 sha1 = cf567713be09b765d18bd9ffb976c3f7fc600fd4
        $signer = new KasushouSigner();

        $sign = $signer->signRequest([], self::API_KEY, '1700000000000');

        $this->assertSame('cf567713be09b765d18bd9ffb976c3f7fc600fd4', $sign);
    }

    public function testSignRequestSelfConsistencyWithIndependentlyBuiltFormula()
    {
        $signer = new KasushouSigner();
        $timestamp = '1712345678901';
        $params = ['zeta' => '9', 'alpha' => 'b', 'attach' => ['recharge_account' => '13800000000']];

        // 独立按文档公式重新拼一遍：只排序顶层键，JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE。
        $sorted = $params;
        ksort($sorted);
        $expectedJson = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $expected = sha1($timestamp . $expectedJson . self::API_KEY);

        $this->assertSame($expected, $signer->signRequest($params, self::API_KEY, $timestamp));
    }

    public function testSignRequestOnlySortsTopLevelKeysAndUsesUnescapedSlashesAndUnicode()
    {
        $signer = new KasushouSigner();
        $timestamp = '1700000000000';
        // 顶层键故意乱序；嵌套的 attach 内部键顺序必须原样保留，不能被递归排序。
        $params = [
            'url' => 'https://merchant.example.com/notify/kasushou',
            'attach' => ['b_field' => '2', 'a_field' => '1'],
            'external_orderno' => 'EO-001',
        ];

        $expectedJson = json_encode(
            ['attach' => ['b_field' => '2', 'a_field' => '1'], 'external_orderno' => 'EO-001', 'url' => 'https://merchant.example.com/notify/kasushou'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $expected = sha1($timestamp . $expectedJson . self::API_KEY);

        $this->assertSame($expected, $signer->signRequest($params, self::API_KEY, $timestamp));
        // 顺带确认 UNESCAPED_SLASHES 生效：编码结果里斜杠没有被转义成 \/
        $this->assertStringNotContainsString('\/', $expectedJson);
    }

    public function testVerifyCallbackAcceptsValidSignatureAndExcludesCardListAndExpressList()
    {
        $signer = new KasushouSigner();
        $time = '1700000000';

        // 签名只覆盖除 sign/card_list/express_list 外的参数（time 本身仍参与签名），
        // 编码 flag 只用 JSON_UNESCAPED_UNICODE（文档没提 UNESCAPED_SLASHES）。
        $signed = ['external_orderno' => 'EO-001', 'status' => 3, 'time' => $time];
        ksort($signed);
        $json = json_encode($signed, JSON_UNESCAPED_UNICODE);
        $validSign = sha1($time . $json . self::API_KEY);

        $payload = [
            'external_orderno' => 'EO-001',
            'status' => 3,
            'time' => $time,
            'sign' => $validSign,
            // 这两个字段必须被剔除出签名计算，篡改它们也不应该导致验签失败
            'card_list' => [['card_no' => 'tampered', 'card_password' => 'tampered']],
            'express_list' => ['tampered'],
        ];

        $this->assertTrue($signer->verifyCallback($payload, self::API_KEY));
    }

    public function testVerifyCallbackRejectsInvalidSignature()
    {
        $signer = new KasushouSigner();

        $payload = [
            'external_orderno' => 'EO-001',
            'status' => 3,
            'time' => '1700000000',
            'sign' => 'not-the-real-signature',
        ];

        $this->assertFalse($signer->verifyCallback($payload, self::API_KEY));
    }

    public function testVerifyCallbackRejectsMissingSignOrTime()
    {
        $signer = new KasushouSigner();

        $this->assertFalse($signer->verifyCallback(['external_orderno' => 'EO-001', 'time' => '1700000000'], self::API_KEY));
        $this->assertFalse($signer->verifyCallback(['external_orderno' => 'EO-001', 'sign' => 'x'], self::API_KEY));
    }

    public function testVerifyCallbackFixedHandDerivedVector()
    {
        // 手算推导：time = "1700000000"，参数（除 sign 外，time 本身仍留在 JSON 里）
        // = {external_orderno: "EO1", status: 3, time: "1700000000"}
        // 排序后 JSON（JSON_UNESCAPED_UNICODE）-> {"external_orderno":"EO1","status":3,"time":"1700000000"}
        // 拼接 = "1700000000" . '{"external_orderno":"EO1","status":3,"time":"1700000000"}' . "secret"
        // 独立验证 sha1 = 7a843756c5a0830758f717bb0f96542bbf85724d
        $signer = new KasushouSigner();

        $payload = [
            'external_orderno' => 'EO1',
            'status' => 3,
            'time' => '1700000000',
            'sign' => '7a843756c5a0830758f717bb0f96542bbf85724d',
        ];

        $this->assertTrue($signer->verifyCallback($payload, self::API_KEY));
    }

    public function testTimestampIs13DigitMillisecondString()
    {
        $signer = new KasushouSigner();

        $timestamp = $signer->timestamp();

        $this->assertMatchesRegularExpression('/^\d{13}$/', $timestamp);
    }

    // ---- verifyProductChangeNotification：第三套签名作用域，只覆盖 id+time ----

    public function testVerifyProductChangeNotificationAcceptsValidSignature()
    {
        $signer = new KasushouSigner();
        $id = 'GOODS-1';
        $time = '1700000000';

        // 独立按推断公式重新拼一遍：sha1(time + sha1(排序后的 {id,time} JSON，
        // JSON_UNESCAPED_UNICODE) + apikey)。
        $signed = ['id' => $id, 'time' => $time];
        ksort($signed);
        $json = json_encode($signed, JSON_UNESCAPED_UNICODE);
        $sign = sha1($time . $json . self::API_KEY);

        $payload = ['id' => $id, 'time' => $time, 'sign' => $sign];

        $this->assertTrue($signer->verifyProductChangeNotification($payload, self::API_KEY));
    }

    public function testVerifyProductChangeNotificationFixedHandDerivedVector()
    {
        // 手算推导：id = "G1"，time = "1700000000"
        // 排序后 JSON（JSON_UNESCAPED_UNICODE）-> {"id":"G1","time":"1700000000"}
        // 拼接 = "1700000000" . '{"id":"G1","time":"1700000000"}' . "secret"
        //      = '1700000000{"id":"G1","time":"1700000000"}secret'
        // 用 `printf '%s' '1700000000{"id":"G1","time":"1700000000"}secret' | shasum -a 1`
        // 独立验证（不经过 PHP/KasushouSigner）得到 790c8327da5caac6b888fa442aedaf9394086672
        $signer = new KasushouSigner();

        $payload = [
            'id' => 'G1',
            'time' => '1700000000',
            'sign' => '790c8327da5caac6b888fa442aedaf9394086672',
        ];

        $this->assertTrue($signer->verifyProductChangeNotification($payload, self::API_KEY));
    }

    public function testVerifyProductChangeNotificationRejectsTamperedSign()
    {
        $signer = new KasushouSigner();

        $payload = ['id' => 'G1', 'time' => '1700000000', 'sign' => 'not-the-real-signature'];

        $this->assertFalse($signer->verifyProductChangeNotification($payload, self::API_KEY));
    }

    public function testVerifyProductChangeNotificationRejectsWrongSecret()
    {
        $signer = new KasushouSigner();
        $id = 'G1';
        $time = '1700000000';
        $signed = ['id' => $id, 'time' => $time];
        ksort($signed);
        $sign = sha1($time . json_encode($signed, JSON_UNESCAPED_UNICODE) . 'a-different-secret');

        $payload = ['id' => $id, 'time' => $time, 'sign' => $sign];

        $this->assertFalse($signer->verifyProductChangeNotification($payload, self::API_KEY));
    }

    public function testVerifyProductChangeNotificationIgnoresUnsignedPriceStatusStockFields()
    {
        $signer = new KasushouSigner();
        $id = 'G1';
        $time = '1700000000';
        // 只对 id+time 签名——即便 payload 里带了 price/status/stock，篡改它们
        // 也不应该影响验签结果（这些字段不在签名保护范围内，是本任务的核心安全点）。
        $signed = ['id' => $id, 'time' => $time];
        ksort($signed);
        $sign = sha1($time . json_encode($signed, JSON_UNESCAPED_UNICODE) . self::API_KEY);

        $payload = [
            'id' => $id,
            'time' => $time,
            'sign' => $sign,
            'price' => '999.99',
            'status' => 'banned',
            'stock' => 0,
        ];

        $this->assertTrue($signer->verifyProductChangeNotification($payload, self::API_KEY));
    }

    public function testVerifyProductChangeNotificationRejectsMissingIdOrTime()
    {
        $signer = new KasushouSigner();

        $this->assertFalse($signer->verifyProductChangeNotification(['time' => '1700000000', 'sign' => 'x'], self::API_KEY));
        $this->assertFalse($signer->verifyProductChangeNotification(['id' => 'G1', 'sign' => 'x'], self::API_KEY));
        $this->assertFalse($signer->verifyProductChangeNotification(['id' => 'G1', 'time' => '1700000000'], self::API_KEY));
    }
}
