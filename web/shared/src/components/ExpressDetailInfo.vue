<script setup lang="ts">
import { computed } from 'vue'
import { money } from '../format'
import { feeAdjustmentTypeLabels, feeItemLabels, labelOf, logisticsStatusLabels } from '../labels'
import type { ExpressDetail } from '../types'
import StatusTag from './StatusTag.vue'

const props = defineProps<{ detail: ExpressDetail }>()

/** 系统后台版才带寄收件人和成本 */
const isAdmin = computed(() => props.detail.sender !== undefined)

function address(party: Record<string, string> | undefined): string {
  if (!party) {
    return '-'
  }
  const place = [party.province, party.city, party.district, party.address].filter(Boolean).join(' ')
  return `${party.name ?? ''} ${party.mobile ?? ''}　${place}`.trim()
}
</script>

<template>
  <el-descriptions :column="2" border>
    <el-descriptions-item label="快递公司">{{ detail.company_name }}</el-descriptions-item>
    <el-descriptions-item label="运单号">{{ detail.waybill_no ?? '-' }}</el-descriptions-item>
    <el-descriptions-item label="物流状态"><StatusTag :map="logisticsStatusLabels" :value="detail.logistics_status" /></el-descriptions-item>
    <el-descriptions-item label="签收时间">{{ detail.signed_at ?? '-' }}</el-descriptions-item>
    <el-descriptions-item label="保价金额">{{ money(detail.insured_amount) }}</el-descriptions-item>
    <template v-if="isAdmin">
      <el-descriptions-item label="重量">{{ detail.weight }} kg</el-descriptions-item>
      <el-descriptions-item label="寄件" :span="2">{{ address(detail.sender) }}</el-descriptions-item>
      <el-descriptions-item label="收件" :span="2">{{ address(detail.receiver) }}</el-descriptions-item>
      <el-descriptions-item label="物品">{{ detail.item?.name ?? '-' }}</el-descriptions-item>
      <el-descriptions-item label="扣费时间">{{ detail.fee_over_at ?? '-' }}</el-descriptions-item>
      <el-descriptions-item label="运费成本" :span="2">
        预估 {{ money(detail.estimated_freight) }} → 冻结 {{ money(detail.frozen_freight) }} → 实际 {{ money(detail.actual_freight) }}
        <span class="muted">（向商户收 {{ money(detail.freight_sale_price) }}）</span>
      </el-descriptions-item>
      <el-descriptions-item label="其它费用（成本价转给商户）" :span="2">
        保价费 {{ money(detail.actual_insured_fee) }}，耗材费 {{ money(detail.actual_material_fee) }}，逆向费 {{ money(detail.actual_reverse_fee) }}
      </el-descriptions-item>
    </template>
    <template v-else>
      <el-descriptions-item label="实际费用" :span="2">
        <template v-if="detail.fees">
          运费 {{ money(detail.fees.freight) }}，保价费 {{ money(detail.fees.insured_fee) }}，耗材费 {{ money(detail.fees.material_fee) }}，逆向费
          {{ money(detail.fees.reverse_fee) }}
        </template>
        <span v-else class="muted">快递公司扣费后才有，目前按预估金额冻结</span>
      </el-descriptions-item>
    </template>
  </el-descriptions>

  <template v-if="detail.fee_adjustments.length > 0">
    <h4 class="sub-title">费用调整</h4>
    <el-table :data="detail.fee_adjustments" border size="small">
      <el-table-column label="方向" width="80">
        <template #default="{ row }"><StatusTag :map="feeAdjustmentTypeLabels" :value="row.type" /></template>
      </el-table-column>
      <el-table-column label="费用项" width="90">
        <template #default="{ row }">{{ labelOf(feeItemLabels, row.item) }}</template>
      </el-table-column>
      <el-table-column label="金额" width="100">
        <template #default="{ row }">{{ money(row.amount) }}</template>
      </el-table-column>
      <el-table-column v-if="isAdmin" prop="reason" label="原因" min-width="160" show-overflow-tooltip />
      <el-table-column prop="created_at" label="时间" width="160" />
    </el-table>
  </template>
</template>

<style scoped>
.sub-title {
  margin: 16px 0 8px;
}

.muted {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}
</style>
