/**
 * 后端枚举值的中文名和标签颜色，两个后台共用。
 * 值以后端校验白名单为准（见各 Service 里的 ALLOWED_* / STATUSES 常量）。
 */

export type TagType = 'primary' | 'success' | 'info' | 'warning' | 'danger'

export interface LabelItem {
  label: string
  type?: TagType
}

export type LabelMap = Record<string, LabelItem>

/** 转成 el-select 的选项 */
export function toOptions(map: LabelMap): { value: string; label: string }[] {
  return Object.entries(map).map(([value, item]) => ({ value, label: item.label }))
}

export function labelOf(map: LabelMap, value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') {
    return '-'
  }
  return map[value]?.label ?? value
}

export const businessLineLabels: LabelMap = {
  recharge: { label: '话费' },
  card: { label: '卡券' },
  movie: { label: '电影票' },
  express: { label: '快递' },
}

/** 商户看到的订单状态：异常单显示为处理中，没有 abnormal */
export const merchantOrderStatusLabels: LabelMap = {
  processing: { label: '处理中', type: 'warning' },
  success: { label: '成功', type: 'success' },
  failed: { label: '失败', type: 'danger' },
  cancelled: { label: '已取消', type: 'info' },
  refunded: { label: '已退款', type: 'info' },
}

export const orderStatusLabels: LabelMap = {
  ...merchantOrderStatusLabels,
  abnormal: { label: '异常', type: 'danger' },
}

export const disputeStatusLabels: LabelMap = {
  processing: { label: '处理中', type: 'warning' },
  rejected: { label: '已驳回（确认已到账）', type: 'info' },
  confirmed: { label: '已退款（确认未到账）', type: 'success' },
}

export const rechargeRequestStatusLabels: LabelMap = {
  pending: { label: '待审核', type: 'warning' },
  approved: { label: '已通过', type: 'success' },
  rejected: { label: '已驳回', type: 'danger' },
}

export const balanceLogTypeLabels: LabelMap = {
  recharge: { label: '充值', type: 'success' },
  freeze: { label: '冻结', type: 'warning' },
  deduct: { label: '扣款', type: 'danger' },
  unfreeze: { label: '解冻', type: 'info' },
  supplement_deduct: { label: '补扣', type: 'danger' },
  refund: { label: '退款', type: 'success' },
  adjustment: { label: '调账', type: 'primary' },
  rebate_settle: { label: '返佣到账', type: 'success' },
  rebate_clawback: { label: '返佣扣回', type: 'danger' },
}

export const merchantStatusLabels: LabelMap = {
  pending: { label: '待审核', type: 'warning' },
  active: { label: '正常', type: 'success' },
  rejected: { label: '已驳回', type: 'danger' },
  disabled: { label: '已禁用', type: 'info' },
}

export const merchantTypeLabels: LabelMap = {
  company: { label: '企业' },
  individual: { label: '个人' },
}

/** 业务线开通申请状态 */
export const subscriptionStatusLabels: LabelMap = {
  pending: { label: '审核中', type: 'warning' },
  approved: { label: '已开通', type: 'success' },
  rejected: { label: '已驳回', type: 'danger' },
}

export const qualificationStatusLabels: LabelMap = {
  pending: { label: '待审核', type: 'warning' },
  approved: { label: '已通过', type: 'success' },
  rejected: { label: '已驳回', type: 'danger' },
}

export const rebateStatusLabels: LabelMap = {
  pending: { label: '待到账', type: 'warning' },
  settled: { label: '已到账', type: 'success' },
  voided: { label: '已作废', type: 'info' },
  clawed_back: { label: '已扣回', type: 'danger' },
}
