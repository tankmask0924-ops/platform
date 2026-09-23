import type { ExpressDetail, MovieDetail, Paged } from '@platform/shared'
import { http } from './http'

type Query = Record<string, unknown>
type Ok = { success: boolean }

/** 商户：App\Controller\Admin\MerchantController */
export interface Merchant {
  id: number
  type: string
  phone: string | null
  email: string | null
  status: string
  level_id: number | null
  created_at: string | null
}

export interface Qualification {
  type: string
  company_name: string | null
  business_license_no: string | null
  business_license_image: string | null
  legal_person_name: string | null
  contact_name: string | null
  contact_phone: string | null
  id_card_name: string | null
  id_card_no: string | null
  id_card_images: string[] | null
  status: string
  reject_reason: string | null
  submitted_at: string | null
  reviewed_at: string | null
}

/** 历次资质提交（驳回后重新提交会有多条），最新在前 */
export interface QualificationHistoryItem {
  id: number
  type: string
  status: string
  reject_reason: string | null
  submitted_at: string | null
  reviewed_at: string | null
}

export interface RateLimit {
  limit_per_second: number
  is_custom: boolean
}

export interface MerchantDetail extends Merchant {
  available_balance: string
  frozen_balance: string
  debt_since: string | null
  qualification: Qualification | null
  qualification_history: QualificationHistoryItem[]
  rate_limit: RateLimit
  /** 四条业务线各自的开通状态，没申请过 status 为 null */
  subscriptions: { business_line: string; available: boolean; status: string | null }[]
}

export interface BalanceLog {
  id: number
  merchant_id: number
  type: string
  amount: string
  available_before: string
  available_after: string
  frozen_before: string
  frozen_after: string
  order_id: number | null
  /** 关联订单（冻结、扣款、退款、返佣、快递理赔等），没有关联订单为 null */
  order_no: string | null
  merchant_order_no: string | null
  business_line: string | null
  rebate_id: number | null
  reason: string | null
  operator_id: number | null
  created_at: string | null
}

export const merchantApi = {
  list: (params: Query) => http.get<Paged<Merchant>>('/admin/merchants', params),
  detail: (id: number) => http.get<MerchantDetail>(`/admin/merchants/${id}`),
  approve: (id: number, levelId: number) => http.post<Ok>(`/admin/merchants/${id}/approve`, { level_id: levelId }),
  reject: (id: number, reason: string) => http.post<Ok>(`/admin/merchants/${id}/reject`, { reason }),
  setStatus: (id: number, status: 'active' | 'disabled') => http.post<Ok>(`/admin/merchants/${id}/status`, { status }),
  setLevel: (id: number, levelId: number) => http.put<Ok>(`/admin/merchants/${id}/level`, { level_id: levelId }),
  setRateLimit: (id: number, limit: number) =>
    http.put<RateLimit>(`/admin/merchants/${id}/rate-limit`, { limit_per_second: limit }),
  resetRateLimit: (id: number) => http.delete<RateLimit>(`/admin/merchants/${id}/rate-limit`),
  adjustBalance: (id: number, amount: string, reason: string) =>
    http.post<Ok>(`/admin/merchants/${id}/balance-adjustments`, { amount, reason }),
  balanceLogs: (id: number, params: Query) => http.get<Paged<BalanceLog>>(`/admin/merchants/${id}/balance-logs`, params),
}

/** 充值审核：App\Controller\Admin\RechargeRequestController */
export interface RechargeRequest {
  id: number
  merchant_id: number
  amount: string
  proof_image: string
  transfer_no: string | null
  status: string
  reject_reason: string | null
  reviewed_by: number | null
  reviewed_at: string | null
  created_at: string | null
}

export const rechargeApi = {
  list: (params: Query) => http.get<Paged<RechargeRequest>>('/admin/recharge-requests', params),
  approve: (id: number) => http.post<Ok>(`/admin/recharge-requests/${id}/approve`),
  reject: (id: number, reason: string) => http.post<Ok>(`/admin/recharge-requests/${id}/reject`, { reason }),
}

