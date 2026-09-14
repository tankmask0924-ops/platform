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

namespace App\Job;

use App\Crypto\Encryptor;
use App\Dao\MerchantDao;
use App\Dao\MerchantNotifyLogDao;
use App\Dao\OrderDao;
use App\Model\Merchant;
use App\Model\Order;
use App\Notify\CallbackUrlGuard;
use App\Signature\SignatureSigner;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Job;
use Hyperf\Context\ApplicationContext;
use Hyperf\Guzzle\ClientFactory;
use Hyperf\Logger\LoggerFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * 平台 → 商户结果回调（requirements.md 7.6），一次尝试对应一个 Job 实例。
 *
 * Job 会被序列化进 Redis（见 Hyperf\AsyncQueue\Driver\DriverFactory），构造参数
 * 只能是标量，不能持有任何注入的服务实例——跟 App\Job\SendUserWelcomeJob 是同一个
 * 约束，所有服务都在 handle() 里现从容器取（ApplicationContext::getContainer()）。
 *
 * 商户返回 success 视为通知成功：这里采用的是「HTTP 响应体 trim 后不区分大小写
 * 等于字面量 success」这个约定（跟微信支付一类通知接口的通用做法一致）。
 * requirements.md 7.6 原文只写了"商户返回 success 视为通知成功"，没有给出更精确的
 * 协议细节，如果后续实际对接的商户用的是别的约定（比如要求返回 JSON
 * {"code":"SUCCESS"}），改 isSuccessBody() 这一个方法就够了，不用动调用方。
 *
 * 回调 payload 里不放 card_no/card_pwd：结果通知只需要告诉商户订单状态
 * （成功/失败/取消/已退款），不需要卡密这类敏感明细——商户要卡密走专门鉴权的
 * 订单查询接口（App\Controller\OpenApi\OrderController）。这就是这里没有额外
 * "payload 打码"逻辑的原因：压根不把卡密放进去，比事后打码更简单也更不容易漏。
 */
class NotifyMerchantJob extends Job
{
    /**
     * 首次失败之后，第 2~7 次尝试（attempt_no）分别延迟多少秒发起，对应
     * requirements.md 7.6 的「1 分钟、5 分钟、15 分钟、1 小时、2 小时、6 小时」。
     * 第 1 次尝试本身由 App\Service\MerchantNotifyService 以 delay=0 触发，不在这张表里。
     */
    private const RETRY_DELAY_SECONDS = [
        2 => 60,
        3 => 300,
        4 => 900,
        5 => 3600,
        6 => 7200,
        7 => 21600,
    ];

    private const MAX_ATTEMPTS = 7;

    private const SUCCESS_BODY = 'success';

    public function __construct(protected int $orderId, protected int $attemptNo)
    {
    }

    public function handle(): void
    {
        $container = ApplicationContext::getContainer();

        /** @var null|Order $order */
        $order = $container->get(OrderDao::class)->find($this->orderId);
        if (! $order instanceof Order) {
            // 理论上不应该发生（Job 是拿着已存在的 order_id 派发的），但防御性地
            // 不崩溃、也不重试——一个查不到的订单不会因为重试就查得到。
            $this->logger()->warning(sprintf('NotifyMerchantJob: order #%d not found, give up.', $this->orderId));
            return;
        }

        /** @var null|Merchant $merchant */
        $merchant = $container->get(MerchantDao::class)->find($order->merchant_id);
        if (! $merchant instanceof Merchant || ! is_string($merchant->app_secret) || $merchant->app_secret === '') {
            $this->logger()->warning(sprintf(
                'NotifyMerchantJob: merchant #%d for order #%d not found or has no app_secret, give up.',
                $order->merchant_id,
                $this->orderId
            ));
            return;
        }

        $url = $order->callback_url;
        $payload = $this->buildSignedPayload($order, $merchant, $container->get(Encryptor::class), $container->get(SignatureSigner::class));

        if (! $container->get(CallbackUrlGuard::class)->isAllowed($url)) {
            // URL 本身就不合法（非 http/https，或指向内网/保留地址）：不发起任何出站
            // 请求，http_status/response_body 都是 null——这不是「网络请求失败」，
            // 是压根没有发起请求。一个坏 URL 不会因为等一等就变合法，所以不安排重试。
            $this->log($container, $order->id, $url, $payload, null, null, false);
            return;
        }

        [$httpStatus, $responseBody, $success] = $this->send($url, $payload);

        $this->log($container, $order->id, $url, $payload, $responseBody, $httpStatus, $success);

        if ($success) {
            return;
        }

        if ($this->attemptNo >= self::MAX_ATTEMPTS) {
            $this->logger()->warning(sprintf(
                'NotifyMerchantJob: order #%d exhausted all %d attempts, give up.',
                $this->orderId,
                self::MAX_ATTEMPTS
            ));
            return;
        }

        $nextAttempt = $this->attemptNo + 1;
        $delay = self::RETRY_DELAY_SECONDS[$nextAttempt] ?? 0;
        $container->get(DriverFactory::class)->get('default')->push(new self($this->orderId, $nextAttempt), $delay);
    }

