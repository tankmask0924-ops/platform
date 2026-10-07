<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { duration } from '../format'
import { labelOf, operatorLabels } from '../labels'
import type { RechargeStats, RechargeStatsRow } from '../types'

/**
 * 话费订单耗时与成功率，系统后台和商户后台共用。口径见后端 App\Service\Order\RechargeStatsService：
 * 成功率 = 成功 ÷（成功 + 失败 + 已退款），耗时只算成功订单从下单到成功。
 */
const props = defineProps<{
  fetcher: (params: Record<string, unknown>) => Promise<RechargeStats>
  /** 系统后台可以按商户筛选 */
  showMerchantFilter?: boolean
}>()

const groupOptions = [
  { value: 'day', label: '按天' },
  { value: 'product', label: '按商品' },
  { value: 'operator', label: '按运营商' },
]

const filters = reactive({ group_by: 'day', merchant_id: '' })
const range = ref<[string, string] | null>(null)
const stats = ref<RechargeStats | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    stats.value = await props.fetcher({
      group_by: filters.group_by,
      created_from: range.value?.[0] ?? '',
      created_to: range.value?.[1] ?? '',
      ...(props.showMerchantFilter ? { merchant_id: filters.merchant_id } : {}),
    })
  } finally {
    loading.value = false
  }
}

function rowLabel(row: RechargeStatsRow): string {
  if (stats.value?.group_by === 'operator') {
    return row.key === null ? '未知运营商' : labelOf(operatorLabels, row.key)
  }
  if (stats.value?.group_by === 'product') {
    return row.label ?? (row.key === null ? '未知商品' : `#${row.key}`)
  }
  return row.key ?? '-'
}

function rate(value: number | null): string {
  return value === null ? '-' : `${value}%`
}

onMounted(load)
</script>

<template>
  <el-card shadow="never">
    <el-form inline @submit.prevent="load">
      <el-form-item label="维度">
        <el-radio-group v-model="filters.group_by" @change="load">
          <el-radio-button v-for="o in groupOptions" :key="o.value" :value="o.value">{{ o.label }}</el-radio-button>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="下单日期">
        <el-date-picker
          v-model="range"
          type="daterange"
          value-format="YYYY-MM-DD"
          start-placeholder="开始"
          end-placeholder="结束"
          @change="load"
        />
      </el-form-item>
      <el-form-item v-if="showMerchantFilter" label="商户 ID">
        <el-input v-model="filters.merchant_id" clearable style="width: 120px" @clear="load" />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" native-type="submit">查询</el-button>
      </el-form-item>
    </el-form>

    <el-alert type="info" :closable="false" class="tip">
      按下单时间统计话费订单。成功率 = 成功 ÷（成功 + 失败 + 已退款），处理中的不计入；耗时只算成功订单，从下单到充值成功。
      中位数表示一半的订单在这个时间内到账，90 分位表示 90% 的订单在这个时间内到账。
      <template v-if="stats">统计区间：{{ stats.created_from }} 至 {{ stats.created_to }}。</template>
    </el-alert>

    <div v-loading="loading">
      <el-row v-if="stats" :gutter="16" class="summary">
        <el-col :xs="12" :sm="8" :md="4">
          <div class="muted">订单数</div>
          <div class="value">{{ stats.summary.total }}</div>
          <div class="muted">处理中 {{ stats.summary.pending }}</div>
        </el-col>
        <el-col :xs="12" :sm="8" :md="4">
          <div class="muted">成功率</div>
          <div class="value">{{ rate(stats.summary.success_rate) }}</div>
          <div class="muted">成功 {{ stats.summary.success }} / 失败 {{ stats.summary.failed }} / 退款 {{ stats.summary.refunded }}</div>
        </el-col>
        <el-col :xs="12" :sm="8" :md="4">
          <div class="muted">平均耗时</div>
          <div class="value">{{ duration(stats.summary.avg_seconds) }}</div>
        </el-col>
        <el-col :xs="12" :sm="8" :md="4">
          <div class="muted">中位数</div>
          <div class="value">{{ duration(stats.summary.p50_seconds) }}</div>
        </el-col>
        <el-col :xs="12" :sm="8" :md="4">
          <div class="muted">90 分位</div>
          <div class="value">{{ duration(stats.summary.p90_seconds) }}</div>
        </el-col>
      </el-row>

      <el-table :data="stats?.data ?? []" border>
        <el-table-column :label="groupOptions.find((o) => o.value === stats?.group_by)?.label.replace('按', '') ?? '分组'" min-width="160">
          <template #default="{ row }">{{ rowLabel(row as RechargeStatsRow) }}</template>
        </el-table-column>
        <el-table-column prop="total" label="订单数" width="90" align="right" />
        <el-table-column prop="success" label="成功" width="80" align="right" />
        <el-table-column prop="failed" label="失败" width="80" align="right" />
        <el-table-column prop="refunded" label="已退款" width="80" align="right" />
        <el-table-column prop="pending" label="处理中" width="80" align="right" />
        <el-table-column label="成功率" width="90" align="right">
          <template #default="{ row }">{{ rate(row.success_rate) }}</template>
        </el-table-column>
        <el-table-column label="平均耗时" width="120" align="right">
          <template #default="{ row }">{{ duration(row.avg_seconds) }}</template>
        </el-table-column>
        <el-table-column label="中位数" width="120" align="right">
          <template #default="{ row }">{{ duration(row.p50_seconds) }}</template>
        </el-table-column>
        <el-table-column label="90 分位" width="120" align="right">
          <template #default="{ row }">{{ duration(row.p90_seconds) }}</template>
        </el-table-column>
      </el-table>
    </div>
  </el-card>
</template>

<style scoped>
.tip,
.summary {
  margin-bottom: 16px;
}
.summary .el-col {
  margin-bottom: 8px;
}
.value {
  font-size: 20px;
  font-weight: 600;
  margin: 6px 0 2px;
}
.muted {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}
</style>
