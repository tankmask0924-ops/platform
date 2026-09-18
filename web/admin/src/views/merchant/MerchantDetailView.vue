<script setup lang="ts">
import {
  balanceLogTypeLabels,
  isHttpUrl,
  isNegative,
  labelOf,
  merchantStatusLabels,
  merchantTypeLabels,
  money,
  qualificationStatusLabels,
  StatusTag,
  toOptions,
  usePagedList,
} from '@platform/shared'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { type BalanceLog, merchantApi, type MerchantDetail } from '@/api/admin'
import { useLevels } from '@/composables/useLevels'
import { usePermissionStore } from '@/stores/permission'

const route = useRoute()
const permission = usePermissionStore()
const id = Number(route.params.id)
const { levels, ensure, levelName } = useLevels()

const merchant = ref<MerchantDetail | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    merchant.value = await merchantApi.detail(id)
  } finally {
    loading.value = false
  }
}

const isPending = computed(() => merchant.value?.status === 'pending')
const isReviewed = computed(() => ['active', 'disabled'].includes(merchant.value?.status ?? ''))

// 审核
const approveLevelId = ref<number | undefined>()
const reviewing = ref(false)

async function approve() {
  if (!approveLevelId.value) {
    ElMessage.warning('请先选择商户等级')
    return
  }
  reviewing.value = true
  try {
    await merchantApi.approve(id, approveLevelId.value)
    ElMessage.success('已审核通过')
    await load()
  } finally {
    reviewing.value = false
  }
}

async function reject() {
  let reason: string
  try {
    const result = await ElMessageBox.prompt('驳回原因（商户可见）', '驳回入驻申请', {
      inputValidator: (value) => (value && value.trim() !== '' ? true : '请填写驳回原因'),
      type: 'warning',
    })
    reason = result.value.trim()
  } catch {
    return
  }
  reviewing.value = true
  try {
    await merchantApi.reject(id, reason)
    ElMessage.success('已驳回')
    await load()
  } finally {
    reviewing.value = false
  }
}

// 启用 / 禁用
async function toggleStatus() {
  if (!merchant.value) {
    return
  }
  const next = merchant.value.status === 'active' ? 'disabled' : 'active'
  const text = next === 'disabled' ? '禁用后商户无法登录后台，开放 API 调用也会被拒绝。确定禁用吗？' : '确定启用该商户吗？'
  try {
    await ElMessageBox.confirm(text, next === 'disabled' ? '禁用商户' : '启用商户', { type: 'warning' })
  } catch {
    return
  }
  await merchantApi.setStatus(id, next)
  ElMessage.success('已更新')
  await load()
}

// 调整等级
const levelDialog = ref(false)
const newLevelId = ref<number | undefined>()

function openLevelDialog() {
  newLevelId.value = merchant.value?.level_id ?? undefined
  levelDialog.value = true
}

async function saveLevel() {
  if (!newLevelId.value) {
    return
  }
  await merchantApi.setLevel(id, newLevelId.value)
  ElMessage.success('等级已调整')
  levelDialog.value = false
  await load()
}

// 限流
const rateDialog = ref(false)
const rateLimit = ref(1)

function openRateDialog() {
  rateLimit.value = merchant.value?.rate_limit.limit_per_second ?? 1
  rateDialog.value = true
}

async function saveRateLimit() {
  const result = await merchantApi.setRateLimit(id, rateLimit.value)
  if (merchant.value) {
    merchant.value.rate_limit = result
  }
  ElMessage.success('限流已设置')
  rateDialog.value = false
}

async function resetRateLimit() {
  try {
    await ElMessageBox.confirm('恢复为系统默认的限流值？', '恢复默认', { type: 'warning' })
  } catch {
    return
  }
  const result = await merchantApi.resetRateLimit(id)
  if (merchant.value) {
    merchant.value.rate_limit = result
  }
  ElMessage.success('已恢复默认')
}