/** 商户等级：App\Controller\Admin\MerchantLevelController */
export interface MerchantLevel {
  id: number
  name: string
  remark: string | null
  created_at: string | null
  updated_at: string | null
}

export type BusinessLine = 'recharge' | 'card' | 'movie' | 'express'

export interface MerchantLevelDetail extends MerchantLevel {
  /** null = 未设置 */
  rates: Record<BusinessLine, string | null>
}

export const levelApi = {
  list: () => http.get<{ data: MerchantLevel[]; total: number }>('/admin/merchant-levels'),
  detail: (id: number) => http.get<MerchantLevelDetail>(`/admin/merchant-levels/${id}`),
  create: (data: { name: string; remark: string }) => http.post<MerchantLevelDetail>('/admin/merchant-levels', data),
  update: (id: number, data: { name: string; remark: string }) =>
    http.put<MerchantLevelDetail>(`/admin/merchant-levels/${id}`, data),
  setRate: (id: number, businessLine: BusinessLine, rate: string) =>
    http.put(`/admin/merchant-levels/${id}/rates/${businessLine}`, { rebate_rate: rate }),
}

/** 本地商品：App\Controller\Admin\ProductController */
export interface Product {
  id: number
  business_line: 'recharge' | 'card'
  name: string
  operator: string | null
  province: string | null
  charge_speed: string | null
  card_type: string | null
  face_value: string
  sale_price: string
  rebate_amount: string
  applicable_region: string | null
  status: string
  created_at: string | null
  updated_at: string | null
}

export interface ProductDetail extends Product {
  level_rebates: { level_id: number; level_name: string | null; rebate_rate: string }[]
}

export type ProductForm = Partial<Omit<Product, 'id' | 'created_at' | 'updated_at'>>

export const productApi = {
  list: (params: Query) => http.get<Paged<Product>>('/admin/products', params),
  detail: (id: number) => http.get<ProductDetail>(`/admin/products/${id}`),
  create: (data: ProductForm) => http.post<ProductDetail>('/admin/products', data),
  update: (id: number, data: ProductForm) => http.put<ProductDetail>(`/admin/products/${id}`, data),
  setStatus: (id: number, status: string) => http.post<Ok>(`/admin/products/${id}/status`, { status }),
  setLevelRebate: (id: number, levelId: number, rate: string) =>
    http.put(`/admin/products/${id}/level-rebates/${levelId}`, { rebate_rate: rate }),
  deleteLevelRebate: (id: number, levelId: number) => http.delete<Ok>(`/admin/products/${id}/level-rebates/${levelId}`),
}

/** 商品映射：App\Controller\Admin\ProductMappingController */
export interface ProductMapping {
  id: number
  product_id: number
  supplier_id: number
  supplier_name: string | null
  supplier_code: string | null
  supplier_product_code: string
  cost_price: string
  priority: number
  status: string
  stock: number | null
  param_mapping: Record<string, unknown> | null
  sale_restrictions: Record<string, unknown> | null
  synced_at: string | null
  created_at: string | null
  updated_at: string | null
}

export interface MappingForm {
  product_id: number
  supplier_id: number
  supplier_product_code: string
  cost_price: string
  priority: number
  status: string
  stock: number | null
}

export const mappingApi = {
  list: (productId: number) => http.get<ProductMapping[]>('/admin/product-mappings', { product_id: productId }),
  create: (data: MappingForm) => http.post('/admin/product-mappings', data),
  update: (id: number, data: { supplier_product_code: string; stock: number | null }) =>
    http.put(`/admin/product-mappings/${id}`, data),
  setCostPrice: (id: number, costPrice: string) => http.post<Ok>(`/admin/product-mappings/${id}/cost-price`, { cost_price: costPrice }),
  setPriority: (id: number, priority: number) => http.post<Ok>(`/admin/product-mappings/${id}/priority`, { priority }),
  setStatus: (id: number, status: string) => http.post<Ok>(`/admin/product-mappings/${id}/status`, { status }),
}