    protected function httpClient(): ClientInterface
    {
        return ApplicationContext::getContainer()->get(ClientFactory::class)->create();
    }

    protected function logger(): LoggerInterface
    {
        return ApplicationContext::getContainer()
            ->get(LoggerFactory::class)
            ->get('async-queue');
    }

    /**
     * @return array{0: null|int, 1: null|string, 2: bool} [http_status, response_body, success]
     */
    private function send(string $url, array $payload): array
    {
        try {
            $response = $this->httpClient()->request('POST', $url, [
                'form_params' => $payload,
                'timeout' => 5,
                'connect_timeout' => 3,
                // 商户返回非 2xx 状态码时也希望能拿到状态码/响应体走「非 success」
                // 分支判断，而不是被当成「连接失败」——所以关掉 Guzzle 对 4xx/5xx
                // 自动抛异常的行为，异常分支只留给真正的连接/超时失败。
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            return [null, null, false];
        }

        $httpStatus = $response->getStatusCode();
        $responseBody = (string) $response->getBody();

        return [$httpStatus, $responseBody, $this->isSuccessBody($responseBody)];
    }

    private function isSuccessBody(string $body): bool
    {
        return strtolower(trim($body)) === self::SUCCESS_BODY;
    }

    /**
     * @param array<string, scalar> $payload
     */
    private function log(
        ContainerInterface $container,
        int $orderId,
        string $url,
        array $payload,
        ?string $responseBody,
        ?int $httpStatus,
        bool $success
    ): void {
        $container->get(MerchantNotifyLogDao::class)->log(
            $orderId,
            $url,
            $payload,
            $responseBody,
            $httpStatus,
            $this->attemptNo,
            $success
        );
    }

    /**
     * 签名参数沿用 App\Middleware\OpenApiSignatureMiddleware 校验入站请求时的同一套
     * app_key/timestamp/nonce/sign 约定（requirements.md 8.1），平台回调商户时复用
     * App\Signature\SignatureSigner::sign() 这同一个类/同一套算法，不写第二套签名逻辑。
     *
     * 通知内容本身只包含订单状态相关字段（不含卡密，见类注释），字段为空
     * （比如订单还没有 fail_reason/completed_at）时不参与签名，跟 sign() 忽略
     * sign 字段是同一个道理——array_filter 去掉 null 之后剩下的都是标量。
     *
     * @return array<string, scalar>
     */
    private function buildSignedPayload(Order $order, Merchant $merchant, Encryptor $encryptor, SignatureSigner $signer): array
    {
        $params = array_filter([
            'order_no' => $order->order_no,
            'merchant_order_no' => $order->merchant_order_no,
            'business_line' => $order->business_line,
            'status' => $order->status,
            'completed_at' => $order->completed_at?->toDateTimeString(),
            'fail_reason' => $order->fail_reason,
            'app_key' => $merchant->app_key,
            'timestamp' => (string) time(),
            'nonce' => bin2hex(random_bytes(16)),
        ], static fn ($value) => $value !== null);

        $secret = $encryptor->decrypt($merchant->app_secret);
        $params['sign'] = $signer->sign($params, $secret);

        return $params;
    }
}