// 手动调账
const adjustDialog = ref(false)
const adjusting = ref(false)
const adjustFormRef = ref<FormInstance>()
const adjustForm = reactive({ direction: 'add' as 'add' | 'subtract', amount: '', reason: '' })
const adjustRules: FormRules = {
  amount: [
    { required: true, message: '请输入金额', trigger: 'blur' },
    { pattern: /^\d+(\.\d{1,2})?$/, message: '金额格式不正确，最多两位小数', trigger: 'blur' },
    {
      validator: (_rule, value: string, callback) => callback(Number(value) > 0 ? undefined : new Error('金额必须大于 0')),
      trigger: 'blur',
    },
  ],
  reason: [{ required: true, message: '请填写调账原因', trigger: 'blur' }],
}

function openAdjustDialog() {
  Object.assign(adjustForm, { direction: 'add', amount: '', reason: '' })
  adjustDialog.value = true
}

async function submitAdjust() {
  const valid = await adjustFormRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  const amount = adjustForm.direction === 'add' ? adjustForm.amount : `-${adjustForm.amount}`
  try {
    await ElMessageBox.confirm(
      `确定对商户 #${id} ${adjustForm.direction === 'add' ? '加款' : '扣款'} ¥${adjustForm.amount} 吗？提交后立即生效${adjustForm.direction === 'subtract' ? '，余额不足时会扣成负数' : ''}。`,
      '确认调账',
      { type: 'warning' },
    )
  } catch {
    return
  }
  adjusting.value = true
  try {
    await merchantApi.adjustBalance(id, amount, adjustForm.reason.trim())
    ElMessage.success('调账成功')
    adjustDialog.value = false
    await Promise.all([load(), logs.search()])
  } finally {
    adjusting.value = false
  }
}

// 资金流水
const logs = usePagedList<BalanceLog, { type: string }>((params) => merchantApi.balanceLogs(id, params), { type: '' })
const typeOptions = toOptions(balanceLogTypeLabels)

onMounted(() => {
  load()
  ensure()
  logs.load()
})
</script>