/** 供应商：App\Controller\Admin\SupplierController */
export interface Supplier {
  id: number
  name: string
  code: string
  business_line: string
  driver: string
  status: string
  balance: string | null
  balance_synced_at: string | null
  balance_warning_threshold: string | null
  created_at: string | null
}

export interface SupplierDetail extends Supplier {
  /** 敏感字段已打码为 ****** */
  /** 密钥字段已脱敏成 ****** ；config 解不开时为 null，见 config_unreadable */
  config: Record<string, unknown> | null
  contact: string | null
  settlement_info: string | null
  remark: string | null
  updated_at: string | null
  /** 订单回调地址，卡速售下单时自动带上 */
  order_notify_url: string
  /** 商品变更通知地址，需要在供应商后台配置 */
  goods_notify_url: string
  cinema_notify_url: string
  /** 没配 SUPPLIER_NOTIFY_BASE_URL 时为 false，回调地址是本机地址，供应商访问不到 */
  notify_base_url_configured: boolean
  /** 接口配置用当前密钥解不开（换过密钥、或历史脏数据），只能整体重新填写 */
  config_unreadable: boolean
}

export interface SupplierCallLog {
  id: number
  action: string
  order_id: number | null
  order_no: string | null
  /** 卡号卡密已打码 */
  request: Record<string, unknown>
  response: Record<string, unknown> | null
  http_status: number | null
  duration_ms: number | null
  created_at: string | null
}

/** 供应商统计：App\Service\Admin\SupplierStatsService，按尝试统计（失败切换的那家也算它头上） */
export interface SupplierStatsRow {
  /** 按天是日期，按商品是商品名；汇总行是 null */
  label: string | null
  product_id: number | null
  order_count: number
  success_count: number
  failed_count: number
  /** 处理中 / 结果未知，不计入成功率 */
  pending_count: number
  /** 百分比，没有已出结果的尝试时为 null */
  success_rate: number | null
  /** 平均到账时长，秒，没有成功的尝试时为 null */
  avg_delivery_seconds: number | null
  cost_total: string
  /** 话费、卡券没有供应商返佣，固定 0.00，电影票/快递三期才有 */
  supplier_rebate_total: string
}

export interface SupplierStats {
  group_by: 'day' | 'product'
  created_from: string
  created_to: string
  summary: SupplierStatsRow
  data: SupplierStatsRow[]
}

/** 熔断状态：App\Service\Admin\CircuitBreakerAdminService（requirements.md 6.6） */
export interface CircuitBreakerRow {
  id: number
  /** null 表示整个供应商 */
  product_id: number | null
  product_name: string | null
  /** 按 paused_until 实时判断的状态，展示用这个 */
  status: 'paused' | 'normal'
  /** 库里存的状态；到期但定时任务还没写回时会是 paused */
  stored_status: string
  /** null 且 status=paused 表示无限期暂停，只能人工恢复 */
  paused_until: string | null
  /** true = 运营手动暂停，false = 自动熔断 */
  manual: boolean
  triggered_reason: string | null
  updated_at: string | null
}

export interface CircuitBreakerStatus {
  thresholds: { window_minutes: number; min_orders: number; fail_rate_percent: string; pause_minutes: number }
  /** 整个供应商此刻是否被熔断（不看单个商品的行） */
  supplier_paused: boolean
  data: CircuitBreakerRow[]
  /** 这家供应商映射了哪些商品，给"只熔断某个商品"的下拉用 */
  mapped_products: { id: number; name: string }[]
}

export interface SupplierForm {
  name?: string
  code?: string
  business_line?: string
  driver?: string
  status?: string
  config?: Record<string, string>
  balance_warning_threshold?: string | null
  contact?: string
  settlement_info?: string
  remark?: string
}

