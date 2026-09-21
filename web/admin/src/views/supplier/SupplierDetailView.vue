<script setup lang="ts">
import { businessLineLabels, copyText, labelOf, money, StatusTag, toOptions, usePagedList } from '@platform/shared'
import { ElMessage, ElMessageBox } from 'element-plus'
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { type CircuitBreakerRow, type CircuitBreakerStatus, type SupplierCallLog, supplierApi, type SupplierDetail, type SupplierStats, type SupplierStatsRow } from '@/api/admin'
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

// 熔断（requirements.md 6.6）
const breakers = ref<CircuitBreakerStatus | null>(null)
const breakersLoading = ref(false)

async function loadBreakers() {
  breakersLoading.value = true
  try {
    breakers.value = await supplierApi.circuitBreakers(id)
  } finally {
    breakersLoading.value = false
  }
}

const pauseVisible = ref(false)
const pauseSubmitting = ref(false)
const pauseForm = ref<{ product_id: number | null; minutes: number | null; remark: string }>({
  product_id: null,
  minutes: null,
  remark: '',
})

function openPause() {
  pauseForm.value = { product_id: null, minutes: breakers.value?.thresholds.pause_minutes ?? 5, remark: '' }
  pauseVisible.value = true
}

async function submitPause() {
  if (pauseForm.value.remark.trim() === '') {
    ElMessage.warning('请填写暂停原因')

    return
  }
  pauseSubmitting.value = true
  try {
    breakers.value = await supplierApi.pauseCircuitBreaker(id, {
      product_id: pauseForm.value.product_id ?? '',
      minutes: pauseForm.value.minutes ?? '',
      remark: pauseForm.value.remark.trim(),
    })
    pauseVisible.value = false
    ElMessage.success('已暂停，路由不再给这个范围分配新订单')
  } finally {
    pauseSubmitting.value = false
  }
}

async function resumeBreaker(row: CircuitBreakerRow) {
  const scope = row.product_id === null ? '整个供应商' : `商品「${row.product_name ?? row.product_id}」`
  await ElMessageBox.confirm(`恢复${scope}的分单？`, '恢复', { type: 'warning' })
  breakers.value = await supplierApi.resumeCircuitBreaker(id, { product_id: row.product_id ?? '' })
  ElMessage.success('已恢复')
}

/** 无限期暂停没有截止时间 */
function pausedUntilText(row: CircuitBreakerRow): string {
  if (row.status !== 'paused') {
    return '-'
  }
  return row.paused_until ?? '无限期（需人工恢复）'
}

// 统计（requirements.md 6.8：订单量、成功率、平均到账时长、成本总额、供应商返佣总额，按天/商品看）
const stats = ref<SupplierStats | null>(null)
const statsLoading = ref(false)
const statsGroupBy = ref<'day' | 'product'>('day')
const statsRange = ref<[string, string] | null>(null)

async function loadStats() {
  statsLoading.value = true
  try {
    stats.value = await supplierApi.stats(id, {
      group_by: statsGroupBy.value,
      created_from: statsRange.value?.[0] ?? '',
      created_to: statsRange.value?.[1] ?? '',
    })
    // 不传时间时后端按最近 7 天算，回填给日期框，免得看起来像"全部时间"
    statsRange.value = [stats.value.created_from, stats.value.created_to]
  } finally {
    statsLoading.value = false
  }
}

const percent = (value: number | null) => (value === null ? '-' : `${value}%`)

/** 到账时长：后端给的是秒 */
function duration(seconds: number | null): string {
  if (seconds === null) {
    return '-'
  }
  if (seconds < 60) {
    return `${seconds} 秒`
  }
  return `${Math.floor(seconds / 60)} 分 ${Math.round(seconds % 60)} 秒`
}

/** 成功率低于 90% 且有一定单量时标红，调优先级时一眼能看到 */
function rateDanger(row: SupplierStatsRow): boolean {
  return row.success_rate !== null && row.success_rate < 90 && row.success_count + row.failed_count >= 10
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
  loadBreakers()
  loadStats()
  logs.load()
})
</script>