<template>
  <div v-loading="loading" class="page">
    <template v-if="merchant">
      <el-card shadow="never">
        <template #header>
          <div class="card-header">
            <span>商户 #{{ merchant.id }}</span>
            <div>
              <el-button v-if="isReviewed" @click="openLevelDialog">调整等级</el-button>
              <el-button v-if="isReviewed" :type="merchant.status === 'active' ? 'danger' : 'success'" plain @click="toggleStatus">
                {{ merchant.status === 'active' ? '禁用' : '启用' }}
              </el-button>
            </div>
          </div>
        </template>
        <el-descriptions :column="3" border>
          <el-descriptions-item label="类型">{{ labelOf(merchantTypeLabels, merchant.type) }}</el-descriptions-item>
          <el-descriptions-item label="状态"><StatusTag :map="merchantStatusLabels" :value="merchant.status" /></el-descriptions-item>
          <el-descriptions-item label="等级">{{ levelName(merchant.level_id) }}</el-descriptions-item>
          <el-descriptions-item label="手机号">{{ merchant.phone ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="邮箱">{{ merchant.email ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="注册时间">{{ merchant.created_at }}</el-descriptions-item>
          <el-descriptions-item label="可用余额">
            <span :class="{ negative: isNegative(merchant.available_balance) }">{{ money(merchant.available_balance) }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="冻结金额">{{ money(merchant.frozen_balance) }}</el-descriptions-item>
          <el-descriptions-item label="欠款开始">{{ merchant.debt_since ?? '-' }}</el-descriptions-item>
          <el-descriptions-item v-if="permission.can('rebate.view')" label="返佣">
            <router-link :to="{ name: 'rebates', query: { merchant_id: merchant.id } }">查看返佣明细</router-link>
          </el-descriptions-item>
          <el-descriptions-item label="限流">
            {{ merchant.rate_limit.limit_per_second }} 次/秒
            <el-tag size="small" :type="merchant.rate_limit.is_custom ? 'warning' : 'info'">
              {{ merchant.rate_limit.is_custom ? '单独设置' : '系统默认' }}
            </el-tag>
            <el-button link type="primary" @click="openRateDialog">设置</el-button>
            <el-button v-if="merchant.rate_limit.is_custom" link type="primary" @click="resetRateLimit">恢复默认</el-button>
          </el-descriptions-item>
        </el-descriptions>
      </el-card>

      <el-card shadow="never" header="资质资料">
        <template v-if="merchant.qualification">
          <el-descriptions :column="2" border>
            <template v-if="merchant.qualification.type === 'company'">
              <el-descriptions-item label="公司名称">{{ merchant.qualification.company_name }}</el-descriptions-item>
              <el-descriptions-item label="营业执照号">{{ merchant.qualification.business_license_no }}</el-descriptions-item>
              <el-descriptions-item label="法人">{{ merchant.qualification.legal_person_name }}</el-descriptions-item>
              <el-descriptions-item label="联系人">{{ merchant.qualification.contact_name }}</el-descriptions-item>
              <el-descriptions-item label="营业执照图片" :span="2">
                <el-link v-if="isHttpUrl(merchant.qualification.business_license_image)" :href="merchant.qualification.business_license_image" target="_blank" type="primary">查看</el-link>
                <span v-else>{{ merchant.qualification.business_license_image || '未上传' }}</span>
              </el-descriptions-item>
            </template>
            <template v-else>
              <el-descriptions-item label="姓名">{{ merchant.qualification.id_card_name }}</el-descriptions-item>
              <el-descriptions-item label="身份证号">{{ merchant.qualification.id_card_no }}</el-descriptions-item>
              <el-descriptions-item label="身份证图片" :span="2">
                <template v-if="merchant.qualification.id_card_images?.length">
                  <template v-for="(url, i) in merchant.qualification.id_card_images" :key="i">
                    <el-link v-if="isHttpUrl(url)" :href="url" target="_blank" type="primary" class="gap">图片 {{ i + 1 }}</el-link>
                    <span v-else class="gap">{{ url }}</span>
                  </template>
                </template>
                <span v-else>未上传</span>
              </el-descriptions-item>
            </template>
            <el-descriptions-item label="联系电话">{{ merchant.qualification.contact_phone }}</el-descriptions-item>
            <el-descriptions-item label="审核状态">
              <StatusTag :map="qualificationStatusLabels" :value="merchant.qualification.status" />
            </el-descriptions-item>
            <el-descriptions-item label="提交时间">{{ merchant.qualification.submitted_at ?? '-' }}</el-descriptions-item>
            <el-descriptions-item label="审核时间">{{ merchant.qualification.reviewed_at ?? '-' }}</el-descriptions-item>
            <el-descriptions-item label="驳回原因" :span="2">{{ merchant.qualification.reject_reason ?? '-' }}</el-descriptions-item>
          </el-descriptions>

          <template v-if="merchant.qualification_history.length > 1">
            <div class="history-title">历次提交</div>
            <el-table :data="merchant.qualification_history" border size="small">
              <el-table-column prop="submitted_at" label="提交时间" width="170" />
              <el-table-column label="类型" width="80">
                <template #default="{ row }">{{ labelOf(merchantTypeLabels, row.type) }}</template>
              </el-table-column>
              <el-table-column label="结果" width="90">
                <template #default="{ row }"><StatusTag :map="qualificationStatusLabels" :value="row.status" /></template>
              </el-table-column>
              <el-table-column label="驳回原因" min-width="200">
                <template #default="{ row }">{{ row.reject_reason ?? '-' }}</template>
              </el-table-column>
              <el-table-column label="审核时间" width="170">
                <template #default="{ row }">{{ row.reviewed_at ?? '-' }}</template>
              </el-table-column>
            </el-table>
          </template>

          <div v-if="isPending" class="review">
            <span>审核通过并分配等级：</span>
            <el-select v-model="approveLevelId" placeholder="选择商户等级" style="width: 200px">
              <el-option v-for="level in levels" :key="level.id" :value="level.id" :label="level.name" />
            </el-select>
            <el-button type="primary" :loading="reviewing" @click="approve">通过</el-button>
            <el-button type="danger" plain :loading="reviewing" @click="reject">驳回</el-button>
            <span v-if="levels.length === 0" class="muted">还没有商户等级，请先到「商户等级」创建</span>
          </div>
        </template>
        <el-empty v-else description="没有资质资料" />
      </el-card>

      <el-card shadow="never">
        <template #header>
          <div class="card-header">
            <span>资金流水</span>
            <div>
              <el-select v-model="logs.filters.type" clearable placeholder="全部类型" style="width: 140px" @change="logs.search">
                <el-option v-for="o in typeOptions" :key="o.value" :value="o.value" :label="o.label" />
              </el-select>
              <el-button v-if="isReviewed" type="primary" class="gap-left" @click="openAdjustDialog">手动调账</el-button>
            </div>
          </div>
        </template>
        <el-table v-loading="logs.loading.value" :data="logs.rows.value" border size="small">
          <el-table-column prop="created_at" label="时间" width="170" />
          <el-table-column label="类型" width="100">
            <template #default="{ row }"><StatusTag :map="balanceLogTypeLabels" :value="row.type" /></template>
          </el-table-column>
          <el-table-column prop="amount" label="金额" width="110" align="right" />
          <el-table-column label="可用（前 → 后）" min-width="180">
            <template #default="{ row }">{{ row.available_before }} → {{ row.available_after }}</template>
          </el-table-column>
          <el-table-column label="冻结（前 → 后）" min-width="180">
            <template #default="{ row }">{{ row.frozen_before }} → {{ row.frozen_after }}</template>
          </el-table-column>
          <el-table-column label="订单" width="90">
            <template #default="{ row }">
              <router-link v-if="row.order_id" :to="{ name: 'order-detail', params: { id: row.order_id } }">#{{ row.order_id }}</router-link>
              <span v-else>-</span>
            </template>
          </el-table-column>
          <el-table-column label="说明" min-width="180">
            <template #default="{ row }">{{ row.reason ?? '-' }}</template>
          </el-table-column>
          <el-table-column label="操作人" width="80">
            <template #default="{ row }">{{ row.operator_id ?? '-' }}</template>
          </el-table-column>
        </el-table>
        <el-pagination
          v-model:current-page="logs.page.value"
          v-model:page-size="logs.perPage.value"
          class="pagination"
          layout="total, sizes, prev, pager, next"
          :total="logs.total.value"
          @current-change="logs.load"
          @size-change="logs.search"
        />
      </el-card>
    </template>
  </div>

  <el-dialog v-model="levelDialog" title="调整商户等级" width="420px">
    <el-select v-model="newLevelId" placeholder="选择商户等级" style="width: 100%">
      <el-option v-for="level in levels" :key="level.id" :value="level.id" :label="level.name" />
    </el-select>
    <p class="muted">调整后，新订单按新等级的返佣比例计算。</p>
    <template #footer>
      <el-button @click="levelDialog = false">取消</el-button>
      <el-button type="primary" :disabled="!newLevelId" @click="saveLevel">保存</el-button>
    </template>
  </el-dialog>

  <el-dialog v-model="rateDialog" title="设置限流" width="420px">
    <el-input-number v-model="rateLimit" :min="1" :max="100000" :step="1" step-strictly />
    <span class="gap-left">次/秒</span>
    <template #footer>
      <el-button @click="rateDialog = false">取消</el-button>
      <el-button type="primary" @click="saveRateLimit">保存</el-button>
    </template>
  </el-dialog>

  <el-dialog v-model="adjustDialog" title="手动调账" width="480px" @closed="adjustFormRef?.clearValidate()">
    <el-form ref="adjustFormRef" :model="adjustForm" :rules="adjustRules" label-width="80px">
      <el-form-item label="方向">
        <el-radio-group v-model="adjustForm.direction">
          <el-radio-button value="add">加款</el-radio-button>
          <el-radio-button value="subtract">扣款</el-radio-button>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="金额" prop="amount">
        <el-input v-model="adjustForm.amount">
          <template #prepend>¥</template>
        </el-input>
      </el-form-item>
      <el-form-item label="原因" prop="reason">
        <el-input v-model="adjustForm.reason" type="textarea" :rows="3" maxlength="255" show-word-limit />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="adjustDialog = false">取消</el-button>
      <el-button type="primary" :loading="adjusting" @click="submitAdjust">提交</el-button>
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

.history-title {
  margin: 16px 0 8px;
  font-weight: 600;
}

.review {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 16px;
}

.negative {
  color: #f56c6c;
  font-weight: 600;
}

.muted {
  color: #909399;
  font-size: 12px;
}

.gap {
  margin-right: 12px;
}

.gap-left {
  margin-left: 8px;
}

.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}
</style>
