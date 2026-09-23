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

/** 商品字段的枚举，商户后台也要用，放在 shared */
export { cardTypeLabels, chargeSpeedLabels, operatorLabels } from '@platform/shared'

/** supplier_call_logs.action，见 KasushouDriver::ACTIONS */
export const supplierCallActionLabels: LabelMap = {
  place_order: { label: '下单' },
  query: { label: '查询订单' },
  query_balance: { label: '查余额' },
  goods_detail: { label: '查商品' },
  goods_list: { label: '商品列表' },
  check_channel: { label: '查价' },
  cancel: { label: '取消 / 释放座位' },
  query_trace: { label: '查轨迹' },
  submit_workorder: { label: '提交工单' },
  submit_aftersale: { label: '提交售后' },
  confirm_order: { label: '确认出票' },
  query_cities: { label: '查城市' },
  query_regions: { label: '查区县' },
  query_cinemas: { label: '查影院' },
  sync_cinemas: { label: '批量拉影院' },
  query_films: { label: '查影片' },
  query_shows: { label: '查场次' },
  sync_shows: { label: '批量拉场次' },
  query_seats: { label: '查座位' },
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
  yunyang: [
    { key: 'base_url', label: '接口地址（必须 https）' },
    { key: 'app_id', label: 'appid' },
    { key: 'secret_key', label: '密钥 secretKey', secret: true },
  ],
  mango: [
    { key: 'base_url', label: '接口地址' },
    { key: 'agent_id', label: 'agent_id' },
    { key: 'app_id', label: 'app_id' },
    { key: 'token', label: '签名 token', secret: true },
    { key: 'tel', label: '账户手机号 tel（查余额用）' },
  ],
}

export const driverLabels: LabelMap = {
  kasushou: { label: '卡速售 2.0' },
  yunyang: { label: '云洋快递' },
  mango: { label: '芒果电影' },
}

/** 驱动能用于哪些业务线，跟后端 SupplierAdminService::DRIVER_BUSINESS_LINES 一致 */
export const driverBusinessLines: Record<string, string[]> = {
  kasushou: ['recharge', 'card'],
  yunyang: ['express'],
  mango: ['movie'],
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
  cancel_at_supplier: { label: '发起供应商撤单' },
  partial_refund: { label: '部分退款' },
  submit_workorder: { label: '提交快递工单' },
  complete_workorder: { label: '完成快递工单' },
  reject_workorder: { label: '驳回快递工单' },
  submit_supplier_aftersale: { label: '提交卡速售售后' },
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

/** 告警类型：App\Model\Alert::TYPES（requirements.md 8.3） */
export const alertTypeLabels: LabelMap = {
  supplier_low_balance: { label: '供应商余额不足' },
  supplier_circuit_broken: { label: '供应商被熔断' },
  product_fail_rate_spike: { label: '商品失败率突增' },
  abnormal_order_backlog: { label: '异常单积压' },
  supplier_refund_after_success: { label: '供应商成功后退款' },
  rebate_loss: { label: '返佣后亏本' },
  merchant_debt_exceeded: { label: '商户欠款超预警线' },
}

export const alertLevelLabels: LabelMap = {
  warning: { label: '警告', type: 'warning' },
  critical: { label: '严重', type: 'danger' },
}

export const alertStatusLabels: LabelMap = {
  open: { label: '未处理', type: 'danger' },
  resolved: { label: '已处理', type: 'success' },
  ignored: { label: '已忽略', type: 'info' },
}

/** 告警关联对象类型，决定详情里跳到哪个页面 */
export const alertRelatedTypeLabels: LabelMap = {
  supplier: { label: '供应商' },
  product: { label: '商品' },
  merchant: { label: '商户' },
  order: { label: '订单' },
}

/** 对账差异类型：App\Model\ReconciliationDiff::TYPES（requirements.md 8.3） */
export const reconciliationTypeLabels: LabelMap = {
  order: { label: '订单对账' },
  rebate: { label: '返佣对账' },
}

/** 对比的字段 */
export const reconciliationFieldLabels: LabelMap = {
  status: { label: '订单状态', type: 'warning' },
  cost_price: { label: '成本金额', type: 'danger' },
  rebate_amount: { label: '返佣金额', type: 'danger' },
}

export const reconciliationStatusLabels: LabelMap = {
  open: { label: '待处理', type: 'danger' },
  resolved: { label: '已处理', type: 'success' },
  ignored: { label: '已忽略', type: 'info' },
}

/** 对账里两侧的订单状态取值，跟订单状态是同一套词，但只有这四种 */
export const reconciliationSideStatusLabels: LabelMap = {
  success: { label: '成功', type: 'success' },
  failed: { label: '失败', type: 'danger' },
  processing: { label: '处理中', type: 'warning' },
  refunded: { label: '已退款', type: 'info' },
  cancelled: { label: '已取消', type: 'info' },
  unknown: { label: '结果不确定', type: 'warning' },
}

/** 财务报表的分组维度：App\Service\Admin\FinanceReportService::GROUP_BYS */
export const reportGroupByLabels: LabelMap = {
  day: { label: '按天' },
  merchant: { label: '按商户' },
  level: { label: '按等级' },
  business_line: { label: '按业务线' },
  supplier: { label: '按供应商' },
}

/** 加价方式：App\Model\PricingRule::TYPES（requirements.md 5.1） */
export const pricingRuleTypeLabels: LabelMap = {
  fixed: { label: '固定金额' },
  percentage: { label: '百分比' },
}

/** 快递工单类型（App\Model\ExpressWorkorder::TYPES） */
export const workorderTypeLabels: LabelMap = {
  weight_verify: { label: '重量核实' },
  claim: { label: '理赔' },
  cancel: { label: '取消订单' },
  cod: { label: '现结到付' },
  urge_pickup: { label: '催取件' },
  urge_transport: { label: '催物流' },
  urge_delivery: { label: '催派送' },
}

export const workorderStatusLabels: LabelMap = {
  processing: { label: '处理中', type: 'warning' },
  completed: { label: '已完成', type: 'success' },
  rejected: { label: '已驳回', type: 'info' },
}

/** 卡速售售后处理状态（售后处理回调） */
export const supplierAftersaleStatusLabels: LabelMap = {
  processing: { label: '供应商处理中', type: 'warning' },
  completed: { label: '供应商处理完成', type: 'success' },
  terminated: { label: '供应商已终止', type: 'info' },
}