export const supplierApi = {
  list: (params: Query) => http.get<Paged<Supplier>>('/admin/suppliers', params),
  detail: (id: number) => http.get<SupplierDetail>(`/admin/suppliers/${id}`),
  create: (data: SupplierForm) => http.post<SupplierDetail>('/admin/suppliers', data),
  update: (id: number, data: SupplierForm) => http.put<SupplierDetail>(`/admin/suppliers/${id}`, data),
  setStatus: (id: number, status: string) => http.post<Ok>(`/admin/suppliers/${id}/status`, { status }),
  refreshBalance: (id: number) => http.post<SupplierDetail>(`/admin/suppliers/${id}/balance/refresh`),
  syncProducts: (id: number) => http.post<Ok>(`/admin/suppliers/${id}/product-sync`),
  callLogs: (id: number, params: Query) => http.get<Paged<SupplierCallLog>>(`/admin/suppliers/${id}/call-logs`, params),
  stats: (id: number, params: Query) => http.get<SupplierStats>(`/admin/suppliers/${id}/stats`, params),
  circuitBreakers: (id: number) => http.get<CircuitBreakerStatus>(`/admin/suppliers/${id}/circuit-breakers`),
  pauseCircuitBreaker: (id: number, data: Query) => http.post<CircuitBreakerStatus>(`/admin/suppliers/${id}/circuit-breakers/pause`, data),
  resumeCircuitBreaker: (id: number, data: Query) => http.post<CircuitBreakerStatus>(`/admin/suppliers/${id}/circuit-breakers/resume`, data),
}

/** 订单：App\Controller\Admin\OrderController */
export interface Order {
  id: number
  order_no: string
  merchant_id: number
  merchant_order_no: string
  business_line: string
  status: string
  sale_price: string
  cost_price: string | null
  frozen_amount: string
  deducted_amount: string | null
  refunded_amount: string
  supplier_id: number | null
  supplier_order_no: string | null
  fail_reason: string | null
  callback_url: string | null
  created_at: string | null
  completed_at: string | null
  finished_at: string | null
}

export interface OrderDetail extends Order {
  supplier_name: string | null
  recharge: { product_id: number; recharge_account: string | null; rebate_amount: string | null; has_card_secret: boolean } | null
  express: ExpressDetail | null
  movie: MovieDetail | null
  /** 快递工单（只有快递订单有） */
  workorders: ExpressWorkorder[]
  attempts: {
    attempt_no: number
    supplier_id: number
    supplier_name: string | null
    result: string | null
    fail_reason: string | null
    request_snapshot: unknown
    response_snapshot: unknown
    created_at: string | null
    updated_at: string | null
  }[]
  balance_logs: { type: string; amount: string; available_after: string; frozen_after: string; created_at: string | null }[]
  notify_logs: {
    attempt_no: number
    url: string
    http_status: number | null
    success: boolean
    response_body: string | null
    created_at: string | null
  }[]
  rebate: { amount: string; status: string; rebate_rate: string; due_at: string | null; settled_at: string | null } | null
  operation_logs: { admin_user_id: number; action: string; before: unknown; after: unknown; created_at: string | null }[]
}

export interface QuerySupplierResult {
  result: string
  supplier_order_no: string | null
  fail_reason: string | null
  order: Order
  /** 只有成功订单查询时才有：refunded 已自动全额退款 / partial 部分退款已告警 / none 没有退款 */
  refund_check?: 'refunded' | 'partial' | 'none'
}

export const orderApi = {
  list: (params: Query) => http.get<Paged<Order>>('/admin/orders', params),
  detail: (id: number) => http.get<OrderDetail>(`/admin/orders/${id}`),
  resolve: (id: number, data: { result: 'success' | 'failed'; remark: string; supplier_order_no?: string }) =>
    http.post<Order>(`/admin/orders/${id}/resolve`, data),
  querySupplier: (id: number) => http.post<QuerySupplierResult>(`/admin/orders/${id}/query-supplier`),
  cancelAtSupplier: (id: number) => http.post<{ accepted: boolean; message: string }>(`/admin/orders/${id}/cancel-supplier`),
  partialRefund: (id: number, data: { amount: string; remark: string }) => http.post<Order>(`/admin/orders/${id}/partial-refund`, data),
  submitWorkorder: (id: number, data: { type: string; content: string }) =>
    http.post<ExpressWorkorder>(`/admin/orders/${id}/workorders`, data),
  renotify: (id: number) => http.post<Ok>(`/admin/orders/${id}/renotify`),
}

