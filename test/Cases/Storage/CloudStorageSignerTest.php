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

use App\Storage\AliyunOssSigner;
use App\Storage\TencentCosSigner;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * 两家云存储的签名按官方文档的示例核对。能核对的是不依赖密钥的中间值（规范化请求、HttpHeaders、
 * 它们的哈希）：这些对上了，剩下的只是标准 HMAC。文档示例里的密钥是官方公开的样例值，也不放进仓库
 * （会被 GitHub 推送保护当成泄露的密钥），涉及密钥的部分换成测试密钥按同一公式核对。
 *
 * @internal
 * @coversNothing
 */
class CloudStorageSignerTest extends TestCase
{
    /**
     * 阿里云「在Header中包含V4签名」签名计算示例：规范化请求的 SHA256。
     */
    public function testAliyunCanonicalRequestMatchesDocumentExample()
    {
        $canonical = (new AliyunOssSigner())->canonicalRequest('PUT', '/examplebucket/exampleobject', [
            'Content-Disposition' => 'attachment',
            'Content-Length' => '3',
            'Content-MD5' => 'ICy5YqxZB1uWSwcVLSNLcA==',
            'Content-Type' => 'text/plain',
            'x-oss-content-sha256' => 'UNSIGNED-PAYLOAD',
            'x-oss-date' => '20250411T064124Z',
        ], 'content-disposition;content-length');

        $this->assertSame('c46d96390bdbc2d739ac9363293ae9d710b14e48081fcb22cd8ad54b63136eca', hash('sha256', $canonical));
    }

    public function testAliyunSignAddsV4Headers()
    {
        $headers = (new AliyunOssSigner())->sign(
            'PUT',
            'examplebucket',
            'uploads/images/a.png',
            'cn-hangzhou',
            ['Content-Type' => 'image/png'],
            'test-access-key-id',
            'secret',
            new DateTimeImmutable('2025-04-11 06:41:24', new DateTimeZone('UTC'))
        );

        $this->assertSame('20250411T064124Z', $headers['x-oss-date']);
        $this->assertSame('UNSIGNED-PAYLOAD', $headers['x-oss-content-sha256']);
        $this->assertMatchesRegularExpression(
            '#^OSS4-HMAC-SHA256 Credential=test-access-key-id/20250411/cn-hangzhou/oss/aliyun_v4_request, Signature=[0-9a-f]{64}$#',
            $headers['Authorization']
        );
    }

    /**
     * 腾讯云「请求签名」示例一（上传对象）：HeaderList、HttpHeaders 跟文档一致；HttpString 的 SHA1
     * 跟文档一致（文档值 8b2751e7…，不依赖密钥），所以用测试密钥算出的签名必须等于按文档公式拼出来的。
     */
    public function testTencentSignatureMatchesDocumentExample()
    {
        $signer = new TencentCosSigner();
        [$headerList, $httpHeaders] = $signer->canonicalHeaders([
            'Date' => 'Thu, 16 May 2019 06:45:51 GMT',
            'Host' => 'examplebucket-1250000000.cos.ap-beijing.myqcloud.com',
            'Content-Type' => 'text/plain',
            'Content-Length' => '13',
            'Content-MD5' => 'mQ/fVh815F3k6TAUm8m0eg==',
            'x-cos-acl' => 'private',
            'x-cos-grant-read' => 'uin="100000000011"',
        ]);

        $this->assertSame('content-length;content-md5;content-type;date;host;x-cos-acl;x-cos-grant-read', $headerList);
        $this->assertSame(
            'content-length=13&content-md5=mQ%2FfVh815F3k6TAUm8m0eg%3D%3D&content-type=text%2Fplain&date=Thu%2C%2016%20May%202019%2006%3A45%3A51%20GMT&host=examplebucket-1250000000.cos.ap-beijing.myqcloud.com&x-cos-acl=private&x-cos-grant-read=uin%3D%22100000000011%22',
            $httpHeaders
        );

        $keyTime = '1557989151;1557996351';
        $secretKey = 'test-secret-key';
        $documentHttpStringSha1 = '8b2751e77f43a0995d6e9eb9477f4b685cca4172';
        $this->assertSame(
            hash_hmac('sha1', "sha1\n" . $keyTime . "\n" . $documentHttpStringSha1 . "\n", hash_hmac('sha1', $keyTime, $secretKey)),
            $signer->signature('PUT', '/exampleobject(腾讯云)', $httpHeaders, $secretKey, $keyTime)
        );

        $authorization = $signer->authorization('PUT', '/exampleobject(腾讯云)', [
            'Host' => 'examplebucket-1250000000.cos.ap-beijing.myqcloud.com',
        ], 'test-secret-id', $secretKey, 1557989151, 1557996351);
        $this->assertMatchesRegularExpression(
            '#^q-sign-algorithm=sha1&q-ak=test-secret-id&q-sign-time=1557989151;1557996351&q-key-time=1557989151;1557996351&q-header-list=host&q-url-param-list=&q-signature=[0-9a-f]{40}$#',
            $authorization
        );
    }
}
