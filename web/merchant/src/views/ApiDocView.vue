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
  { name: 'business_line', type: 'string', desc: '业务线：recharge 话费，card 卡券，movie 电影票，express 快递' },
  { name: 'status', type: 'string', desc: '订单状态：processing 处理中，success 成功，failed 失败，cancelled 已取消（快递取消、电影票释放座位），refunded 已退款' },
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

const movieFields: Field[] = [
  ...orderFields,
  { name: 'movie.cinema_id / cinema_name', type: 'string', desc: '影院' },
  { name: 'movie.film_id / film_name', type: 'string', desc: '影片' },
  { name: 'movie.show_id / show_time', type: 'string', desc: '场次和开场时间' },
  { name: 'movie.area_id', type: 'string|null', desc: '分区，不分区为 null' },
  { name: 'movie.seats', type: 'array', desc: '座位：seat_code、row_label（排）、col_label（座）、love_status（0 普通，1 情侣座左，2 情侣座右）' },
  { name: 'movie.seat_count', type: 'int', desc: '张数' },
  { name: 'movie.unit_price', type: 'string', desc: '每张售价（元），sale_price = unit_price × 张数' },
  { name: 'movie.lock_expire_at', type: 'string', desc: '锁座有效期，到期前必须确认出票，否则座位自动释放并解冻' },
  { name: 'movie.confirmed_at', type: 'string|null', desc: '确认出票时间' },
  { name: 'movie.ticket_codes', type: 'array', desc: '取票码 / 验证码，出票成功后才有' },
]

const movieExample = {
  ...orderExample,
  order_no: 'M20260923100000123456',
  business_line: 'movie',
  sale_price: '80.00',
  frozen_amount: '80.00',
  movie: {
    cinema_id: '1001',
    cinema_name: '万达影城',
    film_id: 'F1',
    film_name: '长安三万里',
    show_id: 'S20260930193000',
    show_time: '2026-09-30 19:30:00',
    area_id: null,
    seats: [
      { seat_code: '1-3', row_label: '1', col_label: '3', love_status: 0 },
      { seat_code: '1-4', row_label: '1', col_label: '4', love_status: 0 },
    ],
    seat_count: 2,
    unit_price: '40.00',
    lock_expire_at: '2026-09-23 10:10:00',
    confirmed_at: null,
    ticket_codes: [],
  },
}

const expressFields: Field[] = [
  ...orderFields,
  { name: 'express.company_code', type: 'string', desc: '下单时传的快递渠道编号' },
  { name: 'express.company_name', type: 'string', desc: '快递公司名称' },
  { name: 'express.waybill_no', type: 'string|null', desc: '运单号，个别快递公司下单后稍晚才有' },
  { name: 'express.logistics_status', type: 'string', desc: '物流状态：pending_pickup 待揽收，in_transit 运输中，signed 已签收，rejected 拒收退回，cancelled 已取消' },
  { name: 'express.insured_amount', type: 'string|null', desc: '保价金额' },
  { name: 'express.signed_at', type: 'string|null', desc: '签收时间' },
  { name: 'express.fees', type: 'object|null', desc: '实际费用明细：freight 运费、insured_fee 保价费、material_fee 耗材费、reverse_fee 逆向费；快递公司扣费（订单成功）前为 null' },
  { name: 'express.fee_adjustments', type: 'array', desc: '扣费之后的费用调整记录：type（supplement 补扣 / refund 退回）、item（freight/insured/material/reverse）、amount、created_at' },
]

const expressExample = {
  ...orderExample,
  order_no: 'E20260923100000123456',
  business_line: 'express',
  sale_price: '14.50',
  frozen_amount: '14.50',
  express: {
    company_code: 'EX8b21d4e05fa7c396',
    company_name: '顺丰',
    waybill_no: 'SF1234567890',
    logistics_status: 'pending_pickup',
    insured_amount: null,
    signed_at: null,
    fees: null,
    fee_adjustments: [],
  },
}

