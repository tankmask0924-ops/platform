import type { Component } from 'vue'

export interface MenuItem {
  /** 路由路径；有 children 时仅作为分组标识 */
  path: string
  title: string
  icon?: Component
  /** 需要的权限编码，没有这个权限时不显示；不填表示登录即可见 */
  permission?: string
  children?: MenuItem[]
}

export interface LoginForm {
  username: string
  password: string
}

/**
 * 快递订单明细：商户后台（开放 API 订单查询的 express 段）和系统后台（多寄收件人和成本）共用一个组件，
 * 后台才有的字段都是可选的。
 */
export interface ExpressDetail {
  company_name: string
  waybill_no: string | null
  logistics_status: string
  insured_amount: string | null
  signed_at: string | null
  /** 商户版：实际费用（运费是加价后的售价），扣费前为 null */
  fees?: { freight: string; insured_fee: string; material_fee: string; reverse_fee: string } | null
  fee_adjustments: { type: string; item: string; amount: string; reason?: string | null; created_at: string }[]
  // 以下只有系统后台有
  sender?: Record<string, string>
  receiver?: Record<string, string>
  item?: { name?: string | null } | null
  weight?: string
  estimated_freight?: string
  frozen_freight?: string | null
  actual_freight?: string | null
  actual_insured_fee?: string | null
  actual_material_fee?: string | null
  actual_reverse_fee?: string | null
  freight_sale_price?: string | null
  fee_over_at?: string | null
}

/** 电影票订单明细，同 ExpressDetail：unit_cost / supplier_rebate 只有系统后台有 */
export interface MovieDetail {
  cinema_name: string | null
  film_name: string | null
  show_id: string
  show_time: string
  area_id: string | null
  seats: { seat_code: string; row_label: string | null; col_label: string | null; love_status: number }[]
  seat_count: number
  unit_price: string
  mobile: string
  lock_expire_at: string
  confirmed_at: string | null
  ticket_codes: unknown[]
  unit_cost?: string
  supplier_rebate?: string | null
}
