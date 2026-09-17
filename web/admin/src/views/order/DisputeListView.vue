<script setup lang="ts">
import {
  businessLineLabels,
  disputeStatusLabels,
  labelOf,
  money,
  orderStatusLabels,
  rebateStatusLabels,
  splitLines,
  StatusTag,
  toOptions,
  usePagedList,
} from '@platform/shared'
import { ElMessage, ElMessageBox } from 'element-plus'
import { onMounted, reactive, ref } from 'vue'
import { type Dispute, disputeApi, type DisputeDetail } from '@/api/admin'

const list = usePagedList<Dispute, { status: string; merchant_id: string }>(disputeApi.list, {
  status: 'processing',
  merchant_id: '',
})
const { rows, total, page, perPage, loading, filters } = list

const detail = ref<DisputeDetail | null>(null)
const detailVisible = ref(false)
const detailLoading = ref(false)

async function openDetail(id: number) {
  detailVisible.value = true
  detailLoading.value = true
  detail.value = null
  try {
    detail.value = await disputeApi.detail(id)
  } finally {
    detailLoading.value = false
  }
}

// 处理：reject = 确认已到账（凭证必填）；confirm = 确认未到账（退款）
const action = ref<'reject' | 'confirm' | null>(null)
const handling = ref(false)
const handleForm = reactive({ remark: '', evidence: '' })

function openHandle(kind: 'reject' | 'confirm') {
  action.value = kind
  handleForm.remark = ''
  handleForm.evidence = ''
}

async function submitHandle() {
  if (!detail.value || !action.value) {
    return
  }
  const remark = handleForm.remark.trim()
  const evidence = splitLines(handleForm.evidence)
  if (remark === '') {
    ElMessage.warning('请填写处理说明')
    return
  }
  if (action.value === 'reject' && evidence.length === 0) {
    ElMessage.warning('驳回必须附上到账凭证')
    return
  }
  if (evidence.length > 20) {
    ElMessage.warning('凭证最多 20 项')
    return
  }
  if (action.value === 'confirm') {
    try {
      await ElMessageBox.confirm(
        `将订单 ${detail.value.order_no} 实扣的 ¥${detail.value.deducted_amount} 退回商户可用余额，订单改为已退款；返佣未到账的作废、已到账的扣回。操作不可撤销，确定吗？`,
        '确认未到账并退款',
        { type: 'warning' },
      )
    } catch {
      return
    }
  }
  handling.value = true
  try {
    const id = detail.value.id
    if (action.value === 'reject') {
      await disputeApi.reject(id, remark, evidence)
    } else {
      await disputeApi.confirm(id, remark, evidence)
    }
    ElMessage.success('已处理')
    action.value = null
    await Promise.all([openDetail(id), list.load()])
  } finally {
    handling.value = false
  }
}

onMounted(list.load)
</script>

