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

namespace HyperfTest\Cases\Notify;

use App\Notify\Sms\AliyunSmsSender;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hyperf\Guzzle\ClientFactory;
use Hyperf\Logger\LoggerFactory;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 * @coversNothing
 */
class AliyunSmsSenderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    /**
     * 阿里云 RPC 签名文档里的示例（AccessKeySecret = testsecret），签名结果以文档为准。
     */
    public function testSignatureMatchesAliyunDocumentationExample()
    {
        $signed = AliyunSmsSender::sign([
            'AccessKeyId' => 'testid',
            'Action' => 'DescribeRegions',
            'Format' => 'XML',
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureNonce' => '3ee8c1b8-83d3-44af-a94f-4e0ad82fd6cf',
            'SignatureVersion' => '1.0',
            'Timestamp' => '2016-02-23T12:46:24Z',
            'Version' => '2014-05-26',
        ], 'testsecret');

        $this->assertSame('OLeaidS1JvxuMvnyHOwuJ+uX5qY=', $signed['Signature']);
    }

    public function testSendsSendSmsRequestWithCodeTemplateParam()
    {
        $captured = null;
        $sender = $this->sender(function (string $method, string $url, array $options) use (&$captured) {
            $captured = $options['query'];

            return new Response(200, [], json_encode(['Code' => 'OK', 'Message' => 'OK', 'RequestId' => 'r1']));
        });

        $this->assertTrue($sender->sendVerificationCode('13800138000', '123456'));
        $this->assertSame('SendSms', $captured['Action']);
        $this->assertSame('13800138000', $captured['PhoneNumbers']);
        $this->assertSame('测试签名', $captured['SignName']);
        $this->assertSame('SMS_1', $captured['TemplateCode']);
        $this->assertSame('{"code":"123456"}', $captured['TemplateParam']);
        $this->assertSame('ak', $captured['AccessKeyId']);
        $withoutSignature = $captured;
        unset($withoutSignature['Signature']);
        $this->assertSame(AliyunSmsSender::sign($withoutSignature, 'secret')['Signature'], $captured['Signature']);
    }

    public function testReturnsFalseOnAliyunErrorOrNetworkFailure()
    {
        $error = $this->sender(fn () => new Response(200, [], json_encode(['Code' => 'isv.BUSINESS_LIMIT_CONTROL', 'Message' => '触发流控'])));
        $this->assertFalse($error->sendVerificationCode('13800138000', '123456'));

        $network = $this->sender(function () {
            throw new ConnectException('timeout', new Request('GET', 'https://dysmsapi.aliyuncs.com/'));
        });
        $this->assertFalse($network->sendVerificationCode('13800138000', '123456'));
    }

    private function sender(callable $handler): AliyunSmsSender
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('request')->andReturnUsing($handler);
        $clientFactory = Mockery::mock(ClientFactory::class);
        $clientFactory->shouldReceive('create')->andReturn($client);
        $loggerFactory = Mockery::mock(LoggerFactory::class);
        $loggerFactory->shouldReceive('get')->andReturn(new NullLogger());

        return new AliyunSmsSender('ak', 'secret', '测试签名', 'SMS_1', $clientFactory, $loggerFactory);
    }
}
