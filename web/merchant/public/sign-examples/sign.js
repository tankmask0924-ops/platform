/**
 * 开放 API 签名示例（Node.js 12+，只用内置模块）。
 *
 * 签名规则：除 sign 外的全部参数（公共参数 + 业务参数）按参数名字母升序排列，
 * 拼成 k1=v1&k2=v2（值用原文，不做 URL 编码），以 AppSecret 为密钥做 HMAC-SHA256，
 * 结果为 64 位小写十六进制字符串。平台回调商户时用同样的规则签名。
 */
const crypto = require('crypto')

function openApiSign(params, appSecret) {
  const text = Object.keys(params)
    .filter((k) => k !== 'sign')
    .sort()
    .map((k) => `${k}=${params[k]}`)
    .join('&')
  return crypto.createHmac('sha256', appSecret).update(text, 'utf8').digest('hex')
}

/** 校验平台回调：签名一致，且 timestamp 在 5 分钟以内。 */
function openApiVerify(params, appSecret) {
  const sign = String(params.sign || '')
  if (sign.length !== 64 || Math.abs(Date.now() / 1000 - Number(params.timestamp)) > 300) {
    return false
  }
  return crypto.timingSafeEqual(Buffer.from(openApiSign(params, appSecret)), Buffer.from(sign))
}

module.exports = { openApiSign, openApiVerify }

if (require.main === module) {
  // ---- 调用示例：查询余额 ----
  const baseUrl = 'https://your-platform-host/open-api' // 换成接口文档页上显示的地址
  const appKey = 'your_app_key'
  const appSecret = 'your_app_secret'

  const params = {
    app_key: appKey,
    timestamp: String(Math.floor(Date.now() / 1000)),
    nonce: crypto.randomBytes(16).toString('hex'),
  }
  params.sign = openApiSign(params, appSecret)
  console.log(`${baseUrl}/balance?${new URLSearchParams(params)}`)

  // ---- 回调接收示例（以 Express 为例）----
  // app.post('/callback', express.urlencoded({ extended: false }), (req, res) => {
  //   if (openApiVerify(req.body, appSecret)) {
  //     // 按 merchant_order_no 更新本地订单；同一订单可能收到多次通知，注意幂等
  //     return res.send('success')
  //   }
  //   res.send('fail')
  // })

  // ---- 自检：用固定参数计算，结果应与接口文档页上的示例签名一致 ----
  console.log(
    openApiSign(
      { app_key: 'ak_demo', timestamp: '1758153600', nonce: 'e4b1c2d3f4a5b6c7', business_line: 'recharge' },
      'demo_secret',
    ),
  )
}