/** 售后争议：App\Controller\Admin\DisputeController */
export interface Dispute {
  id: number
  merchant_id: number
  order_id: number
  order_no: string | null
  business_line: string | null
  order_status: string | null
  sale_price: string | null
  deducted_amount: string | null
  refunded_amount: string | null
  completed_at: string | null
  status: string
  result_remark: string | null
  evidence: string[] | null
  handler_id: number | null
  submitted_at: string | null
  resolved_at: string | null
  /** 提交给卡速售售后的进展，没提交过是 null */
  supplier_aftersale: {
    supplier_id: number | null
    aftersale_no: string | null
    /** processing / completed / terminated */
    status: string | null
    reply: string | null
    submitted_at: string
    updated_at: string | null
  } | null
}

export interface DisputeDetail extends Dispute {
  rebate: { amount: string; status: string; due_at: string | null } | null
}

export const disputeApi = {
  list: (params: Query) => http.get<Paged<Dispute>>('/admin/disputes', params),
  detail: (id: number) => http.get<DisputeDetail>(`/admin/disputes/${id}`),
  reject: (id: number, remark: string, evidence: string[]) =>
    http.post<Dispute>(`/admin/disputes/${id}/reject`, { remark, evidence }),
  confirm: (id: number, remark: string, evidence: string[]) =>
    http.post<Dispute>(`/admin/disputes/${id}/confirm`, { remark, evidence }),
  submitSupplierAftersale: (id: number, content: string, images: string[]) =>
    http.post<DisputeDetail>(`/admin/disputes/${id}/supplier-aftersale`, { content, images }),
}

/** 商户返佣明细：App\Controller\Admin\RebateController */
export interface Rebate {
  id: number
  order_id: number
  order_no: string | null
  merchant_order_no: string | null
  merchant_id: number
  merchant_phone: string | null
  merchant_email: string | null
  business_line: string
  sale_price: string | null
  level_id: number
  level_name: string | null
  rebate_base: string
  rebate_base_source: string
  rebate_rate: string
  rebate_rate_source: string
  amount: string
  status: string
  order_completed_at: string | null
  due_at: string | null
  settled_at: string | null
  voided_at: string | null
  clawed_back_at: string | null
  created_at: string | null
}

export interface RebateList extends Paged<Rebate> {
  /** 按当前筛选条件、按状态汇总的笔数和金额 */
  summary: Record<string, { count: number; amount: string }>
  /** 当前的返佣固定期限（天） */
  due_period_days: number
}

/** 供应商返佣明细（电影票、快递的成功订单）：App\Service\Admin\SupplierRebateAdminService */
export interface SupplierRebate {
  order_id: number
  order_no: string
  business_line: string
  merchant_id: number
  merchant_contact: string | null
  supplier_id: number | null
  supplier_name: string | null
  sale_price: string
  cost_price: string
  completed_at: string | null
  /** null = 供应商还没给 */
  supplier_rebate: string | null
  merchant_rebate: string | null
  merchant_rebate_status: string | null
  merchant_rebate_rate: string | null
  /** 供应商返佣 − 商户返佣（作废、已扣回的商户返佣不减） */
  rebate_balance: string
}

export interface SupplierRebateList extends Paged<SupplierRebate> {
  summary: {
    count: number
    returned_count: number
    missing_count: number
    supplier_rebate: string
    merchant_rebate: string
    rebate_balance: string
  }
}

