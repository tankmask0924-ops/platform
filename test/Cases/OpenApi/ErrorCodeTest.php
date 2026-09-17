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

namespace HyperfTest\Cases\OpenApi;

use App\OpenApi\ErrorCode;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ErrorCodeTest extends TestCase
{
    public function testEveryCodeHasMessageAndKnownSegment()
    {
        $segments = [400, 410, 420, 430, 490, 500];

        foreach (ErrorCode::cases() as $case) {
            $this->assertNotSame('', $case->message(), $case->name);
            $this->assertContains(intdiv($case->value, 100), $segments, $case->name);
        }
    }

    public function testMessagesAreUniqueSoOrderFailuresCanBeResolvedBack()
    {
        $messages = array_map(static fn (ErrorCode $case) => $case->message(), ErrorCode::cases());

        $this->assertSame($messages, array_values(array_unique($messages)));
    }

    public function testHttpStatusBySegment()
    {
        $this->assertSame(401, ErrorCode::InvalidSignature->httpStatus());
        $this->assertSame(403, ErrorCode::IpNotAllowed->httpStatus());
        $this->assertSame(429, ErrorCode::RateLimited->httpStatus());
        $this->assertSame(200, ErrorCode::InvalidParams->httpStatus());
        $this->assertSame(200, ErrorCode::ProductNotFound->httpStatus());
        $this->assertSame(404, ErrorCode::RouteNotFound->httpStatus());
        $this->assertSame(405, ErrorCode::MethodNotAllowed->httpStatus());
        $this->assertSame(500, ErrorCode::InternalError->httpStatus());
    }

    public function testKnownOrderFailureMessagesMapBackToTheirCode()
    {
        foreach ([ErrorCode::InsufficientBalance, ErrorCode::NoSupplierAvailable, ErrorCode::OrderFailed] as $case) {
            $this->assertSame(
                ['fail_code' => $case->value, 'fail_reason' => $case->message()],
                ErrorCode::presentOrderFailure($case->message())
            );
        }
    }

    public function testUnknownFailReasonIsNeverPassedThrough()
    {
        foreach (['kasushou: order status 4', '无可用供应商', ErrorCode::ProductNotFound->message()] as $raw) {
            $this->assertSame(
                ['fail_code' => ErrorCode::OrderFailed->value, 'fail_reason' => ErrorCode::OrderFailed->message()],
                ErrorCode::presentOrderFailure($raw),
                $raw
            );
        }
    }

    public function testNoFailReasonPresentsAsNulls()
    {
        foreach ([null, ''] as $empty) {
            $this->assertSame(['fail_code' => null, 'fail_reason' => null], ErrorCode::presentOrderFailure($empty));
        }
    }
}
