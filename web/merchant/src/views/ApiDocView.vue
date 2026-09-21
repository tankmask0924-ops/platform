<script setup lang="ts">
import { copyText } from '@platform/shared'
import { onMounted, ref } from 'vue'
import { type ApiErrorCode, apiDocApi } from '@/api/merchant'

interface Field {
  name: string
  type: string
  required?: boolean
  desc: string
}

interface Endpoint {
  title: string
  method: 'GET' | 'POST'
  path: string
  desc: string
  params: Field[]
  response: Field[]
  responseIsList?: boolean
  example: object
  notes?: string[]
}

const baseUrl =
  import.meta.env.VITE_OPEN_API_BASE_URL ||
  `${window.location.origin}${import.meta.env.VITE_API_BASE_URL}/open-api`.replace(/([^:])\/{2,}/g, '$1/')

const active = ref('guide')

const commonParams: Field[] = [
  { name: 'app_key', type: 'string', required: true, desc: '在「开发设置」生成的 AppKey' },
  { name: 'timestamp', type: 'int', required: true, desc: '当前 Unix 时间戳（秒），与服务器时间相差超过 5 分钟会被拒绝' },
  { name: 'nonce', type: 'string', required: true, desc: '随机字符串，5 分钟内不能重复，建议 16 位以上' },
  { name: 'sign', type: 'string', required: true, desc: '签名，见「签名规则」' },
]

const orderFields: Field[] = [
  { name: 'order_no', type: 'string', desc: '平台订单号' },
  { name: 'merchant_order_no', type: 'string', desc: '你的订单号' },
  { name: 'business_line', type: 'string', desc: '业务线：recharge 话费，card 卡券' },
  { name: 'status', type: 'string', desc: '订单状态：processing 处理中，success 成功，failed 失败，refunded 已退款' },
  { name: 'sale_price', type: 'string', desc: '售价（元）' },
  { name: 'frozen_amount', type: 'string', desc: '下单时冻结的金额（元）' },
  { name: 'deducted_amount', type: 'string|null', desc: '实际扣款（元），订单成功后才有值' },
  { name: 'refunded_amount', type: 'string', desc: '已退款金额（元）' },
  { name: 'completed_at', type: 'string|null', desc: '完成时间，格式 2026-09-18 10:00:00' },
  { name: 'fail_code', type: 'int|null', desc: '失败原因码（430xx），见「错误码」' },
  { name: 'fail_reason', type: 'string|null', desc: '失败原因' },
]

const orderExample = {
  order_no: 'R20260918100000123456',
  merchant_order_no: 'M202609180001',
  business_line: 'recharge',
  status: 'processing',
  sale_price: '99.20',
  frozen_amount: '99.20',
  deducted_amount: null,
  refunded_amount: '0.00',
  completed_at: null,
  fail_code: null,
  fail_reason: null,
}

const placeNotes = [
  'code = 0 表示订单已受理，结果以回调或订单查询为准；受理时就能确定失败的（如余额不足）会直接返回 status = failed 和 fail_code。',
  '同一个 merchant_order_no 重复提交不会重复下单，返回第一次的订单，网络超时可以放心用原单号重试。',
  '需要先在「服务开通」开通对应业务线，否则返回 42007。',
]

