<script setup lang="ts">
import { labelOf, StatusTag, toOptions, usePagedList } from '@platform/shared'
import { ElMessage, ElMessageBox } from 'element-plus'
import { computed, onMounted, ref } from 'vue'
import { type ReconciliationDiff, reconciliationApi } from '@/api/admin'
import {
  reconciliationFieldLabels,
  reconciliationSideStatusLabels,
  reconciliationStatusLabels,
  reconciliationTypeLabels,
} from '@/labels'
import { usePermissionStore } from '@/stores/permission'

const permission = usePermissionStore()
const canHandle = computed(() => permission.can('reconciliation.handle'))

const openCount = ref(0)
const initial = { type: '', status: 'open', field: '', order_no: '', date_from: '', date_to: '' }
const list = usePagedList<ReconciliationDiff, typeof initial>(
  (params) =>
    reconciliationApi.list(params).then((result) => {
      openCount.value = result.open_count
      return result
    }),
  initial,
  20,
)
const { rows, total, page, perPage, loading, filters } = list

const typeOptions = toOptions(reconciliationTypeLabels)
const fieldOptions = toOptions(reconciliationFieldLabels)
const statusOptions = toOptions(reconciliationStatusLabels)

const dateRange = ref<[string, string] | null>(null)
function onRangeChange(value: [string, string] | null) {
  filters.date_from = value?.[0] ?? ''
  filters.date_to = value?.[1] ?? ''
  list.search()
}

function reset() {
  Object.assign(filters, initial)
  dateRange.value = null
  list.search()
}

/** 重跑的批次日期，默认今天；批次对的是它前一天完成的订单 */
const runDate = ref(new Date().toISOString().slice(0, 10))
const running = ref(false)
async function run() {
  await ElMessageBox.confirm(
    `重跑 ${runDate.value} 这一批（对账的是 ${runDate.value} 前一天完成的订单）？这批已有的差异记录会被这次的结果整体替换，包括已经标记处理过的。`,
    '重跑对账',
    { type: 'warning' },
  )
  running.value = true
  try {
    const summary = await reconciliationApi.run(runDate.value)
    ElMessage.success(
      `已对账 ${summary.order_date} 完成的订单：比对 ${summary.checked} 笔，差异 ${summary.diff_count} 条` +
        (summary.rebate_checked > 0 ? `；比对供应商返佣 ${summary.rebate_checked} 笔，差异 ${summary.rebate_diff_count} 条` : '') +
        (summary.unreachable > 0 ? `，${summary.unreachable} 笔没拿到供应商记录` : ''),
    )
    list.load()
  } finally {
    running.value = false
  }
}

const handling = ref(0)
async function handle(row: ReconciliationDiff, action: 'resolve' | 'ignore') {
  const label = action === 'resolve' ? '已处理' : '已忽略'
  const { value: remark } = await ElMessageBox.prompt('处理备注（可不填）', '标记' + label, {
    inputPlaceholder: '如：供应商侧延迟同步，人工确认无误',
    inputValue: '',
    inputValidator: (value: string) => value.length <= 255 || '备注最多 255 个字',
  })
  handling.value = row.id
  try {
    const params = { ...filters, remark: remark ?? '' }
    const result = await (action === 'resolve'
      ? reconciliationApi.resolve(row.id, params)
      : reconciliationApi.ignore(row.id, params))
    openCount.value = result.open_count
    // 标记后按当前筛选重新取一页：默认筛「待处理」时这条会从列表里消失
    list.load()
    ElMessage.success('已标记为' + label)
  } finally {
    handling.value = 0
  }
}