<template>
  <div v-loading="loading" class="page">
    <template v-if="supplier">
      <el-alert
        v-if="supplier.config_unreadable"
        type="error"
        show-icon
        :closable="false"
        title="接口配置无法解密，请重新填写"
        description="这条供应商的接口配置是用别的加密密钥存的（或数据已损坏），当前密钥读不出来。下单、查余额、同步商品都会失败，需要在供应商列表点「编辑」把配置整体重新填一遍。"
      />

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

    <el-card v-loading="breakersLoading" shadow="never">
      <template #header>
        <div class="card-header">
          <span>
            熔断状态
            <el-tag v-if="breakers?.supplier_paused" type="danger" size="small" class="gap-left">整个供应商熔断中</el-tag>
          </span>
          <el-button v-if="canManage" @click="openPause">手动暂停</el-button>
        </div>
      </template>

      <div v-if="breakers" class="muted block">
        当前阈值：近 {{ breakers.thresholds.window_minutes }} 分钟内出结果的订单达到
        {{ breakers.thresholds.min_orders }} 单、且失败率超过 {{ breakers.thresholds.fail_rate_percent }}% 时，
        自动暂停分单 {{ breakers.thresholds.pause_minutes }} 分钟，到期自动恢复。阈值在「系统设置 - 系统参数」里改。
      </div>

      <el-table :data="breakers?.data ?? []" border size="small" empty-text="没有熔断记录，这家供应商一直正常">
        <el-table-column label="范围" min-width="200">
          <template #default="{ row }">
            <span v-if="row.product_id === null">整个供应商</span>
            <router-link v-else :to="{ name: 'product-detail', params: { id: row.product_id } }">
              {{ row.product_name ?? `#${row.product_id}` }}
            </router-link>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="120">
          <template #default="{ row }">
            <el-tag v-if="row.status === 'paused'" type="danger" size="small">熔断中</el-tag>
            <el-tag v-else type="success" size="small">正常</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="来源" width="100">
          <!-- 恢复后 triggered_reason 被清空，这时已经无所谓来源了，显示 - 而不是误标成"自动" -->
          <template #default="{ row }">{{ row.triggered_reason === null ? '-' : row.manual ? '人工' : '自动' }}</template>
        </el-table-column>
        <el-table-column label="恢复时间" width="190">
          <template #default="{ row }">{{ pausedUntilText(row as CircuitBreakerRow) }}</template>
        </el-table-column>
        <el-table-column prop="triggered_reason" label="原因" min-width="280">
          <template #default="{ row }">{{ row.triggered_reason ?? '-' }}</template>
        </el-table-column>
        <el-table-column prop="updated_at" label="更新时间" width="170" />
        <el-table-column v-if="canManage" label="操作" width="90">
          <template #default="{ row }">
            <el-button v-if="row.status === 'paused'" link type="primary" @click="resumeBreaker(row as CircuitBreakerRow)">恢复</el-button>
            <span v-else>-</span>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-card shadow="never" header="统计">
      <el-form inline @submit.prevent="loadStats">
        <el-form-item label="维度">
          <el-radio-group v-model="statsGroupBy" @change="loadStats">
            <el-radio-button value="day">按天</el-radio-button>
            <el-radio-button value="product">按商品</el-radio-button>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="时间">
          <el-date-picker
            v-model="statsRange"
            type="daterange"
            value-format="YYYY-MM-DD"
            start-placeholder="开始"
            end-placeholder="结束"
            style="width: 240px"
            @change="loadStats"
          />
        </el-form-item>
        <el-form-item>
          <span class="muted">最多查 92 天；成功率的分母只算已经出结果的，处理中不算</span>
        </el-form-item>
      </el-form>

      <div v-if="stats" v-loading="statsLoading">
        <div class="tiles">
          <div class="tile">
            <div class="tile-label">订单量</div>
            <div class="tile-value">{{ stats.summary.order_count }}</div>
            <div class="muted">成功 {{ stats.summary.success_count }} / 失败 {{ stats.summary.failed_count }} / 处理中 {{ stats.summary.pending_count }}</div>
          </div>
          <div class="tile">
            <div class="tile-label">成功率</div>
            <div class="tile-value" :class="{ danger: rateDanger(stats.summary) }">{{ percent(stats.summary.success_rate) }}</div>
          </div>
          <div class="tile">
            <div class="tile-label">平均到账时长</div>
            <div class="tile-value">{{ duration(stats.summary.avg_delivery_seconds) }}</div>
          </div>
          <div class="tile">
            <div class="tile-label">成本总额</div>
            <div class="tile-value">{{ money(stats.summary.cost_total) }}</div>
          </div>
          <div class="tile">
            <div class="tile-label">供应商返佣总额</div>
            <div class="tile-value">{{ money(stats.summary.supplier_rebate_total) }}</div>
            <div class="muted">话费、卡券没有供应商返佣</div>
          </div>
        </div>

        <el-table :data="stats.data" border size="small" empty-text="这段时间没有分给这家供应商的订单">
          <el-table-column :label="stats.group_by === 'day' ? '日期' : '商品'" min-width="180">
            <template #default="{ row }">
              <router-link v-if="row.product_id" :to="{ name: 'product-detail', params: { id: row.product_id } }">{{ row.label }}</router-link>
              <span v-else>{{ row.label }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="order_count" label="订单量" width="90" align="right" />
          <el-table-column prop="success_count" label="成功" width="80" align="right" />
          <el-table-column prop="failed_count" label="失败" width="80" align="right" />
          <el-table-column label="处理中" width="90" align="right">
            <template #default="{ row }">{{ row.pending_count }}</template>
          </el-table-column>
          <el-table-column label="成功率" width="100" align="right">
            <template #default="{ row }">
              <span :class="{ danger: rateDanger(row as SupplierStatsRow) }">{{ percent(row.success_rate) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="平均到账时长" width="130" align="right">
            <template #default="{ row }">{{ duration(row.avg_delivery_seconds) }}</template>
          </el-table-column>
          <el-table-column label="成本总额" width="120" align="right">
            <template #default="{ row }">{{ money(row.cost_total) }}</template>
          </el-table-column>
          <el-table-column label="供应商返佣" width="120" align="right">
            <template #default="{ row }">{{ money(row.supplier_rebate_total) }}</template>
          </el-table-column>
        </el-table>
      </div>
    </el-card>

    <el-dialog v-model="pauseVisible" title="手动暂停分单" width="520px">
      <el-form label-width="90px">
        <el-form-item label="范围">
          <el-select v-model="pauseForm.product_id" clearable placeholder="整个供应商" style="width: 100%">
            <el-option v-for="p in breakers?.mapped_products ?? []" :key="p.id" :value="p.id" :label="p.name" />
          </el-select>
          <div class="muted">留空＝暂停这家供应商的全部商品；选一个商品＝只暂停这个商品，不影响其他商品</div>
        </el-form-item>
        <el-form-item label="暂停时长">
          <el-input-number v-model="pauseForm.minutes" :min="1" :max="1440" controls-position="right" />
          <span class="muted gap-left">分钟，最多 1440；清空表示无限期，只能人工恢复</span>
        </el-form-item>
        <el-form-item label="原因" required>
          <el-input v-model="pauseForm.remark" type="textarea" :rows="2" maxlength="200" show-word-limit placeholder="必填，会记在熔断记录上" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="pauseVisible = false">取消</el-button>
        <el-button type="primary" :loading="pauseSubmitting" @click="submitPause">确定</el-button>
      </template>
    </el-dialog>

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

.tiles {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}

.tile {
  padding: 12px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
}

.tile-label {
  color: #909399;
  font-size: 12px;
}

.tile-value {
  margin: 4px 0;
  font-size: 20px;
  font-weight: 600;
}

.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}
</style>