<template>
  <el-card shadow="never">
    <div class="toolbar">
      <el-radio-group v-model="filters.status" @change="list.search">
        <el-radio-button v-for="o in toOptions(disputeStatusLabels)" :key="o.value" :value="o.value">{{ o.label }}</el-radio-button>
        <el-radio-button value="">全部</el-radio-button>
      </el-radio-group>
      <el-input v-model="filters.merchant_id" clearable placeholder="商户 ID" style="width: 140px" @change="list.search" />
    </div>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="id" label="编号" width="70" />
      <el-table-column label="商户" width="80">
        <template #default="{ row }">
          <router-link :to="{ name: 'merchant-detail', params: { id: row.merchant_id } }">#{{ row.merchant_id }}</router-link>
        </template>
      </el-table-column>
      <el-table-column label="订单" min-width="200">
        <template #default="{ row }">
          <router-link :to="{ name: 'order-detail', params: { id: row.order_id } }">{{ row.order_no }}</router-link>
        </template>
      </el-table-column>
      <el-table-column label="业务" width="70">
        <template #default="{ row }">{{ labelOf(businessLineLabels, row.business_line) }}</template>
      </el-table-column>
      <el-table-column label="实扣" width="100" align="right">
        <template #default="{ row }">{{ money(row.deducted_amount) }}</template>
      </el-table-column>
      <el-table-column label="订单完成" width="170">
        <template #default="{ row }">{{ row.completed_at ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="状态" width="170">
        <template #default="{ row }"><StatusTag :map="disputeStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column prop="submitted_at" label="提交时间" width="170" />
      <el-table-column label="操作" width="80" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openDetail(row.id)">{{ row.status === 'processing' ? '处理' : '详情' }}</el-button>
        </template>
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

  <el-drawer v-model="detailVisible" title="售后争议" size="600px" @closed="action = null">
    <div v-loading="detailLoading">
      <template v-if="detail">
        <el-descriptions :column="2" border>
          <el-descriptions-item label="编号">{{ detail.id }}</el-descriptions-item>
          <el-descriptions-item label="状态"><StatusTag :map="disputeStatusLabels" :value="detail.status" /></el-descriptions-item>
          <el-descriptions-item label="订单" :span="2">
            <router-link :to="{ name: 'order-detail', params: { id: detail.order_id } }">{{ detail.order_no }}</router-link>
          </el-descriptions-item>
          <el-descriptions-item label="订单状态"><StatusTag :map="orderStatusLabels" :value="detail.order_status" /></el-descriptions-item>
          <el-descriptions-item label="业务">{{ labelOf(businessLineLabels, detail.business_line) }}</el-descriptions-item>
          <el-descriptions-item label="售价">{{ money(detail.sale_price) }}</el-descriptions-item>
          <el-descriptions-item label="实扣">{{ money(detail.deducted_amount) }}</el-descriptions-item>
          <el-descriptions-item label="已退款">{{ money(detail.refunded_amount) }}</el-descriptions-item>
          <el-descriptions-item label="订单完成">{{ detail.completed_at ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="返佣" :span="2">
            <template v-if="detail.rebate">
              {{ money(detail.rebate.amount) }}
              <StatusTag :map="rebateStatusLabels" :value="detail.rebate.status" />
              <span v-if="detail.rebate.status === 'pending'" class="muted">争议处理期间暂停到账</span>
            </template>
            <span v-else>无</span>
          </el-descriptions-item>
          <el-descriptions-item label="提交时间">{{ detail.submitted_at }}</el-descriptions-item>
          <el-descriptions-item label="处理时间">{{ detail.resolved_at ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="处理人">{{ detail.handler_id ? `#${detail.handler_id}` : '-' }}</el-descriptions-item>
          <el-descriptions-item label="处理说明">{{ detail.result_remark ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="凭证" :span="2">
            <ul v-if="detail.evidence?.length" class="evidence">
              <li v-for="(item, i) in detail.evidence" :key="i">{{ item }}</li>
            </ul>
            <span v-else>-</span>
          </el-descriptions-item>
        </el-descriptions>

        <template v-if="detail.status === 'processing'">
          <el-alert type="info" :closable="false" class="block" title="先向供应商核实到账情况：已到账则驳回并附凭证；未到账则确认退款。" />
          <div v-if="!action" class="actions">
            <el-button @click="openHandle('reject')">确认已到账（驳回）</el-button>
            <el-button type="danger" @click="openHandle('confirm')">确认未到账（退款）</el-button>
          </div>
          <el-form v-else label-position="top" class="block">
            <el-form-item :label="action === 'reject' ? '驳回：处理说明（商户可见）' : '退款：处理说明（商户可见）'" required>
              <el-input v-model="handleForm.remark" type="textarea" :rows="2" maxlength="255" show-word-limit />
            </el-form-item>
            <el-form-item :label="action === 'reject' ? '到账凭证（必填，每行一项，可填链接或说明）' : '凭证（选填，每行一项）'" :required="action === 'reject'">
              <el-input v-model="handleForm.evidence" type="textarea" :rows="4" />
            </el-form-item>
            <el-button @click="action = null">取消</el-button>
            <el-button :type="action === 'confirm' ? 'danger' : 'primary'" :loading="handling" @click="submitHandle">提交</el-button>
          </el-form>
        </template>
      </template>
    </div>
  </el-drawer>
</template>

<style scoped>
.toolbar {
  display: flex;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 16px;
}

.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}

.block {
  margin-top: 16px;
}

.actions {
  display: flex;
  gap: 8px;
  margin-top: 16px;
}

.muted {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}

.evidence {
  margin: 0;
  padding-left: 18px;
  word-break: break-all;
}
</style>
