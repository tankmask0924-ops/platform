<script setup lang="ts">
import { businessLineLabels, labelOf, money, ratePercent, rebateStatusLabels, StatusTag, usePagedList } from '@platform/shared'
import { onMounted, ref } from 'vue'
import { rebateApi, type SupplierRebate, type SupplierRebateList } from '@/api/admin'
import { usePermissionStore } from '@/stores/permission'

const permission = usePermissionStore()

// 汇总跟列表同一个接口返回，按当前筛选条件统计、不分页
const summary = ref<SupplierRebateList['summary'] | null>(null)

const initial = {
  business_line: '',
  rebate: '',
  supplier_id: '',
  merchant_id: '',
  order_no: '',
  completed_from: '',
  completed_to: '',
}
const list = usePagedList<SupplierRebate, typeof initial>(
  (params) =>
    rebateApi.supplierList(params).then((result) => {
      summary.value = result.summary
      return result
    }),
  initial,
)
const { rows, total, page, perPage, loading, filters } = list
const businessLineOptions = [
  { value: 'movie', label: labelOf(businessLineLabels, 'movie') },
  { value: 'express', label: labelOf(businessLineLabels, 'express') },
]
const rebateOptions = [
  { value: 'returned', label: '已返回' },
  { value: 'missing', label: '未返回' },
]

const completedRange = ref<[string, string] | null>(null)
function onRangeChange(value: [string, string] | null) {
  filters.completed_from = value?.[0] ?? ''
  filters.completed_to = value?.[1] ?? ''
  list.search()
}

function reset() {
  Object.assign(filters, { ...initial })
  completedRange.value = null
  list.search()
}

onMounted(list.load)
</script>

<template>
  <el-alert type="info" :closable="false" class="tip">
    只列电影票、快递的成功订单。供应商返佣以供应商查询订单详情为准，可能晚于出票才返回；跟供应商对不上的由每日对账报出（对账类型「返佣」），
    在订单详情「查询供应商」可以补上晚到的返佣。快递的供应商（云洋）目前没有返佣。
  </el-alert>

  <el-row v-if="summary" :gutter="16" class="summary">
    <el-col :xs="12" :sm="6">
      <el-card shadow="never">
        <div class="muted">供应商返佣</div>
        <div class="summary-amount">{{ money(summary.supplier_rebate) }}</div>
        <div class="muted">已返回 {{ summary.returned_count }} 笔 / 未返回 {{ summary.missing_count }} 笔</div>
      </el-card>
    </el-col>
    <el-col :xs="12" :sm="6">
      <el-card shadow="never">
        <div class="muted">商户返佣（待到账 + 已到账）</div>
        <div class="summary-amount">{{ money(summary.merchant_rebate) }}</div>
        <div class="muted">共 {{ summary.count }} 笔订单</div>
      </el-card>
    </el-col>
    <el-col :xs="12" :sm="6">
      <el-card shadow="never">
        <div class="muted">返佣收支</div>
        <div class="summary-amount">{{ money(summary.rebate_balance) }}</div>
        <div class="muted">供应商返佣 − 商户返佣</div>
      </el-card>
    </el-col>
  </el-row>

  <el-card shadow="never">
    <el-form inline @submit.prevent="list.search">
      <el-form-item label="平台单号">
        <el-input v-model="filters.order_no" clearable style="width: 200px" />
      </el-form-item>
      <el-form-item label="业务">
        <el-select v-model="filters.business_line" clearable placeholder="全部" style="width: 110px">
          <el-option v-for="o in businessLineOptions" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>
      <el-form-item label="供应商返佣">
        <el-select v-model="filters.rebate" clearable placeholder="全部" style="width: 110px">
          <el-option v-for="o in rebateOptions" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>
      <el-form-item label="供应商 ID">
        <el-input v-model="filters.supplier_id" clearable style="width: 100px" />
      </el-form-item>
      <el-form-item label="商户 ID">
        <el-input v-model="filters.merchant_id" clearable style="width: 100px" />
      </el-form-item>
      <el-form-item label="完成日期">
        <el-date-picker
          v-model="completedRange"
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
      <el-table-column label="业务" width="70">
        <template #default="{ row }">{{ labelOf(businessLineLabels, row.business_line) }}</template>
      </el-table-column>
      <el-table-column label="供应商" min-width="120">
        <template #default="{ row }">{{ row.supplier_name ?? (row.supplier_id ? `#${row.supplier_id}` : '-') }}</template>
      </el-table-column>
      <el-table-column label="商户" min-width="150">
        <template #default="{ row }">
          <router-link :to="{ name: 'merchant-detail', params: { id: row.merchant_id } }">#{{ row.merchant_id }}</router-link>
          <span class="muted">{{ row.merchant_contact ?? '' }}</span>
        </template>
      </el-table-column>
      <el-table-column label="售价 / 成本" width="120" align="right">
        <template #default="{ row }">
          {{ money(row.sale_price) }}
          <div class="muted">{{ money(row.cost_price) }}</div>
        </template>
      </el-table-column>
      <el-table-column label="供应商返佣" width="110" align="right">
        <template #default="{ row }">
          <span v-if="row.supplier_rebate !== null">{{ money(row.supplier_rebate) }}</span>
          <el-tag v-else type="info" size="small">未返回</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="商户返佣" width="150" align="right">
        <template #default="{ row }">
          <template v-if="row.merchant_rebate !== null">
            {{ money(row.merchant_rebate) }}
            <div>
              <span class="muted">{{ ratePercent(row.merchant_rebate_rate) }}</span>
              <StatusTag :map="rebateStatusLabels" :value="row.merchant_rebate_status" />
            </div>
          </template>
          <span v-else class="muted">未生成</span>
        </template>
      </el-table-column>
      <el-table-column label="返佣收支" width="100" align="right">
        <template #default="{ row }">{{ money(row.rebate_balance) }}</template>
      </el-table-column>
      <el-table-column label="完成时间" width="170">
        <template #default="{ row }">{{ row.completed_at ?? '-' }}</template>
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
.tip,
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
