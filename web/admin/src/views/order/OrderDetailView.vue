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
  toOptions,
} from '@platform/shared'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { orderApi, type OrderDetail } from '@/api/admin'
import { attemptResultLabels, operationActionLabels, workorderStatusLabels, workorderTypeLabels } from '@/labels'
import { usePermissionStore } from '@/stores/permission'

const route = useRoute()
const id = Number(route.params.id)
const permission = usePermissionStore()

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

/** 话费、卡券：成功订单也能查（看是不是被供应商退款了），异常单能撤单，成功订单能部分退款 */
const isRechargeOrCard = computed(() => ['recharge', 'card'].includes(order.value?.business_line ?? ''))
const canQuery = computed(
  () => ['processing', 'abnormal'].includes(order.value?.status ?? '') || (order.value?.status === 'success' && (isRechargeOrCard.value || order.value?.business_line === 'movie')),
)
const canCancelAtSupplier = computed(() => order.value?.status === 'abnormal' && isRechargeOrCard.value)
const canPartialRefund = computed(() => order.value?.status === 'success' && isRechargeOrCard.value)
const refundable = computed(() => {
  if (!order.value) {
    return '0.00'
  }
  const cents = Math.round(Number(order.value.deducted_amount ?? 0) * 100) - Math.round(Number(order.value.refunded_amount) * 100)
  return (Math.max(cents, 0) / 100).toFixed(2)
})

const refundCheckText: Record<string, string> = {
  refunded: '供应商已全额退款，订单已自动改为已退款并退回商户',
  partial: '供应商部分退款，已生成告警，请核实后用「部分退款」处理',
  none: '供应商没有退款',
}
const canNotify = computed(() => ['success', 'failed', 'cancelled', 'refunded'].includes(order.value?.status ?? ''))

