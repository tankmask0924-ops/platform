<script setup lang="ts">
import {
  businessLineLabels,
  labelOf,
  money,
  ratePercent,
  rebateStatusLabels,
  StatusTag,
  toOptions,
  usePagedList,
} from '@platform/shared'
import { onMounted, ref } from 'vue'
import { type Rebate, rebateApi, type RebateSummary } from '@/api/merchant'

// 汇总跟列表同一个接口返回，按当前筛选条件统计、不分页
const summary = ref<RebateSummary>({})
const list = usePagedList<Rebate, Record<string, string>>(
  (params) =>
    rebateApi.list(params).then((result) => {
      summary.value = result.summary
      return result
    }),
  { status: '', business_line: '', order_no: '', created_from: '', created_to: '' },
)
const { rows, total, page, perPage, loading, filters } = list
const statusOptions = toOptions(rebateStatusLabels)
const businessLineOptions = toOptions(businessLineLabels)

const createdRange = ref<[string, string] | null>(null)
function onRangeChange(value: [string, string] | null) {
  filters.created_from = value?.[0] ?? ''
  filters.created_to = value?.[1] ?? ''
  list.search()
}

function reset() {
  Object.assign(filters, { status: '', business_line: '', order_no: '', created_from: '', created_to: '' })
  createdRange.value = null
  list.search()
}

/** 各状态对应的发生时间 */
function statusTime(row: Rebate): string {
  const times: Record<string, string | null> = { settled: row.settled_at, voided: row.voided_at, clawed_back: row.clawed_back_at }
  const time = times[row.status]
  return time ?? '-'
}

onMounted(list.load)
</script>

<template>
  <el-row :gutter="16" class="summary">
    <el-col v-for="o in statusOptions" :key="o.value" :xs="12" :sm="6">
      <el-card shadow="never">
        <div class="summary-label"><StatusTag :map="rebateStatusLabels" :value="o.value" /></div>
        <div class="summary-amount">{{ money(summary[o.value]?.amount ?? '0.00') }}</div>
        <div class="muted">{{ summary[o.value]?.count ?? 0 }} 笔</div>
      </el-card>
    </el-col>
  </el-row>

  <el-card shadow="never">
    <el-form inline @submit.prevent="list.search">
      <el-form-item label="平台单号">
        <el-input v-model="filters.order_no" clearable style="width: 200px" />
      </el-form-item>
      <el-form-item label="状态">
        <el-select v-model="filters.status" clearable placeholder="全部" style="width: 120px">
          <el-option v-for="o in statusOptions" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>
      <el-form-item label="业务">
        <el-select v-model="filters.business_line" clearable placeholder="全部" style="width: 120px">
          <el-option v-for="o in businessLineOptions" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>
      <el-form-item label="生成日期">
        <el-date-picker
          v-model="createdRange"
          type="daterange"
          value-format="YYYY-MM-DD"
          start-placeholder="开始"
          end-placeholder="结束"
          @change="onRangeChange"
        />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" native-type="submit">查询</el-button>
        <el-button @click="reset">重置</el-button>
      </el-form-item>
    </el-form>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="order_no" label="平台单号" min-width="200" />
      <el-table-column prop="merchant_order_no" label="商户单号" min-width="160" />
      <el-table-column label="业务" width="80">
        <template #default="{ row }">{{ labelOf(businessLineLabels, row.business_line) }}</template>
      </el-table-column>
      <el-table-column label="订单金额" width="110" align="right">
        <template #default="{ row }">{{ money(row.sale_price) }}</template>
      </el-table-column>
      <el-table-column label="返佣比例" width="100" align="right">
        <template #default="{ row }">{{ ratePercent(row.rebate_rate) }}</template>
      </el-table-column>
      <el-table-column label="返佣金额" width="110" align="right">
        <template #default="{ row }">{{ money(row.amount) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }"><StatusTag :map="rebateStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column label="订单完成时间" width="170">
        <template #default="{ row }">{{ row.order_completed_at ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="预计到账" width="170">
        <template #default="{ row }">{{ row.due_at ?? '待订单完成后确定' }}</template>
      </el-table-column>
      <el-table-column label="到账 / 作废 / 扣回时间" width="170">
        <template #default="{ row }">{{ statusTime(row as Rebate) }}</template>
      </el-table-column>
    </el-table>

    <el-pagination
      v-model:current-page="page"
      v-model:page-size="perPage"
      class="pagination"
      layout="total, sizes, prev, pager, next"
      :total="total"
      @current-change="list.load"
      @size-change="list.search"
    />
  </el-card>
</template>

<style scoped>
.summary {
  margin-bottom: 16px;
}
.summary .el-col {
  margin-bottom: 8px;
}
.summary-amount {
  font-size: 20px;
  font-weight: 600;
  margin: 8px 0 4px;
}
.muted {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}
.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}
</style>
