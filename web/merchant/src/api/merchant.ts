import type { Paged } from '@platform/shared'
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
