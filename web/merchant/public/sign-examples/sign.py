"""
开放 API 签名示例（Python 3.6+，只用标准库）。

签名规则：除 sign 外的全部参数（公共参数 + 业务参数）按参数名字母升序排列，
拼成 k1=v1&k2=v2（值用原文，不做 URL 编码），以 AppSecret 为密钥做 HMAC-SHA256，
结果为 64 位小写十六进制字符串。平台回调商户时用同样的规则签名。
"""
import hashlib
import hmac
import secrets
import time
from urllib.parse import urlencode


def open_api_sign(params, app_secret):
    items = sorted((k, str(v)) for k, v in params.items() if k != 'sign')
    text = '&'.join('%s=%s' % (k, v) for k, v in items)
    return hmac.new(app_secret.encode('utf-8'), text.encode('utf-8'), hashlib.sha256).hexdigest()


def open_api_verify(params, app_secret):
    """校验平台回调：签名一致，且 timestamp 在 5 分钟以内。"""
    sign = params.get('sign')
    try:
        fresh = abs(time.time() - int(params.get('timestamp', ''))) <= 300
    except ValueError:
        return False
    return bool(sign) and fresh and hmac.compare_digest(open_api_sign(params, app_secret), sign)


if __name__ == '__main__':
    # ---- 调用示例：查询余额 ----
    base_url = 'https://your-platform-host/open-api'  # 换成接口文档页上显示的地址
    app_key = 'your_app_key'
    app_secret = 'your_app_secret'

    params = {'app_key': app_key, 'timestamp': str(int(time.time())), 'nonce': secrets.token_hex(16)}
    params['sign'] = open_api_sign(params, app_secret)
    print(base_url + '/balance?' + urlencode(params))

    # ---- 回调接收示例（以 Flask 为例）----
    # @app.post('/callback')
    # def callback():
    #     if open_api_verify(request.form.to_dict(), app_secret):
    #         # 按 merchant_order_no 更新本地订单；同一订单可能收到多次通知，注意幂等
    #         return 'success'
    #     return 'fail'

    # ---- 自检：用固定参数计算，结果应与接口文档页上的示例签名一致 ----
    print(open_api_sign({
        'app_key': 'ak_demo',
        'timestamp': '1758153600',
        'nonce': 'e4b1c2d3f4a5b6c7',
        'business_line': 'recharge',
    }, 'demo_secret'))