onMounted(list.load)
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="card-header">
        <span>
          对账
          <el-tag v-if="openCount > 0" type="danger" size="small" class="gap-left">{{ openCount }} 条待处理</el-tag>
          <el-tag v-else type="success" size="small" class="gap-left">没有待处理差异</el-tag>
        </span>
        <div v-if="canHandle" class="run">
          <el-date-picker v-model="runDate" type="date" value-format="YYYY-MM-DD" :clearable="false" style="width: 150px" />
          <el-button type="primary" :loading="running" @click="run">重跑该批次</el-button>
        </div>
      </div>
    </template>

    <el-alert type="info" :closable="false" show-icon class="tip">
      每天 05:00 自动对前一天完成的订单：拿平台订单跟供应商侧的订单记录逐笔比，状态或成本对不上的列在这里。
      查不到供应商记录的订单不算差异（下一批会再对一次），不会出现在列表里。
    </el-alert>

    <el-form inline @submit.prevent="list.search">
      <el-form-item label="状态">
        <el-select v-model="filters.status" clearable placeholder="全部" style="width: 120px" @change="list.search">
          <el-option v-for="o in statusOptions" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>
      <el-form-item label="对账类型">
        <el-select v-model="filters.type" clearable placeholder="全部" style="width: 130px" @change="list.search">
          <el-option v-for="o in typeOptions" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>
      <el-form-item label="差异项">
        <el-select v-model="filters.field" clearable placeholder="全部" style="width: 130px" @change="list.search">
          <el-option v-for="o in fieldOptions" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>
      <el-form-item label="订单号">
        <el-input v-model="filters.order_no" placeholder="平台订单号" clearable style="width: 200px" />
      </el-form-item>
      <el-form-item label="批次日期">
        <el-date-picker
          v-model="dateRange"
          type="daterange"
          value-format="YYYY-MM-DD"
          start-placeholder="开始"
          end-placeholder="结束"
          style="width: 240px"
          @change="onRangeChange"
        />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" native-type="submit">查询</el-button>
        <el-button @click="reset">重置</el-button>
      </el-form-item>
    </el-form>

    <el-table v-loading="loading" :data="rows" border empty-text="没有对账差异">
      <el-table-column prop="reconciliation_date" label="批次" width="110" />
      <el-table-column label="类型" width="100">
        <template #default="{ row }">{{ labelOf(reconciliationTypeLabels, row.type) }}</template>
      </el-table-column>
      <el-table-column label="订单" min-width="190">
        <template #default="{ row }">
          <router-link v-if="row.order_no" :to="{ name: 'order-detail', params: { id: row.order_id } }">
            {{ row.order_no }}
          </router-link>
          <span v-else>#{{ row.order_id }}</span>
        </template>
      </el-table-column>
      <el-table-column label="供应商" min-width="140">
        <template #default="{ row }">
          <router-link v-if="row.supplier_name" :to="{ name: 'supplier-detail', params: { id: row.supplier_id } }">
            {{ row.supplier_name }}
          </router-link>
          <span v-else>#{{ row.supplier_id }}</span>
        </template>
      </el-table-column>
      <el-table-column label="差异项" width="110">
        <template #default="{ row }"><StatusTag :map="reconciliationFieldLabels" :value="row.field" /></template>
      </el-table-column>
      <el-table-column label="平台" width="110">
        <template #default="{ row }">
          <StatusTag v-if="row.field === 'status'" :map="reconciliationSideStatusLabels" :value="row.platform_value" />
          <span v-else>{{ row.platform_value }}</span>
        </template>
      </el-table-column>
      <el-table-column label="供应商侧" width="110">
        <template #default="{ row }">
          <StatusTag v-if="row.field === 'status'" :map="reconciliationSideStatusLabels" :value="row.supplier_value" />
          <span v-else>{{ row.supplier_value }}</span>
        </template>
      </el-table-column>
      <el-table-column label="差额" width="100" align="right">
        <template #default="{ row }">
          <!-- 差额 = 平台 - 供应商，正数表示平台记的成本更高 -->
          <span v-if="row.diff_amount === null">-</span>
          <span v-else class="diff">{{ row.diff_amount }}</span>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="100">
        <template #default="{ row }"><StatusTag :map="reconciliationStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column label="处理" min-width="180">
        <template #default="{ row }">
          <div v-if="row.resolved_by">
            {{ row.resolved_by }}
            <div class="remark">{{ row.remark || '（无备注）' }}</div>
          </div>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column v-if="canHandle" label="操作" width="150">
        <template #default="{ row }">
          <template v-if="row.status === 'open'">
            <el-button link type="primary" :loading="handling === row.id" @click="handle(row as ReconciliationDiff, 'resolve')">
              已处理
            </el-button>
            <el-button link type="info" :loading="handling === row.id" @click="handle(row as ReconciliationDiff, 'ignore')">
              忽略
            </el-button>
          </template>
          <span v-else>-</span>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      v-model:current-page="page"
      v-model:page-size="perPage"
      class="pagination"
      layout="total, sizes, prev, pager, next"
      :page-sizes="[20, 50, 100]"
      :total="total"
      @current-change="list.load"
      @size-change="list.search"
    />
  </el-card>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.run {
  display: flex;
  gap: 8px;
}

.gap-left {
  margin-left: 8px;
}

.tip {
  margin-bottom: 16px;
}

.diff {
  font-weight: 600;
  color: #e6a23c;
}

.remark {
  font-size: 12px;
  color: #909399;
}

.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}
</style>