const orderIdentParams: Field[] = [
  { name: 'order_no', type: 'string', desc: '平台订单号' },
  { name: 'merchant_order_no', type: 'string', desc: '你的订单号（两者必须传一个）' },
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
    title: '快递查价',
    method: 'POST',
    path: '/express/quote',
    desc: '按寄收件地址和重量查可用的快递公司和预估价格。只是查价，不产生订单、不冻结金额；下单时会按最新价格重新算，以下单返回的为准。',
    params: [
      { name: 'sender_province', type: 'string', required: true, desc: '寄件省' },
      { name: 'sender_city', type: 'string', required: true, desc: '寄件市' },
      { name: 'sender_district', type: 'string', required: true, desc: '寄件区县' },
      { name: 'sender_address', type: 'string', desc: '寄件详细地址，查价可不传' },
      { name: 'receiver_province', type: 'string', required: true, desc: '收件省' },
      { name: 'receiver_city', type: 'string', required: true, desc: '收件市' },
      { name: 'receiver_district', type: 'string', required: true, desc: '收件区县' },
      { name: 'receiver_address', type: 'string', desc: '收件详细地址，查价可不传' },
      { name: 'weight', type: 'int', required: true, desc: '重量（整数公斤），1 ~ 1000' },
      { name: 'length', type: 'int', desc: '长（厘米），三边要么都传要么都不传' },
      { name: 'width', type: 'int', desc: '宽（厘米）' },
      { name: 'height', type: 'int', desc: '高（厘米）' },
      { name: 'insured_amount', type: 'string', desc: '保价金额（元），传了就只返回支持保价的快递公司' },
    ],
    response: [
      { name: 'channel_code', type: 'string', desc: '快递渠道编号，下单时传它；同一个渠道的编号是固定的，可以存下来复用' },
      { name: 'company_name', type: 'string', desc: '快递公司名称' },
      { name: 'freight', type: 'string', desc: '运费（元）' },
      { name: 'insured_fee', type: 'string', desc: '保价费（元），不保价时为 0.00' },
      { name: 'material_fee', type: 'string', desc: '耗材费（元），揽收后可能变化' },
      { name: 'total_price', type: 'string', desc: '预估总价（元）= 运费 + 保价费 + 耗材费' },
      { name: 'billing_rule', type: 'string|null', desc: '计费说明（首重续重等）' },
      { name: 'allow_insured', type: 'bool', desc: '是否支持保价' },
      { name: 'appointment_times', type: 'array', desc: '可选的预约取件时间段，部分快递公司下单时必传' },
    ],
    responseIsList: true,
    example: {
      channels: [
        {
          channel_code: 'EX3f9a1c7b20e4d581',
          company_name: '中通',
          freight: '10.00',
          insured_fee: '0.00',
          material_fee: '0.00',
          total_price: '10.00',
          billing_rule: '首重 1kg，续重每 kg',
          allow_insured: false,
          appointment_times: [],
        },
        {
          channel_code: 'EX8b21d4e05fa7c396',
          company_name: '顺丰',
          freight: '17.00',
          insured_fee: '2.00',
          material_fee: '1.00',
          total_price: '20.00',
          billing_rule: '首重 1kg，续重每 kg',
          allow_insured: true,
          appointment_times: ['09:00-12:00', '14:00-18:00'],
        },
      ],
    },
    notes: [
      '返回的是预估价：快递按实际计费重量收费，最终以快递公司扣费时的实际费用结算，多退少补。',
      '按总价从低到高排序。返回空列表表示这个地址和重量暂时没有可用的快递公司；渠道查询失败会返回 42008。',
      '没开通快递业务线时返回 42007，去「服务开通」申请。',
    ],
  },
  {
    title: '电影票 - 城市 / 区县 / 影院',
    method: 'GET',
    path: '/movie/cities、/movie/regions、/movie/cinemas',
    desc: '城市列表（无参数）；区县列表（传 city_id）；影院列表（传 city_id，可选 region_id，分页 page / per_page，每页最多 100）。数据每天同步，影院变动会增量更新。',
    params: [
      { name: 'city_id', type: 'string', desc: '区县、影院列表必传' },
      { name: 'region_id', type: 'string', desc: '影院列表按区县筛选' },
      { name: 'page / per_page', type: 'int', desc: '影院列表分页' },
    ],
    response: [
      { name: 'cities[]', type: 'array', desc: 'city_id、city_name、first_letter、is_hot' },
      { name: 'regions[]', type: 'array', desc: 'region_id、region_name' },
      { name: 'data[] / total', type: 'array', desc: '影院：cinema_id、cinema_name、region_id、address、tel、longitude、latitude' },
    ],
    example: { cities: [{ city_id: '440300', city_name: '深圳', first_letter: 'S', is_hot: true }] },
  },
  {
    title: '电影票 - 影片 / 场次 / 座位',
    method: 'GET',
    path: '/movie/films、/movie/shows、/movie/seats',
    desc: '影片（传 city_id）、场次（传 cinema_id + film_id）、座位图（传 show_id）都是实时查询。场次价格已经是售价（每张）；分区场次按区给价。',
    params: [
      { name: 'city_id', type: 'string', desc: '影片列表必传' },
      { name: 'cinema_id / film_id', type: 'string', desc: '场次列表必传，用影院、影片接口返回的 ID' },
      { name: 'show_id', type: 'string', desc: '座位图必传；部分场次 ID 含特殊字符，传参时注意 URL 编码' },
    ],
    response: [
      { name: 'films[]', type: 'array', desc: 'film_id、film_name、attributes（海报、时长等展示信息）' },
      { name: 'shows[].price', type: 'string|null', desc: '不分区场次的每张售价；分区场次为 null，看 areas' },
      { name: 'shows[].areas[]', type: 'array', desc: '分区：area_id、area_name、price（每张售价）；分区之间不能混选' },
      { name: 'seats[]', type: 'array', desc: 'seat_code、row / col（座位图格子坐标，隔着过道会跳号）、row_label、col_label、area_id、love_status、available' },
    ],
    example: {
      shows: [
        { show_id: 'S20260930193000', cinema_id: '1001', film_id: 'F1', show_time: '2026-09-30 19:30:00', price: '40.00', areas: [], attributes: { hall_name: '1 号厅' } },
      ],
    },
    notes: [
      '查询失败（包括场次刚下架）返回 42011，请 30 秒左右后重试。',
      '选座规则（锁座时平台会校验，不满足直接拒绝）：一单 1 ~ 4 个座位；不能跨分区；情侣座必须左右成对购买；同一排被过道隔开的一段超过 5 个座位时，所选座位左右都不能只剩 1 个空座。',
    ],
  },
  {
    title: '电影票 - 锁座',
    method: 'POST',
    path: '/movie/lock',
    desc: '锁定座位并按最新场次价格冻结金额（每张售价 × 张数），不采用你传的任何价格。锁座有效期 10 分钟，期间调「确认出票」；放弃就调「释放座位」，到期未确认会自动释放并解冻。',
    params: [
      { name: 'merchant_order_no', type: 'string', required: true, desc: '你的订单号，在你的账户下唯一' },
      { name: 'callback_url', type: 'string', required: true, desc: '结果回调地址，只支持 http/https 公网地址' },
      { name: 'cinema_id', type: 'string', required: true, desc: '影院 ID' },
      { name: 'film_id', type: 'string', required: true, desc: '影片 ID' },
      { name: 'show_id', type: 'string', required: true, desc: '场次 ID' },
      { name: 'seat_codes', type: 'string', required: true, desc: '座位编码，英文逗号分隔，如 1-3,1-4' },
      { name: 'mobile', type: 'string', required: true, desc: '取票手机号（11 位手机号）' },
    ],
    response: movieFields,
    example: movieExample,
    notes: [
      '选座不合法返回 41001（message 说明原因），场次已停售或所选分区不可售返回 42012，都不会冻结金额。',
      '场次价格刚好变动时锁座会失败：status = failed、fail_code = 43004、全额解冻，请 2~3 分钟后重新查询场次再锁。',
      '同一个 merchant_order_no 重复提交不会重复锁座；需要先在「服务开通」开通电影票业务线，否则返回 42007。',
    ],
  },
  {
    title: '电影票 - 确认出票',
    method: 'POST',
    path: '/movie/confirm',
    desc: '终端用户付款后调用，只能在锁座有效期内确认一次（重复调用返回当前状态）。出票成功后扣款并回调你，取票码在 movie.ticket_codes。',
    params: orderIdentParams,
    response: movieFields,
    example: { ...movieExample, status: 'success', deducted_amount: '80.00', completed_at: '2026-09-23 10:05:12', movie: { ...movieExample.movie, confirmed_at: '2026-09-23 10:05:00', ticket_codes: [{ code: '88886666' }] } },
    notes: [
      '多数情况下几秒内出票，接口返回时可能已经是 success；仍是 processing 时以回调或订单查询为准。',
      '锁座已超时返回 42013，请重新锁座。出票失败会全额解冻，status = failed。',
      '出票成功后不支持退票、改签。影院改票根时会再回调一次新的取票码，请以最新一次为准。',
    ],
  },
  {
    title: '电影票 - 释放座位',
    method: 'POST',
    path: '/movie/release',
    desc: '放弃已锁的座位，订单变为 cancelled 并全额解冻。已确认出票的订单不能释放（42010）。',
    params: orderIdentParams,
    response: movieFields,
    example: { ...movieExample, status: 'cancelled' },
  },
  {
    title: '快递下单',
    method: 'POST',
    path: '/express/order',
    desc: '选定查价返回的快递公司下单，快递员上门取件。下单时会按最新价格重新计算并冻结预估费用，不采用你传的任何金额。',
    params: [
      { name: 'merchant_order_no', type: 'string', required: true, desc: '你的订单号，在你的账户下唯一' },
      { name: 'channel_code', type: 'string', required: true, desc: '查价返回的 channel_code' },
      { name: 'callback_url', type: 'string', required: true, desc: '结果回调地址，只支持 http/https 公网地址' },
      { name: 'sender_name', type: 'string', required: true, desc: '寄件人姓名' },
      { name: 'sender_mobile', type: 'string', required: true, desc: '寄件人手机或座机' },
      { name: 'sender_province / sender_city / sender_district', type: 'string', required: true, desc: '寄件省、市、区县' },
      { name: 'sender_address', type: 'string', required: true, desc: '寄件详细地址' },
      { name: 'receiver_name', type: 'string', required: true, desc: '收件人姓名' },
      { name: 'receiver_mobile', type: 'string', required: true, desc: '收件人手机或座机' },
      { name: 'receiver_province / receiver_city / receiver_district', type: 'string', required: true, desc: '收件省、市、区县' },
      { name: 'receiver_address', type: 'string', required: true, desc: '收件详细地址' },
      { name: 'item_name', type: 'string', required: true, desc: '物品名称' },
      { name: 'weight', type: 'int', required: true, desc: '重量（整数公斤），1 ~ 1000' },
      { name: 'length / width / height', type: 'int', desc: '长宽高（厘米），要么都传要么都不传' },
      { name: 'insured_amount', type: 'string', desc: '保价金额（元），所选快递公司必须支持保价' },
      { name: 'appointment_time', type: 'string', desc: '预约取件时间段，必须是查价返回的 appointment_times 之一；部分快递公司必传' },
    ],
    response: expressFields,
    example: expressExample,
    notes: [
      'code = 0 表示快递公司已受理，订单状态为处理中；快递公司按实际计费重量扣费后订单变为成功，并回调通知你。',
      '冻结金额先按预估价，快递公司受理后按它确认的运费重算（多退少补）；扣费时按实际费用结算：比冻结的多就从可用余额补扣，少就解冻差额。',
      '扣费之后到签收前还可能产生耗材费、保价费、逆向费（拒收退回）或重量核实退回运费，每次都会补扣或退回并回调你，明细见订单查询的 express.fee_adjustments。补扣可能让可用余额变成负数。',
      '所选快递公司查不到了（渠道下线、地址或重量变了、要保价但不支持）返回 42009，请重新查价。',
      '同一个 merchant_order_no 重复提交不会重复下单；需要先在「服务开通」开通快递业务线，否则返回 42007。',
    ],
  },
  {
    title: '快递取消',
    method: 'POST',
    path: '/express/cancel',
    desc: '取消快递订单，全额解冻。只有待揽收的订单能取消。',
    params: orderIdentParams,
    response: expressFields,
    example: { ...expressExample, status: 'cancelled', frozen_amount: '14.50', express: { ...expressExample.express, logistics_status: 'cancelled' } },
    notes: [
      '已揽收、已取消或者该快递公司不支持取消时返回 42010。',
      '个别情况下取消已提交但结果稍后才确认，此时返回 status = processing，确认后会回调你。',
    ],
  },
  {
    title: '快递轨迹查询',
    method: 'GET',
    path: '/express/trace',
    desc: '查询快递的物流轨迹。',
    params: orderIdentParams,
    response: [
      { name: 'order_no', type: 'string', desc: '平台订单号' },
      { name: 'waybill_no', type: 'string|null', desc: '运单号' },
      { name: 'logistics_status', type: 'string', desc: '物流状态，取值同快递下单' },
      { name: 'traces', type: 'array', desc: '轨迹列表：time 时间、description 描述' },
    ],
    example: {
      order_no: 'E20260923100000123456',
      waybill_no: 'SF1234567890',
      logistics_status: 'in_transit',
      traces: [
        { time: '2026-09-23 15:02:11', description: '快递员已揽收' },
        { time: '2026-09-23 21:40:05', description: '已到达深圳转运中心' },
      ],
    },
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
      { name: 'express', type: 'object', desc: '快递订单的运单号、物流状态和费用明细，字段见「快递下单」' },
      { name: 'movie', type: 'object', desc: '电影票订单的场次、座位、每张售价、锁座有效期、取票码，字段见「电影票 - 锁座」' },
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
  { name: 'status', type: 'string', required: true, desc: 'success 成功，failed 失败，cancelled 已取消（快递），refunded 已退款' },
  { name: 'completed_at', type: 'string', desc: '完成时间，没有时不带这个字段' },
  { name: 'fail_code', type: 'int', desc: '失败原因码，只在失败时带' },
  { name: 'fail_reason', type: 'string', desc: '失败原因，只在失败时带' },
  { name: 'waybill_no', type: 'string', desc: '快递：运单号' },
  { name: 'logistics_status', type: 'string', desc: '快递：物流状态' },
  { name: 'deducted_amount', type: 'string', desc: '快递：目前实际扣款合计（元），扣费后才有' },
  { name: 'freight / insured_fee / material_fee / reverse_fee', type: 'string', desc: '快递：实际运费、保价费、耗材费、逆向费（元），扣费后才有' },
  { name: 'ticket_codes', type: 'string', desc: '电影票：取票码列表（JSON 字符串），出票成功后才有' },
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
          <li>订单成功、失败、已取消、已退款（售后确认未到账），以及快递扣费后的费用调整时，平台向下单时传入的 <code>callback_url</code> 发 POST 请求，表单格式（<code>application/x-www-form-urlencoded</code>）。</li>
          <li>收到后请先验签（规则同请求签名，用你的 AppSecret），再按 <code>merchant_order_no</code> 更新订单；建议同时校验 <code>timestamp</code> 在 5 分钟以内。</li>
          <li>处理完成后响应内容为 <code>success</code>（纯文本）。其它响应或超时（5 秒）视为失败，按 1 分钟、5 分钟、15 分钟、1 小时、2 小时、6 小时重试 6 次，之后可在「订单列表」手动重推。</li>
          <li>同一订单可能收到多次通知（重试、手动重推、成功后又退款、快递费用调整），请按订单状态做幂等处理；快递以最新一次的 <code>deducted_amount</code> 为准，电影票以最新一次的 <code>ticket_codes</code> 为准。</li>
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
