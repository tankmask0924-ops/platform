import type { Paged } from '@platform/shared'
import type { QualificationPayload } from '@/qualification'
import { http } from './http'

type Query = Record<string, unknown>

/** 开发设置：App\Controller\Merchant\DevSettingsController */
export interface DevSettings {
  app_key: string | null
  app_secret_generated: boolean
  app_secret_reset_at: string | null
  ip_whitelist: string[]
}

export interface SecretResult {
  app_key: string
  app_secret: string
  app_secret_reset_at: string
}

export const devSettingsApi = {
  get: () => http.get<DevSettings>('/merchant/dev-settings'),
  generate: () => http.post<SecretResult>('/merchant/dev-settings/app-key'),
  resetSecret: () => http.post<SecretResult>('/merchant/dev-settings/app-secret/reset'),
  // body 顶层就是 IP 数组
  updateIpWhitelist: (ips: string[]) => http.put<{ ip_whitelist: string[] }>('/merchant/dev-settings/ip-whitelist', ips),
}

/** 充值申请：App\Controller\Merchant\RechargeRequestController */
export interface RechargeRequest {
  id: number
  amount: string
  proof_image: string
  transfer_no: string | null
  status: string
  reject_reason: string | null
  reviewed_at: string | null
  created_at: string | null
}

export const rechargeApi = {
  list: (params: Query) => http.get<Paged<RechargeRequest>>('/merchant/recharge-requests', params),
  submit: (data: { amount: string; proof_image: string; transfer_no: string }) =>
    http.post<RechargeRequest>('/merchant/recharge-requests', data),
}

/** 资金流水：App\Controller\Merchant\BalanceLogController */
export interface BalanceLog {
  id: number
  type: string
  amount: string
  available_before: string
  available_after: string
  frozen_before: string
  frozen_after: string
  order_id: number | null
  rebate_id: number | null
  reason: string | null
  created_at: string | null
}

export const balanceLogApi = {
  list: (params: Query) => http.get<Paged<BalanceLog>>('/merchant/balance-logs', params),
}

/** 订单：App\Controller\Merchant\OrderController */
export interface Order {
  order_no: string
  merchant_order_no: string
  business_line: string
  status: string
  sale_price: string
  frozen_amount: string
  deducted_amount: string | null
  refunded_amount: string
  created_at: string | null
  completed_at: string | null
  fail_code: number | null
  fail_reason: string | null
}

export interface NotifyLog {
  attempt_no: number
  url: string
  http_status: number | null
  success: boolean
  response_body: string | null
  created_at: string | null
}

export interface OrderDetail extends Order {
  recharge_account: string | null
  card_no?: string
  card_pwd?: string
  notify_logs: NotifyLog[]
}

export const orderApi = {
  list: (params: Query) => http.get<Paged<Order>>('/merchant/orders', params),
  detail: (orderNo: string) => http.get<OrderDetail>(`/merchant/orders/${encodeURIComponent(orderNo)}`),
  renotify: (orderNo: string) => http.post<{ success: boolean }>(`/merchant/orders/${encodeURIComponent(orderNo)}/renotify`),
}

/** 售后争议：App\Controller\Merchant\DisputeController */
export interface Dispute {
  id: number
  order_no: string | null
  merchant_order_no: string | null
  business_line: string | null
  sale_price: string | null
  order_status: string | null
  status: string
  result_remark: string | null
  evidence: string[] | null
  submitted_at: string | null
  resolved_at: string | null
}

export const disputeApi = {
  list: (params: Query) => http.get<Paged<Dispute>>('/merchant/disputes', params),
  detail: (id: number) => http.get<Dispute>(`/merchant/disputes/${id}`),
  submit: (orderNo: string) => http.post<Dispute>('/merchant/disputes', { order_no: orderNo }),
}

/** 返佣明细：App\Controller\Merchant\RebateController */
export interface Rebate {
  id: number
  order_no: string | null
  merchant_order_no: string | null
  business_line: string
  sale_price: string | null
  rebate_rate: string
  amount: string
  status: string
  order_completed_at: string | null
  due_at: string | null
  settled_at: string | null
  voided_at: string | null
  clawed_back_at: string | null
  created_at: string | null
}

/** 按当前筛选条件、按状态汇总的笔数和金额 */
export type RebateSummary = Record<string, { count: number; amount: string }>

export const rebateApi = {
  list: (params: Query) => http.get<Paged<Rebate> & { summary: RebateSummary }>('/merchant/rebates', params),
}

/** 资质资料：App\Controller\Merchant\QualificationController */
export interface MerchantQualification {
  id: number
  type: string
  company_name: string | null
  business_license_no: string | null
  business_license_image: string | null
  legal_person_name: string | null
  contact_name: string | null
  contact_phone: string
  id_card_name: string | null
  /** 身份证号只返回后 4 位 */
  id_card_no_masked: string | null
  id_card_images: string[] | null
  status: string
  reject_reason: string | null
  submitted_at: string | null
  reviewed_at: string | null
}

export interface QualificationHistoryItem {
  id: number
  type: string
  status: string
  reject_reason: string | null
  submitted_at: string | null
  reviewed_at: string | null
}

export interface QualificationStatus {
  /** 商户账户状态 */
  status: string
  type: string
  level_name: string | null
  /** 只有被驳回时能重新提交 */
  can_resubmit: boolean
  qualification: MerchantQualification | null
  history: QualificationHistoryItem[]
}

export const qualificationApi = {
  get: () => http.get<QualificationStatus>('/merchant/qualification'),
  resubmit: (data: QualificationPayload) => http.post<QualificationStatus>('/merchant/qualification', data),
}

/** 首页统计：App\Controller\Merchant\DashboardController，口径见 App\Service\Merchant\DashboardService */
export interface DashboardStats {
  pending_rebate: string
  today: {
    order_count: number
    /** 消费金额 = 实扣 - 已退款 */
    amount: string
    success_count: number
    finished_count: number
    /** 百分比；今天还没有出结果的订单时为 null */
    success_rate: number | null
  }
  /** 近 7 天，最早的在前，最后一项是今天 */
  trend: { date: string; order_count: number; amount: string }[]
}

export const dashboardApi = {
  stats: () => http.get<DashboardStats>('/merchant/dashboard'),
}

/** 服务开通：App\Controller\Merchant\SubscriptionController */
export interface BusinessSubscription {
  business_line: string
  /** false = 平台暂未开放，不能申请 */
  available: boolean
  /** null = 没申请过 */
  status: string | null
  reject_reason: string | null
  applied_at: string | null
  reviewed_at: string | null
}

export const subscriptionApi = {
  list: () => http.get<{ data: BusinessSubscription[] }>('/merchant/subscriptions').then((r) => r.data),
  apply: (businessLine: string) =>
    http.post<{ data: BusinessSubscription[] }>('/merchant/subscriptions', { business_line: businessLine }).then((r) => r.data),
}

/** 商品价格：App\Controller\Merchant\ProductController */
export interface PricedProduct {
  id: number
  name: string
  operator: string | null
  province: string | null
  charge_speed: string | null
  card_type: string | null
  face_value: string
  sale_price: string
  /** 按本商户当前等级算出的每单返佣 */
  rebate: string
}

export interface ProductPriceList {
  business_line: string
  /** false = 平台暂未开放该业务线 */
  available: boolean
  subscribed: boolean
  /** 本等级在该业务线的默认返佣比例（1 = 100%），没设置为 null */
  level_rate: string | null
  data: PricedProduct[]
}

export const productApi = {
  list: (businessLine: string) => http.get<ProductPriceList>('/merchant/products', { business_line: businessLine }),
}
