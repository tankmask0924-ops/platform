import type { Paged } from '@platform/shared'
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
  config: Record<string, unknown>
  contact: string | null
  settlement_info: string | null
  remark: string | null
  updated_at: string | null
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
}

export const orderApi = {
  list: (params: Query) => http.get<Paged<Order>>('/admin/orders', params),
  detail: (id: number) => http.get<OrderDetail>(`/admin/orders/${id}`),
  resolve: (id: number, data: { result: 'success' | 'failed'; remark: string; supplier_order_no?: string }) =>
    http.post<Order>(`/admin/orders/${id}/resolve`, data),
  querySupplier: (id: number) => http.post<QuerySupplierResult>(`/admin/orders/${id}/query-supplier`),
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

export const rebateApi = {
  list: (params: Query) => http.get<RebateList>('/admin/rebates', params),
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
