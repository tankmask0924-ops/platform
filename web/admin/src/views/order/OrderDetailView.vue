<script setup lang="ts">
import {
  balanceLogTypeLabels,
  businessLineLabels,
  ExpressDetailInfo,
  labelOf,
  MovieDetailInfo,
  money,
  orderStatusLabels,
  ratePercent,
  rebateStatusLabels,
  StatusTag,
} from '@platform/shared'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { orderApi, type OrderDetail } from '@/api/admin'
import { attemptResultLabels } from '@/labels'

const route = useRoute()
const id = Number(route.params.id)

const order = ref<OrderDetail | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    order.value = await orderApi.detail(id)
  } finally {
    loading.value = false
  }
}

const canQuery = computed(() => ['processing', 'abnormal'].includes(order.value?.status ?? ''))
const canNotify = computed(() => ['success', 'failed', 'cancelled', 'refunded'].includes(order.value?.status ?? ''))

const querying = ref(false)
async function querySupplier() {
  querying.value = true
  try {
    const result = await orderApi.querySupplier(id)
    const text = `供应商返回：${labelOf(attemptResultLabels, result.result)}${result.fail_reason ? `（${result.fail_reason}）` : ''}，订单当前状态：${labelOf(orderStatusLabels, result.order.status)}`
    ElMessage({ type: result.result === 'success' ? 'success' : 'info', message: text, duration: 6000 })
    await load()
  } finally {
    querying.value = false
  }
}

async function renotify() {
  try {
    await ElMessageBox.confirm('立即向商户回调地址重新推送一次订单结果？', '重推回调', { type: 'warning' })
  } catch {
    return
  }
  await orderApi.renotify(id)
  ElMessage.success('已重推')
  await load()
}

// 异常单人工处理
const resolveDialog = ref(false)
const resolving = ref(false)
const resolveFormRef = ref<FormInstance>()
const resolveForm = reactive({ result: 'failed' as 'success' | 'failed', remark: '', supplier_order_no: '' })
const resolveRules: FormRules = {
  remark: [{ required: true, message: '请填写处理说明', trigger: 'blur' }],
}

function openResolve() {
  Object.assign(resolveForm, { result: 'failed', remark: '', supplier_order_no: order.value?.supplier_order_no ?? '' })
  resolveDialog.value = true
}

async function submitResolve() {
  const valid = await resolveFormRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  const text =
    resolveForm.result === 'success'
      ? '置为成功：扣除冻结金额、生成返佣，并通知商户。'
      : '置为失败：解冻金额退回商户可用余额，并通知商户。'
  try {
    await ElMessageBox.confirm(`${text}操作不可撤销，确定吗？`, '确认处理', { type: 'warning' })
  } catch {
    return
  }
  resolving.value = true
  try {
    await orderApi.resolve(id, {
      result: resolveForm.result,
      remark: resolveForm.remark.trim(),
      supplier_order_no: resolveForm.supplier_order_no.trim() || undefined,
    })
    ElMessage.success('已处理')
    resolveDialog.value = false
    await load()
  } finally {
    resolving.value = false
  }
}

function json(value: unknown): string {
  return value === null || value === undefined ? '-' : JSON.stringify(value, null, 2)
}

onMounted(load)
</script>