const endpoints: Endpoint[] = [
  {
    title: '查询余额',
    method: 'GET',
    path: '/balance',
    desc: '查询账户可用余额、冻结金额和待到账返佣。',
    params: [],
    response: [
      { name: 'available_balance', type: 'string', desc: '可用余额（元），有欠款时为负数' },
      { name: 'frozen_balance', type: 'string', desc: '冻结金额（元），处理中订单占用' },
      { name: 'pending_rebate', type: 'string', desc: '待到账返佣（元）' },
      { name: 'debt_since', type: 'string|null', desc: '开始欠款的时间，没有欠款时为 null；欠款期间暂停下单' },
    ],
    example: { available_balance: '1000.00', frozen_balance: '99.20', pending_rebate: '3.50', debt_since: null },
  },
  {
    title: '商品列表（话费 / 卡券）',
    method: 'GET',
    path: '/products',
    desc: '返回你已开通业务线的在售商品、售价和按你当前等级计算的每单返佣。',
    params: [{ name: 'business_line', type: 'string', required: true, desc: 'recharge 话费，card 卡券' }],
    response: [
      { name: 'id', type: 'int', desc: '商品 ID，下单时作为 product_id' },
      { name: 'name', type: 'string', desc: '商品名称' },
      { name: 'operator', type: 'string|null', desc: '话费：运营商 mobile 移动 / unicom 联通 / telecom 电信；卡券为 null' },
      { name: 'province', type: 'string|null', desc: '话费：限定省份，null 表示全国；卡券为 null' },
      { name: 'charge_speed', type: 'string|null', desc: '话费：到账速度 fast 快充 / slow 慢充；卡券为 null' },
      { name: 'card_type', type: 'string|null', desc: '卡券：direct 直充（下单必传 recharge_account）/ card_secret 卡密（下单不传）；话费为 null' },
      { name: 'face_value', type: 'string', desc: '面值（元）' },
      { name: 'sale_price', type: 'string', desc: '售价（元），下单时按此冻结' },
      { name: 'rebate', type: 'string', desc: '每单返佣（元），订单成功后按平台规定期限到账' },
    ],
    responseIsList: true,
    example: [
      {
        id: 12,
        name: '移动 100 元快充',
        operator: 'mobile',
        province: null,
        charge_speed: 'fast',
        card_type: null,
        face_value: '100.00',
        sale_price: '99.20',
        rebate: '0.30',
      },
      {
        id: 31,
        name: '游戏点卡 100 元',
        operator: null,
        province: null,
        charge_speed: null,
        card_type: 'card_secret',
        face_value: '100.00',
        sale_price: '97.00',
        rebate: '2.00',
      },
    ],
    notes: [
      '两条业务线共用这一个接口，用不上的字段返回 null。',
      '卡券下单前先看 card_type：direct 必须传 recharge_account，card_secret 必须不传，传错直接返回参数错误。',
      '没开通对应业务线时返回 42007，去「服务开通」申请。',
    ],
  },
  {
    title: '话费下单',
    method: 'POST',
    path: '/orders/recharge',
    desc: '给手机号充值话费，每单数量固定为 1。',
    params: [
      { name: 'merchant_order_no', type: 'string', required: true, desc: '你的订单号，在你的账户下唯一' },
      { name: 'product_id', type: 'int', required: true, desc: '商品 ID' },
      { name: 'recharge_account', type: 'string', required: true, desc: '充值手机号' },
      { name: 'callback_url', type: 'string', required: true, desc: '结果回调地址，只支持 http/https 公网地址' },
    ],
    response: orderFields,
    example: orderExample,
    notes: placeNotes,
  },
  {
    title: '卡券下单',
    method: 'POST',
    path: '/orders/card',
    desc: '购买卡券，每单数量固定为 1。直充类卡券充到指定账号，卡密类卡券成功后通过订单查询取卡密。',
    params: [
      { name: 'merchant_order_no', type: 'string', required: true, desc: '你的订单号，在你的账户下唯一' },
      { name: 'product_id', type: 'int', required: true, desc: '商品 ID' },
      {
        name: 'recharge_account',
        type: 'string',
        desc: '充值账号：直充类商品必传，卡密类商品不能传',
      },
      { name: 'callback_url', type: 'string', required: true, desc: '结果回调地址，只支持 http/https 公网地址' },
    ],
    response: orderFields,
    example: { ...orderExample, order_no: 'C20260918100000123456', business_line: 'card' },
    notes: placeNotes,
  },
  {
    title: '订单查询',
    method: 'GET',
    path: '/order',
    desc: '按平台订单号或你的订单号查询订单，两者必须且只能传一个。',
    params: [
      { name: 'order_no', type: 'string', desc: '平台订单号' },
      { name: 'merchant_order_no', type: 'string', desc: '你的订单号' },
    ],
    response: [
      ...orderFields,
      { name: 'card_no', type: 'string', desc: '卡号（明文），只有卡密类卡券成功后才返回这个字段' },
      { name: 'card_pwd', type: 'string', desc: '卡密（明文），同上' },
    ],
    example: {
      ...orderExample,
      order_no: 'C20260918100000123456',
      business_line: 'card',
      status: 'success',
      deducted_amount: '99.20',
      completed_at: '2026-09-18 10:00:05',
      card_no: '8800123456789',
      card_pwd: 'ABCD-EFGH-IJKL',
    },
    notes: ['查不到订单返回 42005。'],
  },
]