const querying = ref(false)
async function querySupplier() {
  querying.value = true
  try {
    const result = await orderApi.querySupplier(id)
    const text = result.refund_check
      ? `${refundCheckText[result.refund_check]}，订单当前状态：${labelOf(orderStatusLabels, result.order.status)}`
      : `供应商返回：${labelOf(attemptResultLabels, result.result)}${result.fail_reason ? `（${result.fail_reason}）` : ''}，订单当前状态：${labelOf(orderStatusLabels, result.order.status)}`
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

// 发起供应商撤单（只发起，结果看撤单回调或再查一次供应商）
const cancelling = ref(false)
async function cancelAtSupplier() {
  try {
    await ElMessageBox.confirm(
      '向供应商发起撤单。受理不等于撤单成功：请稍后「查询供应商」，确认已撤单（退款）后再「人工处理 → 置失败」解冻。',
      '发起供应商撤单',
      { type: 'warning' },
    )
  } catch {
    return
  }
  cancelling.value = true
  try {
    const result = await orderApi.cancelAtSupplier(id)
    ElMessage({
      type: result.accepted ? 'success' : 'warning',
      message: result.accepted ? `供应商已受理撤单：${result.message}` : `供应商未受理撤单：${result.message}`,
      duration: 6000,
    })
  } finally {
    cancelling.value = false
  }
}

// 快递工单：客服代提交云洋工单（快递售后不对商户开放），结单在「快递工单」列表
const canSubmitWorkorder = computed(
  () =>
    order.value?.business_line === 'express' &&
    !!order.value?.supplier_order_no &&
    !['failed', 'cancelled', 'refunded'].includes(order.value?.status ?? '') &&
    permission.can('aftersale.handle'),
)
const workorderTypeOptions = toOptions(workorderTypeLabels)
const workorderDialog = ref(false)
const workorderSubmitting = ref(false)
const workorderFormRef = ref<FormInstance>()
const workorderForm = reactive({ type: '', content: '' })
const workorderRules: FormRules = {
  type: [{ required: true, message: '请选择工单类型', trigger: 'change' }],
  content: [{ required: true, message: '请填写工单内容', trigger: 'blur' }],
}

function openWorkorder() {
  Object.assign(workorderForm, { type: '', content: '' })
  workorderDialog.value = true
}

async function submitWorkorder() {
  const valid = await workorderFormRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  workorderSubmitting.value = true
  try {
    await orderApi.submitWorkorder(id, { type: workorderForm.type, content: workorderForm.content.trim() })
    ElMessage.success('工单已提交给云洋')
    workorderDialog.value = false
    await load()
  } finally {
    workorderSubmitting.value = false
  }
}

// 部分退款
const refundDialog = ref(false)
const refunding = ref(false)
const refundFormRef = ref<FormInstance>()
const refundForm = reactive({ amount: '', remark: '' })
const refundRules: FormRules = {
  amount: [
    { required: true, message: '请填写退款金额', trigger: 'blur' },
    { pattern: /^\d+(\.\d{1,2})?$/, message: '金额最多两位小数', trigger: 'blur' },
  ],
  remark: [{ required: true, message: '请填写退款说明', trigger: 'blur' }],
}

function openRefund() {
  Object.assign(refundForm, { amount: '', remark: '' })
  refundDialog.value = true
}

async function submitRefund() {
  const valid = await refundFormRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  if (Number(refundForm.amount) <= 0) {
    ElMessage.warning('退款金额必须大于 0')
    return
  }
  if (Number(refundForm.amount) > Number(refundable.value)) {
    ElMessage.warning(`退款金额不能超过可退金额 ${refundable.value} 元`)
    return
  }
  const full = Number(refundForm.amount) === Number(refundable.value)
  const text = full
    ? `退款 ${refundForm.amount} 元等于全部可退金额，订单将改为已退款，返佣作废或扣回。`
    : `退款 ${refundForm.amount} 元退回商户可用余额，订单仍为成功，返佣不变。`
  try {
    await ElMessageBox.confirm(`${text}操作不可撤销，确定吗？`, '确认退款', { type: 'warning' })
  } catch {
    return
  }
  refunding.value = true
  try {
    await orderApi.partialRefund(id, { amount: refundForm.amount, remark: refundForm.remark.trim() })
    ElMessage.success('已退款')
    refundDialog.value = false
    await load()
  } finally {
    refunding.value = false
  }
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
              <el-button v-if="canCancelAtSupplier" :loading="cancelling" @click="cancelAtSupplier">发起供应商撤单</el-button>
              <el-button v-if="order.status === 'abnormal'" type="danger" @click="openResolve">人工处理</el-button>
              <el-button v-if="canPartialRefund" type="warning" @click="openRefund">部分退款</el-button>
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

      <el-card v-if="order.business_line === 'express'" shadow="never">
        <template #header>
          <div class="card-header">
            <span>快递工单</span>
            <span>
              <router-link v-if="permission.can('aftersale.view')" :to="{ name: 'express-workorders' }" class="header-link">
                去工单列表结单
              </router-link>
              <el-button v-if="canSubmitWorkorder" size="small" type="primary" @click="openWorkorder">提交工单</el-button>
            </span>
          </div>
        </template>
        <el-table :data="order.workorders" border empty-text="还没有工单">
          <el-table-column label="类型" width="100">
            <template #default="{ row }">{{ labelOf(workorderTypeLabels, row.type) }}</template>
          </el-table-column>
          <el-table-column label="状态" width="90">
            <template #default="{ row }"><StatusTag :map="workorderStatusLabels" :value="row.status" /></template>
          </el-table-column>
          <el-table-column prop="content" label="内容" min-width="180" />
          <el-table-column label="云洋回复（未验证）" min-width="180">
            <template #default="{ row }">
              {{ row.supplier_reply ?? '-' }}
              <span v-if="row.supplier_amount" class="muted">金额 {{ money(row.supplier_amount) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="处理结果" min-width="160">
            <template #default="{ row }">
              {{ row.result_remark ?? '-' }}
              <span v-if="row.claim_amount" class="muted">理赔调账 {{ money(row.claim_amount) }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="created_at" label="提交时间" width="170" />
        </el-table>
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
          <el-table-column label="动作" width="150">
            <template #default="{ row }">{{ labelOf(operationActionLabels, row.action) }}</template>
          </el-table-column>
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

  <el-dialog v-model="workorderDialog" title="提交快递工单" width="480px" @closed="workorderFormRef?.clearValidate()">
    <el-alert
      type="info"
      show-icon
      :closable="false"
      class="dialog-tip"
      title="工单直接提交给云洋"
      description="重量核实、状态异常退回的运费会按费用调整自动退给商户；理赔款在工单列表结单时按核实金额调账。"
    />
    <el-form ref="workorderFormRef" :model="workorderForm" :rules="workorderRules" label-width="80px">
      <el-form-item label="类型" prop="type">
        <el-select v-model="workorderForm.type" placeholder="请选择" style="width: 100%">
          <el-option v-for="o in workorderTypeOptions" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>
      <el-form-item label="内容" prop="content">
        <el-input v-model="workorderForm.content" type="textarea" :rows="4" maxlength="500" show-word-limit placeholder="说明情况，例如：商户反馈实际重量 2kg，扣费按 5kg" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="workorderDialog = false">取消</el-button>
      <el-button type="primary" :loading="workorderSubmitting" @click="submitWorkorder">提交</el-button>
    </template>
  </el-dialog>

  <el-dialog v-model="refundDialog" title="部分退款" width="480px" @closed="refundFormRef?.clearValidate()">
    <el-alert
      type="info"
      show-icon
      :closable="false"
      class="dialog-tip"
      :title="`可退金额 ${money(refundable)}（已扣款 ${money(order?.deducted_amount)}，已退 ${money(order?.refunded_amount)}）`"
      description="按核实的供应商退款金额退给商户；退满可退金额就是全额退款。"
    />
    <el-form ref="refundFormRef" :model="refundForm" :rules="refundRules" label-width="90px">
      <el-form-item label="退款金额" prop="amount">
        <el-input v-model="refundForm.amount" placeholder="例如 20.00">
          <template #append>元</template>
        </el-input>
      </el-form-item>
      <el-form-item label="退款说明" prop="remark">
        <el-input v-model="refundForm.remark" type="textarea" :rows="3" maxlength="200" show-word-limit placeholder="例如：供应商只充值到账 50 元，退还差额" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="refundDialog = false">取消</el-button>
      <el-button type="warning" :loading="refunding" @click="submitRefund">提交</el-button>
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

.dialog-tip {
  margin-bottom: 16px;
}

.header-link {
  margin-right: 12px;
  font-size: 13px;
}

.muted {
  margin-left: 4px;
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

pre.inline {
  max-height: 120px;
  padding: 4px;
}
</style>