<template>
  <div v-loading="loading" class="page">
    <template v-if="order">
      <el-alert
        v-if="order.status === 'abnormal'"
        type="error"
        show-icon
        :closable="false"
        title="异常单：长时间没有拿到供应商结果，需要人工处理"
        description="建议先「查询供应商」确认实际结果；卡密类订单只能在供应商确认成功并返回卡密后置成功。"
      />

      <el-card shadow="never">
        <template #header>
          <div class="card-header">
            <span>订单 {{ order.order_no }}</span>
            <div>
              <el-button v-if="canQuery" :loading="querying" @click="querySupplier">查询供应商</el-button>
              <el-button v-if="order.status === 'abnormal'" type="danger" @click="openResolve">人工处理</el-button>
              <el-button v-if="canNotify" @click="renotify">重推回调</el-button>
            </div>
          </div>
        </template>
        <el-descriptions :column="3" border>
          <el-descriptions-item label="ID">{{ order.id }}</el-descriptions-item>
          <el-descriptions-item label="状态"><StatusTag :map="orderStatusLabels" :value="order.status" /></el-descriptions-item>
          <el-descriptions-item label="业务">{{ labelOf(businessLineLabels, order.business_line) }}</el-descriptions-item>
          <el-descriptions-item label="商户">
            <router-link :to="{ name: 'merchant-detail', params: { id: order.merchant_id } }">#{{ order.merchant_id }}</router-link>
          </el-descriptions-item>
          <el-descriptions-item label="商户单号" :span="2">{{ order.merchant_order_no }}</el-descriptions-item>
          <el-descriptions-item label="售价">{{ money(order.sale_price) }}</el-descriptions-item>
          <el-descriptions-item label="成本价">{{ money(order.cost_price) }}</el-descriptions-item>
          <el-descriptions-item label="冻结">{{ money(order.frozen_amount) }}</el-descriptions-item>
          <el-descriptions-item label="实扣">{{ money(order.deducted_amount) }}</el-descriptions-item>
          <el-descriptions-item label="已退款">{{ money(order.refunded_amount) }}</el-descriptions-item>
          <el-descriptions-item label="供应商">{{ order.supplier_name ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="供应商单号">{{ order.supplier_order_no ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="失败原因" :span="2">{{ order.fail_reason ?? '-' }}</el-descriptions-item>
          <template v-if="order.recharge">
            <el-descriptions-item label="充值账号">{{ order.recharge.recharge_account ?? '-' }}</el-descriptions-item>
            <el-descriptions-item label="本地商品">
              <router-link :to="{ name: 'product-detail', params: { id: order.recharge.product_id } }">#{{ order.recharge.product_id }}</router-link>
            </el-descriptions-item>
            <el-descriptions-item label="卡密">{{ order.recharge.has_card_secret ? '已发放（不在后台显示）' : '-' }}</el-descriptions-item>
          </template>
          <el-descriptions-item label="回调地址" :span="3">{{ order.callback_url ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="下单时间">{{ order.created_at }}</el-descriptions-item>
          <el-descriptions-item label="完成时间">{{ order.completed_at ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="结束时间">{{ order.finished_at ?? '-' }}</el-descriptions-item>
        </el-descriptions>
      </el-card>

      <el-card v-if="order.express" shadow="never" header="快递明细">
        <ExpressDetailInfo :detail="order.express" />
      </el-card>

      <el-card v-if="order.movie" shadow="never" header="电影票明细">
        <MovieDetailInfo :detail="order.movie" />
      </el-card>

      <el-card shadow="never" header="返佣">
        <el-descriptions v-if="order.rebate" :column="3" border>
          <el-descriptions-item label="金额">{{ money(order.rebate.amount) }}</el-descriptions-item>
          <el-descriptions-item label="比例">{{ ratePercent(order.rebate.rebate_rate) }}</el-descriptions-item>
          <el-descriptions-item label="状态"><StatusTag :map="rebateStatusLabels" :value="order.rebate.status" /></el-descriptions-item>
          <el-descriptions-item label="预计到账">{{ order.rebate.due_at ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="到账时间">{{ order.rebate.settled_at ?? '-' }}</el-descriptions-item>
        </el-descriptions>
        <el-empty v-else description="无返佣记录" :image-size="60" />
      </el-card>

      <el-card shadow="never" header="供应商尝试记录">
        <el-table :data="order.attempts" border size="small" empty-text="无">
          <el-table-column type="expand">
            <template #default="{ row }">
              <div class="snapshots">
                <div>
                  <h5>请求</h5>
                  <pre>{{ json(row.request_snapshot) }}</pre>
                </div>
                <div>
                  <h5>响应</h5>
                  <pre>{{ json(row.response_snapshot) }}</pre>
                </div>
              </div>
            </template>
          </el-table-column>
          <el-table-column prop="attempt_no" label="次序" width="60" />
          <el-table-column label="供应商" min-width="120">
            <template #default="{ row }">{{ row.supplier_name ?? `#${row.supplier_id}` }}</template>
          </el-table-column>
          <el-table-column label="结果" width="100">
            <template #default="{ row }"><StatusTag :map="attemptResultLabels" :value="row.result" /></template>
          </el-table-column>
          <el-table-column label="失败原因" min-width="160" show-overflow-tooltip>
            <template #default="{ row }">{{ row.fail_reason ?? '-' }}</template>
          </el-table-column>
          <el-table-column prop="created_at" label="发起时间" width="170" />
          <el-table-column prop="updated_at" label="更新时间" width="170" />
        </el-table>
      </el-card>

      <el-card shadow="never" header="资金流水">
        <el-table :data="order.balance_logs" border size="small" empty-text="无">
          <el-table-column prop="created_at" label="时间" width="170" />
          <el-table-column label="类型" width="100">
            <template #default="{ row }"><StatusTag :map="balanceLogTypeLabels" :value="row.type" /></template>
          </el-table-column>
          <el-table-column prop="amount" label="金额" width="110" align="right" />
          <el-table-column prop="available_after" label="变动后可用" width="130" align="right" />
          <el-table-column prop="frozen_after" label="变动后冻结" width="130" align="right" />
        </el-table>
      </el-card>

      <el-card shadow="never" header="商户回调记录">
        <el-table :data="order.notify_logs" border size="small" empty-text="无">
          <el-table-column prop="attempt_no" label="次数" width="60" />
          <el-table-column label="结果" width="70">
            <template #default="{ row }">
              <el-tag :type="row.success ? 'success' : 'danger'" size="small">{{ row.success ? '成功' : '失败' }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="HTTP" width="70">
            <template #default="{ row }">{{ row.http_status ?? '-' }}</template>
          </el-table-column>
          <el-table-column prop="url" label="地址" min-width="200" show-overflow-tooltip />
          <el-table-column prop="response_body" label="响应" min-width="160" show-overflow-tooltip />
          <el-table-column prop="created_at" label="时间" width="170" />
        </el-table>
      </el-card>

      <el-card shadow="never" header="后台操作记录">
        <el-table :data="order.operation_logs" border size="small" empty-text="无">
          <el-table-column prop="created_at" label="时间" width="170" />
          <el-table-column label="管理员" width="90">
            <template #default="{ row }">#{{ row.admin_user_id }}</template>
          </el-table-column>
          <el-table-column prop="action" label="动作" width="150" />
          <el-table-column label="内容" min-width="240">
            <template #default="{ row }"><pre class="inline">{{ json(row.after) }}</pre></template>
          </el-table-column>
        </el-table>
      </el-card>
    </template>
  </div>

  <el-dialog v-model="resolveDialog" title="异常单人工处理" width="520px" @closed="resolveFormRef?.clearValidate()">
    <el-form ref="resolveFormRef" :model="resolveForm" :rules="resolveRules" label-width="100px">
      <el-form-item label="处理结果">
        <el-radio-group v-model="resolveForm.result">
          <el-radio-button value="failed">置为失败（退款）</el-radio-button>
          <el-radio-button value="success">置为成功（扣款）</el-radio-button>
        </el-radio-group>
      </el-form-item>
      <el-form-item v-if="resolveForm.result === 'success'" label="供应商单号">
        <el-input v-model="resolveForm.supplier_order_no" maxlength="64" placeholder="选填" />
      </el-form-item>
      <el-form-item label="处理说明" prop="remark">
        <el-input v-model="resolveForm.remark" type="textarea" :rows="3" maxlength="255" show-word-limit placeholder="例如：已与供应商客服确认未充值" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="resolveDialog = false">取消</el-button>
      <el-button type="danger" :loading="resolving" @click="submitResolve">提交</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.page {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-height: 200px;
}

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
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

pre.inline {
  max-height: 120px;
  padding: 4px;
}
</style>