const callbackFields: Field[] = [
  { name: 'order_no', type: 'string', required: true, desc: '平台订单号' },
  { name: 'merchant_order_no', type: 'string', required: true, desc: '你的订单号' },
  { name: 'business_line', type: 'string', required: true, desc: '业务线' },
  { name: 'status', type: 'string', required: true, desc: 'success 成功，failed 失败，refunded 已退款' },
  { name: 'completed_at', type: 'string', desc: '完成时间，没有时不带这个字段' },
  { name: 'fail_code', type: 'int', desc: '失败原因码，只在失败时带' },
  { name: 'fail_reason', type: 'string', desc: '失败原因，只在失败时带' },
  { name: 'app_key', type: 'string', required: true, desc: '你的 AppKey' },
  { name: 'timestamp', type: 'int', required: true, desc: '发送时间戳（秒）' },
  { name: 'nonce', type: 'string', required: true, desc: '随机字符串' },
  { name: 'sign', type: 'string', required: true, desc: '签名，规则跟请求签名相同' },
]

const signDemo = {
  secret: 'demo_secret',
  params: 'app_key=ak_demo, timestamp=1758153600, nonce=e4b1c2d3f4a5b6c7, business_line=recharge',
  text: 'app_key=ak_demo&business_line=recharge&nonce=e4b1c2d3f4a5b6c7&timestamp=1758153600',
  sign: 'e89290be61170ed37c78476fd421127dd70de8b5ff5f2fd22742efb47ffac990',
}

const examples = [
  { lang: 'PHP', file: 'sign.php', note: 'PHP 7.0+' },
  { lang: 'Java', file: 'OpenApiSign.java', note: 'Java 8+，只用 JDK' },
  { lang: 'Python', file: 'sign.py', note: 'Python 3.6+，只用标准库' },
  { lang: 'Node.js', file: 'sign.js', note: 'Node.js 12+，只用内置模块' },
]
const exampleUrl = (file: string) => `${import.meta.env.BASE_URL}sign-examples/${file}`

const errorCodes = ref<ApiErrorCode[]>([])
const loadingCodes = ref(false)

async function loadErrorCodes() {
  loadingCodes.value = true
  try {
    errorCodes.value = await apiDocApi.errorCodes()
  } finally {
    loadingCodes.value = false
  }
}

const json = (value: object) => JSON.stringify({ code: 0, message: 'ok', data: value }, null, 2)

onMounted(loadErrorCodes)
</script>

