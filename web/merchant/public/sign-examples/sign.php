<?php

/**
 * 开放 API 签名示例（PHP 7.0+）。
 *
 * 签名规则：除 sign 外的全部参数（公共参数 + 业务参数）按参数名字母升序排列，
 * 拼成 k1=v1&k2=v2（值用原文，不做 URL 编码），以 AppSecret 为密钥做 HMAC-SHA256，
 * 结果为 64 位小写十六进制字符串。平台回调商户时用同样的规则签名。
 */

function open_api_sign(array $params, string $appSecret): string
{
    unset($params['sign']);
    ksort($params, SORT_STRING);
    $pairs = [];
    foreach ($params as $key => $value) {
        $pairs[] = $key . '=' . $value;
    }

    return hash_hmac('sha256', implode('&', $pairs), $appSecret);
}

/**
 * 校验平台回调：签名一致，且 timestamp 在 5 分钟以内。
 */
function open_api_verify(array $params, string $appSecret): bool
{
    if (! isset($params['sign'], $params['timestamp']) || abs(time() - (int) $params['timestamp']) > 300) {
        return false;
    }

    return hash_equals(open_api_sign($params, $appSecret), (string) $params['sign']);
}

// ---- 调用示例：查询余额 ----
$baseUrl = 'https://your-platform-host/open-api'; // 换成接口文档页上显示的地址
$appKey = 'your_app_key';
$appSecret = 'your_app_secret';

$params = [
    'app_key' => $appKey,
    'timestamp' => (string) time(),
    'nonce' => bin2hex(random_bytes(16)),
];
$params['sign'] = open_api_sign($params, $appSecret);
echo $baseUrl . '/balance?' . http_build_query($params), PHP_EOL;

// ---- 回调接收示例（放在你的回调地址对应的脚本里）----
// if (open_api_verify($_POST, $appSecret)) {
//     // 按 $_POST['merchant_order_no'] 更新本地订单；同一订单可能收到多次通知，注意幂等
//     echo 'success';
// }

// ---- 自检：用固定参数计算，结果应与接口文档页上的示例签名一致 ----
echo open_api_sign([
    'app_key' => 'ak_demo',
    'timestamp' => '1758153600',
    'nonce' => 'e4b1c2d3f4a5b6c7',
    'business_line' => 'recharge',
], 'demo_secret'), PHP_EOL;
