import type { LabelMap } from '@platform/shared'

/** 系统后台专用的枚举，值见各 Admin Service 的 ALLOWED_* 常量 */

export const productStatusLabels: LabelMap = {
  on_shelf: { label: '上架', type: 'success' },
  off_shelf: { label: '下架', type: 'info' },
}

/** 本地商品库目前只接受这两条业务线 */
export const productBusinessLineLabels: LabelMap = {
  recharge: { label: '话费' },
  card: { label: '卡券' },
}

export const operatorLabels: LabelMap = {
  mobile: { label: '移动' },
  unicom: { label: '联通' },
  telecom: { label: '电信' },
}

export const chargeSpeedLabels: LabelMap = {
  fast: { label: '快充' },
  slow: { label: '慢充' },
}

export const cardTypeLabels: LabelMap = {
  direct: { label: '直充' },
  card_secret: { label: '卡密' },
}

export const supplierStatusLabels: LabelMap = {
  active: { label: '启用', type: 'success' },
  disabled: { label: '停用', type: 'info' },
}

export const mappingStatusLabels: LabelMap = {
  active: { label: '启用', type: 'success' },
  paused: { label: '暂停', type: 'warning' },
  banned: { label: '禁用', type: 'danger' },
}

/** 已开发的供应商驱动及其配置项（配置整体加密存储，敏感项回显为 ******） */
export const driverConfigFields: Record<string, { key: string; label: string; secret?: boolean }[]> = {
  kasushou: [
    { key: 'base_url', label: '接口地址' },
    { key: 'user_id', label: '商户编号 user_id' },
    { key: 'api_key', label: '密钥 api_key', secret: true },
  ],
}

export const driverLabels: LabelMap = {
  kasushou: { label: '卡速售 2.0' },
}

/**
 * 供应商尝试结果（order_attempts.result，见 SupplierRouter::RESULT_MAP）；
 * 手动查询供应商接口返回的是枚举名小写，明确失败是 definitefailure。
 */
export const attemptResultLabels: LabelMap = {
  success: { label: '成功', type: 'success' },
  failed: { label: '明确失败', type: 'danger' },
  definitefailure: { label: '明确失败', type: 'danger' },
  processing: { label: '处理中', type: 'warning' },
  unknown: { label: '结果未知', type: 'warning' },
}

export const adminUserStatusLabels: LabelMap = {
  active: { label: '启用', type: 'success' },
  disabled: { label: '禁用', type: 'info' },
}

/** 角色名：超级管理员角色在库里叫 super_admin */
export function roleLabel(name: string | null | undefined): string {
  return name === 'super_admin' ? '超级管理员' : (name ?? '-')
}

/** 返佣基数来源（merchant_rebates.rebate_base_source） */
export const rebateBaseSourceLabels: LabelMap = {
  product: { label: '商品返佣' },
  supplier: { label: '供应商返佣' },
}

/** 返佣比例来源（merchant_rebates.rebate_rate_source） */
export const rebateRateSourceLabels: LabelMap = {
  product_level: { label: '商品单独设置' },
  level: { label: '等级设置' },
}

/** 权限分组 / 操作日志模块，对应权限编码前缀和 Service 里的 MODULE */
export const moduleLabels: LabelMap = {
  merchant: { label: '商户' },
  subscription: { label: '服务开通' },
  recharge: { label: '充值' },
  merchant_level: { label: '商户等级' },
  product: { label: '本地商品' },
  product_mapping: { label: '商品映射' },
  supplier: { label: '供应商' },
  order: { label: '订单' },
  aftersale: { label: '售后' },
  rebate: { label: '返佣' },
  system: { label: '系统设置' },
}

/** 操作日志的动作：Service 手动记的用具体名字，AdminOperationLogAspect 自动记的是 Controller 方法名 */
export const operationActionLabels: LabelMap = {
  store: { label: '新建' },
  update: { label: '修改' },
  destroy: { label: '删除' },
  change_status: { label: '启用/禁用' },
  set_status: { label: '启用/禁用' },
  approve: { label: '审核通过' },
  reject: { label: '驳回' },
  confirm: { label: '确认未到账并退款' },
  adjust_balance: { label: '调账' },
  change_level: { label: '调整等级' },
  set_rate_limit: { label: '设置限流' },
  reset_rate_limit: { label: '恢复默认限流' },
  set_rate: { label: '设置返佣比例' },
  set_level_rebate: { label: '设置等级返佣' },
  delete_level_rebate: { label: '删除等级返佣' },
  update_cost_price: { label: '改成本价' },
  update_priority: { label: '改优先级' },
  resolve_abnormal: { label: '异常单处理' },
  query_supplier: { label: '查询供应商' },
  renotify: { label: '重推回调' },
  reject_dispute: { label: '驳回争议' },
  confirm_dispute: { label: '确认未到账并退款' },
  create_admin_user: { label: '新建管理员' },
  update_admin_user: { label: '修改管理员' },
  enable_admin_user: { label: '启用管理员' },
  disable_admin_user: { label: '禁用管理员' },
  reset_admin_password: { label: '重置管理员密码' },
  create_role: { label: '新建角色' },
  update_role: { label: '修改角色' },
  delete_role: { label: '删除角色' },
  update_setting: { label: '修改系统参数' },
}