<template>
  <el-card shadow="never">
    <el-tabs v-model="active">
      <el-tab-pane label="接入说明" name="guide">
        <ol class="steps">
          <li>在「资质资料」完成资质审核，再到「服务开通」申请要用的业务线，审核通过后才能查商品和下单。</li>
          <li>在「开发设置」生成 AppKey / AppSecret；需要限制来源时配置 IP 白名单（不配置则不限制）。</li>
          <li>按「签名规则」给每个请求签名后调用接口，下单时传入你的回调地址。</li>
          <li>接收结果回调并验签，或者调订单查询接口主动查结果。</li>
        </ol>

        <el-descriptions :column="1" border class="block">
          <el-descriptions-item label="接口地址">
            <code>{{ baseUrl }}</code>
            <el-button link type="primary" class="copy" @click="copyText(baseUrl)">复制</el-button>
          </el-descriptions-item>
          <el-descriptions-item label="请求方式">
            GET 参数放在查询字符串；POST 参数用 <code>application/x-www-form-urlencoded</code> 表单提交，公共参数和业务参数放在一起
          </el-descriptions-item>
          <el-descriptions-item label="字符编码">UTF-8</el-descriptions-item>
          <el-descriptions-item label="返回格式">
            JSON：<code>{"code": 0, "message": "ok", "data": {...}}</code>，<code>code</code> 为 0 表示成功，非 0 见「错误码」
          </el-descriptions-item>
          <el-descriptions-item label="金额">字符串，单位元，保留两位小数</el-descriptions-item>
          <el-descriptions-item label="限流">按商户限制每秒请求数，超出返回 40009，需要调整请联系平台</el-descriptions-item>
          <el-descriptions-item label="测试环境">暂不提供沙箱，请用小额真实订单联调</el-descriptions-item>
        </el-descriptions>
      </el-tab-pane>

      <el-tab-pane label="签名规则" name="sign">
        <h4>公共参数</h4>
        <p class="muted">每个请求都要带，跟业务参数一起提交。</p>
        <el-table :data="commonParams" border class="block">
          <el-table-column prop="name" label="参数" width="140" />
          <el-table-column prop="type" label="类型" width="90" />
          <el-table-column label="必填" width="70">
            <template #default="{ row }">{{ row.required ? '是' : '否' }}</template>
          </el-table-column>
          <el-table-column prop="desc" label="说明" min-width="240" />
        </el-table>

        <h4>计算方法</h4>
        <ol class="steps">
          <li>取除 <code>sign</code> 以外的全部参数（公共参数 + 业务参数，空值也参与）。</li>
          <li>按参数名字母升序排列，拼成 <code>k1=v1&amp;k2=v2</code>。参数值用原文，<b>拼接时不做 URL 编码</b>（发送请求时照常编码）。</li>
          <li>以 AppSecret 为密钥，对拼好的字符串做 HMAC-SHA256，得到 64 位小写十六进制字符串，即 <code>sign</code>。</li>
        </ol>

        <h4>示例</h4>
        <el-descriptions :column="1" border class="block">
          <el-descriptions-item label="AppSecret"><code>{{ signDemo.secret }}</code></el-descriptions-item>
          <el-descriptions-item label="参数"><code>{{ signDemo.params }}</code></el-descriptions-item>
          <el-descriptions-item label="待签名字符串"><code class="wrap">{{ signDemo.text }}</code></el-descriptions-item>
          <el-descriptions-item label="sign"><code class="wrap">{{ signDemo.sign }}</code></el-descriptions-item>
        </el-descriptions>
        <p class="muted">可以用下载的示例代码跑一下，自检输出应该跟这里的 sign 一致。</p>
      </el-tab-pane>

      <el-tab-pane label="接口列表" name="endpoints">
        <el-collapse>
          <el-collapse-item v-for="ep in endpoints" :key="ep.path" :name="ep.path">
            <template #title>
              <el-tag :type="ep.method === 'GET' ? 'success' : 'warning'" size="small" class="method">{{ ep.method }}</el-tag>
              <code>{{ ep.path }}</code>
              <span class="ep-title">{{ ep.title }}</span>
            </template>

            <p>{{ ep.desc }}</p>
            <p class="muted">完整地址：<code>{{ baseUrl }}{{ ep.path }}</code></p>

            <h4>请求参数（另加公共参数）</h4>
            <el-table v-if="ep.params.length" :data="ep.params" border class="block">
              <el-table-column prop="name" label="参数" width="170" />
              <el-table-column prop="type" label="类型" width="90" />
              <el-table-column label="必填" width="70">
                <template #default="{ row }">{{ row.required ? '是' : '否' }}</template>
              </el-table-column>
              <el-table-column prop="desc" label="说明" min-width="240" />
            </el-table>
            <p v-else class="muted">只需公共参数。</p>

            <h4>返回 data{{ ep.responseIsList ? '（数组，每项如下）' : '' }}</h4>
            <el-table :data="ep.response" border class="block">
              <el-table-column prop="name" label="字段" width="170" />
              <el-table-column prop="type" label="类型" width="110" />
              <el-table-column prop="desc" label="说明" min-width="240" />
            </el-table>

            <ul v-if="ep.notes" class="notes">
              <li v-for="n in ep.notes" :key="n">{{ n }}</li>
            </ul>

            <h4>返回示例</h4>
            <pre class="code">{{ json(ep.example) }}</pre>
          </el-collapse-item>
        </el-collapse>
      </el-tab-pane>

      <el-tab-pane label="结果回调" name="callback">
        <ul class="notes">
          <li>订单成功、失败、已退款（售后确认未到账）时，平台向下单时传入的 <code>callback_url</code> 发 POST 请求，表单格式（<code>application/x-www-form-urlencoded</code>）。</li>
          <li>收到后请先验签（规则同请求签名，用你的 AppSecret），再按 <code>merchant_order_no</code> 更新订单；建议同时校验 <code>timestamp</code> 在 5 分钟以内。</li>
          <li>处理完成后响应内容为 <code>success</code>（纯文本）。其它响应或超时（5 秒）视为失败，按 1 分钟、5 分钟、15 分钟、1 小时、2 小时、6 小时重试 6 次，之后可在「订单列表」手动重推。</li>
          <li>同一订单可能收到多次通知（重试、手动重推、成功后又退款），请按订单状态做幂等处理。</li>
          <li>回调里不带卡密；卡密类卡券成功后请调订单查询接口获取。</li>
        </ul>

        <h4>回调参数</h4>
        <el-table :data="callbackFields" border class="block">
          <el-table-column prop="name" label="参数" width="170" />
          <el-table-column prop="type" label="类型" width="90" />
          <el-table-column label="必带" width="70">
            <template #default="{ row }">{{ row.required ? '是' : '否' }}</template>
          </el-table-column>
          <el-table-column prop="desc" label="说明" min-width="240" />
        </el-table>
      </el-tab-pane>

      <el-tab-pane label="错误码" name="codes">
        <ul class="notes">
          <li>鉴权类错误返回 HTTP 401 / 403 / 429，其余业务错误 HTTP 200，都以返回体里的 <code>code</code> 为准。</li>
          <li>「订单失败原因」类的码不会作为接口 <code>code</code> 返回，而是出现在订单的 <code>fail_code</code> 里。</li>
        </ul>
        <el-table v-loading="loadingCodes" :data="errorCodes" border class="block">
          <el-table-column prop="code" label="code" width="90" />
          <el-table-column prop="message" label="说明" min-width="260" />
          <el-table-column prop="http_status" label="HTTP 状态码" width="110" />
          <el-table-column label="用途" width="130">
            <template #default="{ row }">{{ row.order_failure ? '订单失败原因' : '接口返回' }}</template>
          </el-table-column>
        </el-table>
      </el-tab-pane>

      <el-tab-pane label="签名示例下载" name="examples">
        <p class="muted">每个示例都包含签名、回调验签和一个查询余额的调用示例，直接运行会在最后一行输出自检签名。</p>
        <el-table :data="examples" border class="block">
          <el-table-column prop="lang" label="语言" width="120" />
          <el-table-column prop="note" label="运行环境" min-width="200" />
          <el-table-column label="下载" width="200">
            <template #default="{ row }">
              <a :href="exampleUrl(row.file)" :download="row.file">{{ row.file }}</a>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>
    </el-tabs>
  </el-card>
</template>

<style scoped>
h4 {
  margin: 20px 0 8px;
}

.block {
  margin-bottom: 8px;
}

.steps,
.notes {
  margin: 8px 0 12px;
  padding-left: 20px;
  line-height: 1.9;
}

.muted {
  margin: 4px 0 12px;
  color: var(--el-text-color-secondary);
  font-size: 13px;
}

code {
  padding: 1px 4px;
  border-radius: 3px;
  background: var(--el-fill-color-light);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 12px;
}

.wrap {
  word-break: break-all;
}

.copy {
  margin-left: 8px;
}

.method {
  margin-right: 8px;
}

.ep-title {
  margin-left: 12px;
}

.code {
  overflow-x: auto;
  margin: 0;
  padding: 12px;
  border-radius: 4px;
  background: var(--el-fill-color-light);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 12px;
  line-height: 1.6;
}
</style>
