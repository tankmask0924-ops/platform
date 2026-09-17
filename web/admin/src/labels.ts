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
