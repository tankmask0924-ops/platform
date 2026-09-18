<script setup lang="ts">
import { businessLineLabels, copyText, labelOf, money, StatusTag, toOptions, usePagedList } from '@platform/shared'
import { ElMessage } from 'element-plus'
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { type SupplierCallLog, supplierApi, type SupplierDetail } from '@/api/admin'
import { driverLabels, supplierCallActionLabels, supplierStatusLabels } from '@/labels'
import { usePermissionStore } from '@/stores/permission'

const route = useRoute()
const id = Number(route.params.id)
const permission = usePermissionStore()
const canManage = computed(() => permission.can('supplier.manage'))

const supplier = ref<SupplierDetail | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    supplier.value = await supplierApi.detail(id)
  } finally {
    loading.value = false
  }
}

const balanceLow = computed(() => {
  const s = supplier.value
  return s !== null && s.balance !== null && s.balance_warning_threshold !== null && Number(s.balance) < Number(s.balance_warning_threshold)
})

const refreshing = ref(false)
async function refreshBalance() {
  refreshing.value = true
  try {
    supplier.value = await supplierApi.refreshBalance(id)
    ElMessage.success('余额已更新')
  } finally {
    refreshing.value = false
    // 失败时也刷新，方便直接看这次调用供应商返回了什么
    logs.search()
  }
}

const syncing = ref(false)
async function syncProducts() {
  syncing.value = true
  try {
    await supplierApi.syncProducts(id)
    ElMessage.success('已开始同步，完成后商品映射的同步时间会更新，过程可在下方调用日志查看')
  } finally {
    syncing.value = false
  }
}

// 调用日志
const initial = { action: '', order_no: '', created_from: '', created_to: '' }
const logs = usePagedList<SupplierCallLog, typeof initial>((p) => supplierApi.callLogs(id, p), initial, 20)
const { rows, total, page, perPage, filters } = logs
const logsLoading = logs.loading
const createdRange = ref<[string, string] | null>(null)
const actionOptions = toOptions(supplierCallActionLabels)

function onRangeChange(value: [string, string] | null) {
  filters.created_from = value?.[0] ?? ''
  filters.created_to = value?.[1] ?? ''
  logs.search()
}

function outcome(row: SupplierCallLog): { text: string; danger: boolean } {
  if (row.response && 'exception' in row.response) {
    return { text: '网络异常', danger: true }
  }
  if (row.http_status === null) {
    return { text: '-', danger: false }
  }
  return { text: `HTTP ${row.http_status}`, danger: row.http_status !== 200 }
}

const json = (value: unknown) => (value === null || value === undefined ? '-' : JSON.stringify(value, null, 2))

onMounted(() => {
  load()
  logs.load()
})
</script>

