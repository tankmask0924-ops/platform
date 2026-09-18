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
import { useRoute } from 'vue-router'
import { type Rebate, rebateApi, type RebateList } from '@/api/admin'
import { rebateBaseSourceLabels, rebateRateSourceLabels } from '@/labels'
import { usePermissionStore } from '@/stores/permission'

const route = useRoute()
const permission = usePermissionStore()

// 汇总和返佣期限跟列表同一个接口返回，汇总按当前筛选条件统计、不分页
const summary = ref<RebateList['summary']>({})
const duePeriodDays = ref<number | null>(null)

const initial = {
  merchant_id: typeof route.query.merchant_id === 'string' ? route.query.merchant_id : '',
  status: '',
  business_line: '',
  order_no: '',
  created_from: '',
  created_to: '',
}
const list = usePagedList<Rebate, typeof initial>(
  (params) =>
    rebateApi.list(params).then((result) => {
      summary.value = result.summary
      duePeriodDays.value = result.due_period_days
      return result
    }),
  initial,
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
  Object.assign(filters, { merchant_id: '', status: '', business_line: '', order_no: '', created_from: '', created_to: '' })
  createdRange.value = null
  list.search()
}

function statusTime(row: Rebate): string {
  const times: Record<string, string | null> = { settled: row.settled_at, voided: row.voided_at, clawed_back: row.clawed_back_at }
  return times[row.status] ?? '-'
}

onMounted(list.load)
</script>

<template>
  <el-alert v-if="duePeriodDays !== null" type="info" :closable="false" class="period">
    当前返佣固定期限：订单完成后 <b>{{ duePeriodDays }}</b> 天到账，修改只影响之后完成的订单。
    <router-link v-if="permission.can('setting.view')" :to="{ name: 'settings' }">去系统参数修改</router-link>
  </el-alert>

  <el-row :gutter="16" class="summary">
    <el-col v-for="o in statusOptions" :key="o.value" :xs="12" :sm="6">
      <el-card shadow="never">
        <StatusTag :map="rebateStatusLabels" :value="o.value" />
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
      <el-form-item label="商户 ID">
        <el-input v-model="filters.merchant_id" clearable style="width: 100px" />
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
      <el-table-column label="平台单号" min-width="200">
        <template #default="{ row }">
          <router-link v-if="permission.can('order.view')" :to="{ name: 'order-detail', params: { id: row.order_id } }">
            {{ row.order_no }}
          </router-link>
          <span v-else>{{ row.order_no }}</span>
        </template>
      </el-table-column>
      <el-table-column label="商户" min-width="150">
        <template #default="{ row }">
          <router-link :to="{ name: 'merchant-detail', params: { id: row.merchant_id } }">#{{ row.merchant_id }}</router-link>
          <span class="muted">{{ row.merchant_phone ?? row.merchant_email ?? '' }}</span>
        </template>
      </el-table-column>
      <el-table-column label="业务" width="70">
        <template #default="{ row }">{{ labelOf(businessLineLabels, row.business_line) }}</template>
      </el-table-column>
      <el-table-column label="等级" width="100">
        <template #default="{ row }">{{ row.level_name ?? `#${row.level_id}` }}</template>
      </el-table-column>
      <el-table-column label="返佣基数" width="130" align="right">
        <template #default="{ row }">
          {{ money(row.rebate_base) }}
          <div class="muted">{{ labelOf(rebateBaseSourceLabels, row.rebate_base_source) }}</div>
        </template>
      </el-table-column>
      <el-table-column label="比例" width="120" align="right">
        <template #default="{ row }">
          {{ ratePercent(row.rebate_rate) }}
          <div class="muted">{{ labelOf(rebateRateSourceLabels, row.rebate_rate_source) }}</div>
        </template>
      </el-table-column>
      <el-table-column label="返佣金额" width="100" align="right">
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
.period,
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
  margin-left: 4px;
  color: var(--el-text-color-secondary);
  font-size: 12px;
}
.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}
</style>