export const rebateApi = {
  list: (params: Query) => http.get<RebateList>('/admin/rebates', params),
  supplierList: (params: Query) => http.get<SupplierRebateList>('/admin/rebates/supplier', params),
}

/** 服务开通审核：App\Controller\Admin\SubscriptionController */
export interface Subscription {
  id: number
  merchant_id: number
  merchant_name: string | null
  merchant_type: string | null
  merchant_phone: string | null
  merchant_email: string | null
  merchant_status: string | null
  business_line: string
  status: string
  reject_reason: string | null
  applied_at: string | null
  reviewed_by: number | null
  reviewed_at: string | null
}

export const subscriptionApi = {
  list: (params: Query) => http.get<Paged<Subscription>>('/admin/subscriptions', params),
  approve: (id: number) => http.post<Ok>(`/admin/subscriptions/${id}/approve`),
  reject: (id: number, reason: string) => http.post<Ok>(`/admin/subscriptions/${id}/reject`, { reason }),
}

/** 告警：App\Controller\Admin\AlertController（requirements.md 8.3） */
export interface Alert {
  id: number
  type: string
  level: 'warning' | 'critical'
  /** supplier/product/merchant/order，null 表示全局告警 */
  related_type: string | null
  related_id: number | null
  message: string
  status: 'open' | 'resolved' | 'ignored'
  /** 同一条告警重复触发的次数 */
  occurrence_count: number
  /** 处理人姓名 */
  resolved_by: string | null
  resolved_at: string | null
  triggered_at: string | null
  created_at: string | null
}

export type AlertList = Paged<Alert> & { open_count: number }

export const alertApi = {
  list: (params: Query) => http.get<AlertList>('/admin/alerts', params),
  resolve: (id: number, params: Query) => http.post<AlertList>(`/admin/alerts/${id}/resolve`, params),
  ignore: (id: number, params: Query) => http.post<AlertList>(`/admin/alerts/${id}/ignore`, params),
}

/** 对账差异：App\Controller\Admin\ReconciliationController（requirements.md 8.3） */
export interface ReconciliationDiff {
  id: number
  /** order 订单对账 / rebate 返佣对账（电影票的供应商返佣） */
  type: string
  order_id: number
  order_no: string | null
  supplier_id: number
  supplier_name: string | null
  /** 批次日期，对的是它前一天完成的订单 */
  reconciliation_date: string | null
  /** status / cost_price / rebate_amount */
  field: string
  platform_value: string
  supplier_value: string
  /** 金额类差异的差额（平台 - 供应商），状态差异为 null */
  diff_amount: string | null
  status: 'open' | 'resolved' | 'ignored'
  /** 处理人姓名 */
  resolved_by: string | null
  resolved_at: string | null
  remark: string | null
  created_at: string | null
}

export type ReconciliationList = Paged<ReconciliationDiff> & { open_count: number }

/** 跑一个批次的汇总 */
export interface ReconciliationRunSummary {
  type: string
  reconciliation_date: string
  order_date: string
  /** 实际比对过的订单数 */
  checked: number
  /** 没能拿到供应商记录、这次没对成的订单数 */
  unreachable: number
  diff_count: number
  /** 比对了供应商返佣的电影票订单数 / 返佣差异条数 */
  rebate_checked: number
  rebate_diff_count: number
}

export const reconciliationApi = {
  list: (params: Query) => http.get<ReconciliationList>('/admin/reconciliations', params),
  run: (date: string) => http.post<ReconciliationRunSummary>('/admin/reconciliations/run', { date }),
  resolve: (id: number, params: Query) => http.post<ReconciliationList>(`/admin/reconciliations/${id}/resolve`, params),
  ignore: (id: number, params: Query) => http.post<ReconciliationList>(`/admin/reconciliations/${id}/ignore`, params),
}