<template>
  <div v-loading="loading" class="page">
    <template v-if="supplier">
      <el-card shadow="never">
        <template #header>
          <div class="card-header">
            <span>{{ supplier.name }}</span>
            <div v-if="canManage">
              <el-button :loading="refreshing" @click="refreshBalance">刷新余额</el-button>
              <el-button :loading="syncing" :disabled="supplier.status !== 'active'" @click="syncProducts">同步商品</el-button>
            </div>
          </div>
        </template>
        <el-descriptions :column="3" border>
          <el-descriptions-item label="编码">{{ supplier.code }}</el-descriptions-item>
          <el-descriptions-item label="业务线">{{ labelOf(businessLineLabels, supplier.business_line) }}</el-descriptions-item>
          <el-descriptions-item label="驱动">{{ labelOf(driverLabels, supplier.driver) }}</el-descriptions-item>
          <el-descriptions-item label="状态"><StatusTag :map="supplierStatusLabels" :value="supplier.status" /></el-descriptions-item>
          <el-descriptions-item label="余额">
            <span :class="{ danger: balanceLow }">{{ money(supplier.balance) }}</span>
            <el-tag v-if="balanceLow" type="danger" size="small" class="gap-left">低于预警线</el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="预警线">{{ money(supplier.balance_warning_threshold) }}</el-descriptions-item>
          <el-descriptions-item label="余额查询时间" :span="3">
            {{ supplier.balance_synced_at ?? '还没查到过' }}
            <span class="muted gap-left">每 5 分钟自动查询一次，查询失败时保留上次的值</span>
          </el-descriptions-item>
        </el-descriptions>
      </el-card>

      <el-card shadow="never" header="回调地址">
        <el-alert
          v-if="!supplier.notify_base_url_configured"
          type="warning"
          show-icon
          :closable="false"
          class="block"
          title="服务器没有配置 SUPPLIER_NOTIFY_BASE_URL，下面是本机地址，供应商访问不到"
          description="订单结果只能靠定时查询拿到（会慢一些），商品变更只能靠每天 04:00 的全量同步。"
        />
        <el-descriptions :column="1" border>
          <el-descriptions-item label="订单结果回调">
            <code>{{ supplier.order_notify_url }}</code>
            <el-button link type="primary" class="gap-left" @click="copyText(supplier.order_notify_url)">复制</el-button>
            <div class="muted">下单时自动传给供应商，不用手动配置</div>
          </el-descriptions-item>
          <el-descriptions-item label="商品变更通知">
            <code>{{ supplier.goods_notify_url }}</code>
            <el-button link type="primary" class="gap-left" @click="copyText(supplier.goods_notify_url)">复制</el-button>
            <div class="muted">需要在供应商后台配置成这个地址；地址里带令牌，不要外传</div>
          </el-descriptions-item>
        </el-descriptions>
      </el-card>
    </template>

    <el-card shadow="never" header="调用日志">
      <el-form inline @submit.prevent="logs.search">
        <el-form-item label="动作">
          <el-select v-model="filters.action" clearable placeholder="全部" style="width: 120px" @change="logs.search">
            <el-option v-for="o in actionOptions" :key="o.value" :value="o.value" :label="o.label" />
          </el-select>
        </el-form-item>
        <el-form-item label="平台单号">
          <el-input v-model="filters.order_no" clearable style="width: 220px" />
        </el-form-item>
        <el-form-item label="时间">
          <el-date-picker
            v-model="createdRange"
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
        </el-form-item>
      </el-form>

      <el-table v-loading="logsLoading" :data="rows" border size="small" empty-text="没有调用记录">
        <el-table-column type="expand">
          <template #default="{ row }">
            <div class="snapshots">
              <div>
                <h5>请求</h5>
                <pre>{{ json(row.request) }}</pre>
              </div>
              <div>
                <h5>响应</h5>
                <pre>{{ json(row.response) }}</pre>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="时间" width="170" />
        <el-table-column label="动作" width="100">
          <template #default="{ row }">{{ labelOf(supplierCallActionLabels, row.action) }}</template>
        </el-table-column>
        <el-table-column label="订单" min-width="200">
          <template #default="{ row }">
            <router-link v-if="row.order_id" :to="{ name: 'order-detail', params: { id: row.order_id } }">
              {{ row.order_no ?? `#${row.order_id}` }}
            </router-link>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="结果" width="110">
          <template #default="{ row }">
            <span :class="{ danger: outcome(row as SupplierCallLog).danger }">{{ outcome(row as SupplierCallLog).text }}</span>
          </template>
        </el-table-column>
        <el-table-column label="耗时" width="100" align="right">
          <template #default="{ row }">{{ row.duration_ms === null ? '-' : `${row.duration_ms} ms` }}</template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-model:current-page="page"
        v-model:page-size="perPage"
        class="pagination"
        layout="total, sizes, prev, pager, next"
        :page-sizes="[20, 50, 100]"
        :total="total"
        @current-change="logs.load"
        @size-change="logs.search"
      />
    </el-card>
  </div>
</template>

<style scoped>
.page {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.block {
  margin-bottom: 12px;
}

.danger {
  color: #f56c6c;
  font-weight: 600;
}

.muted {
  color: #909399;
  font-size: 12px;
}

.gap-left {
  margin-left: 8px;
}

code {
  word-break: break-all;
  font-size: 12px;
}

.snapshots {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  padding: 0 16px;
}

.snapshots h5 {
  margin: 0 0 4px;
}

pre {
  margin: 0;
  max-height: 320px;
  overflow: auto;
  padding: 8px;
  background: #f5f7fa;
  font-size: 12px;
  white-space: pre-wrap;
  word-break: break-all;
}

.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}
</style>
