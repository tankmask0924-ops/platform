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

namespace HyperfTest\Cases\Storage;

use App\Storage\CloudStorageClient;
use App\Storage\StorageConfig;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class CloudStorageClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testAliyunPutGoesToBucketHostAndReturnsPublicUrl()
    {
        $captured = [];
        $client = $this->makeClient($this->config(StorageConfig::DRIVER_ALIYUN_OSS, 'img-bucket', 'cn-hangzhou'), function ($method, $url, $options) use (&$captured) {
            $captured = [$method, $url, $options];

            return new Response(200);
        });

        $url = $client->put('uploads/images/20261120/a.png', 'PNGDATA', 'image/png');

        $this->assertSame('https://img-bucket.oss-cn-hangzhou.aliyuncs.com/uploads/images/20261120/a.png', $url);
        $this->assertSame('PUT', $captured[0]);
        $this->assertSame('https://img-bucket.oss-cn-hangzhou.aliyuncs.com/uploads/images/20261120/a.png', $captured[1]);
        $this->assertSame('PNGDATA', $captured[2]['body']);
        $this->assertStringStartsWith('OSS4-HMAC-SHA256 Credential=testkeyid0001/', $captured[2]['headers']['Authorization']);
        $this->assertSame('image/png', $captured[2]['headers']['Content-Type']);
    }

    public function testTencentPutUsesCosHostCustomDomainAndSurfacesXmlError()
    {
        $calls = 0;
        $config = $this->config(StorageConfig::DRIVER_TENCENT_COS, 'images-1250000000', 'ap-guangzhou', 'https://cdn.example.com/');
        $client = $this->makeClient($config, function ($method, $url, $options) use (&$calls) {
            ++$calls;
            $this->assertSame('https://images-1250000000.cos.ap-guangzhou.myqcloud.com/k.png', $url);
            $this->assertStringStartsWith('q-sign-algorithm=sha1&q-ak=testkeyid0001&', $options['headers']['Authorization']);
            $this->assertStringContainsString('q-header-list=content-type;host&', $options['headers']['Authorization']);

            return $calls === 1
                ? new Response(200)
                : new Response(403, [], '<?xml version="1.0"?><Error><Code>SignatureDoesNotMatch</Code><Message>The calculated signature does not match</Message></Error>');
        });

        $this->assertSame('https://cdn.example.com/k.png', $client->put('k.png', 'x', 'image/png'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 403，SignatureDoesNotMatch：The calculated signature does not match');
        $client->put('k.png', 'x', 'image/png');
    }

    private function config(string $driver, string $bucket, string $region, ?string $domain = null): StorageConfig
    {
        return new StorageConfig($driver, $bucket, $region, 'testkeyid0001', 'secret-value', $domain, 'uploads');
    }

    private function makeClient(StorageConfig $config, callable $handler): CloudStorageClient
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->andReturnUsing($handler);

        return new class($config, $http) extends CloudStorageClient {
            public function __construct(StorageConfig $config, private ClientInterface $http)
            {
                parent::__construct($config);
            }

            protected function httpClient(): ClientInterface
            {
                return $this->http;
            }
        };
    }
}