/** 财务报表：App\Controller\Admin\ReportController（requirements.md 8.3） */
export interface ProfitRow {
  /** 分组键：日期 / 商户 id / 等级 id / 业务线编码 / 供应商 id；汇总行为 null */
  key: string | null
  /** 商户（手机号或邮箱）、等级名、供应商名；按天和按业务线分组时为 null，由前端转中文 */
  label: string | null
  orders: number
  sale_total: string
  cost_total: string
  /** 售价合计 − 成本合计 */
  gross_profit: string
  merchant_rebate: string
  /** 话费、卡券没有供应商返佣，恒为 0.00（电影票、快递三期） */
  supplier_rebate: string
  /** 供应商返佣 − 商户返佣，只有支出时是负数 */
  rebate_balance: string
  /** 订单毛利 + 返佣收支 */
  total_profit: string
  /** 已退款订单不计毛利，单独列出来 */
  refunded_count: number
  refunded_amount: string
}

export type ProfitGroupBy = 'day' | 'merchant' | 'level' | 'business_line' | 'supplier'

export interface ProfitReport {
  group_by: ProfitGroupBy
  from: string
  to: string
  merchant_id: number | null
  summary: ProfitRow
  data: ProfitRow[]
}

export interface BalanceFlowRow {
  /** merchant_balance_logs.type */
  type: string
  count: number
  /** 调账这一行是带符号的净额，其余类型方向由类型本身表达 */
  amount: string
}

export interface BalanceFlowReport {
  from: string
  to: string
  merchant_id: number | null
  data: BalanceFlowRow[]
  total_count: number
}

export const reportApi = {
  profit: (params: Query) => http.get<ProfitReport>('/admin/reports/profit', params),
  balanceFlows: (params: Query) => http.get<BalanceFlowReport>('/admin/reports/balance-flows', params),
}

/** 加价规则：App\Controller\Admin\PricingRuleController（requirements.md 5.1、8.3「价格设置」） */
export interface PricingRule {
  /** movie 电影票 / express 快递；话费、卡券的售价在商品上直接设置 */
  business_line: string
  /** fixed 固定金额 / percentage 百分比；没设置过是 null */
  rule_type: string | null
  /** fixed 时是元（两位小数），percentage 时是比例（四位小数，0.0500 = 5%） */
  value: string | null
  /** 最后修改人姓名 */
  updated_by: string | null
  updated_at: string | null
}

export interface PricingPreview {
  business_line: string
  rule_type: string
  value: string
  cost: string
  sale_price: string
  /** 售价 − 成本 */
  gross_profit: string
}

export const pricingRuleApi = {
  list: () => http.get<{ data: PricingRule[] }>('/admin/pricing-rules'),
  update: (businessLine: string, payload: Query) =>
    http.put<{ data: PricingRule[] }>(`/admin/pricing-rules/${businessLine}`, payload),
  preview: (params: Query) => http.get<PricingPreview>('/admin/pricing-rules/preview', params),
}

/** 快递工单（客服代提交）：App\Service\Admin\ExpressWorkorderAdminService */
export interface ExpressWorkorder {
  id: number
  order_id: number
  order_no: string | null
  type: string
  status: string
  content: string
  supplier_workorder_no: string | null
  submitted_by: number
  /** 供应商回调里说的（回调没有签名，未验证） */
  supplier_reply: string | null
  supplier_amount: string | null
  supplier_replied_at: string | null
  result_remark: string | null
  /** 核实后调账给商户的理赔金额 */
  claim_amount: string | null
  resolved_by: number | null
  resolved_at: string | null
  created_at: string | null
}

export interface ExpressWorkorderList extends Paged<ExpressWorkorder> {
  processing_count: number
}

export const workorderApi = {
  list: (params: Query) => http.get<ExpressWorkorderList>('/admin/express-workorders', params),
  complete: (id: number, data: { result_remark: string; claim_amount?: string }) =>
    http.post<ExpressWorkorder>(`/admin/express-workorders/${id}/complete`, data),
  reject: (id: number, resultRemark: string) =>
    http.post<ExpressWorkorder>(`/admin/express-workorders/${id}/reject`, { result_remark: resultRemark }),
}
