<script setup lang="ts">
import {
  businessLineLabels,
  disputeStatusLabels,
  isHttpUrl,
  labelOf,
  merchantOrderStatusLabels,
  money,
  StatusTag,
  toOptions,
  usePagedList,
} from '@platform/shared'
import { ElMessage, type FormInstance } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { onMounted, reactive, ref } from 'vue'
import { type Dispute, disputeApi } from '@/api/merchant'

const list = usePagedList<Dispute, { status: string }>(disputeApi.list, { status: '' })
const { rows, total, page, perPage, loading, filters } = list
const statusOptions = toOptions(disputeStatusLabels)

const detail = ref<Dispute | null>(null)

const dialogVisible = ref(false)
const saving = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({ order_no: '' })

function openDialog() {
  form.order_no = ''
  dialogVisible.value = true
}

async function submit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  saving.value = true
  try {
    await disputeApi.submit(form.order_no.trim())
    ElMessage.success('已提交')
    dialogVisible.value = false
    await list.search()
  } finally {
    saving.value = false
  }
}

onMounted(list.load)
</script>

<template>
  <el-card shadow="never">
    <el-alert
      type="info"
      show-icon
      :closable="false"
      title="话费、卡券订单显示成功但实际未到账时，可在订单成功后的规定时限内（默认 7 天）提交争议。平台核实未到账会全额退款到可用余额；核实已到账会驳回并附上凭证。每笔订单只能提交一次。"
      class="block"
    />
    <div class="toolbar">
      <el-button type="primary" :icon="Plus" @click="openDialog">提交争议</el-button>
      <el-select v-model="filters.status" clearable placeholder="全部状态" style="width: 200px" @change="list.search">
        <el-option v-for="o in statusOptions" :key="o.value" :value="o.value" :label="o.label" />
      </el-select>
    </div>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="id" label="编号" width="80" />
      <el-table-column prop="order_no" label="平台单号" min-width="200" />
      <el-table-column prop="merchant_order_no" label="商户单号" min-width="180" />
      <el-table-column label="业务" width="80">
        <template #default="{ row }">{{ labelOf(businessLineLabels, row.business_line) }}</template>
      </el-table-column>
      <el-table-column label="金额" width="100" align="right">
        <template #default="{ row }">{{ money(row.sale_price) }}</template>
      </el-table-column>
      <el-table-column label="处理状态" width="170">
        <template #default="{ row }"><StatusTag :map="disputeStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column prop="submitted_at" label="提交时间" width="170" />
      <el-table-column label="处理时间" width="170">
        <template #default="{ row }">{{ row.resolved_at ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="操作" width="80" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="detail = row as Dispute">详情</el-button>
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

  <el-dialog v-model="dialogVisible" title="提交未到账争议" width="480px" @closed="formRef?.clearValidate()">
    <el-form ref="formRef" :model="form" label-width="90px" @submit.prevent="submit">
      <el-form-item label="平台单号" prop="order_no" :rules="[{ required: true, message: '请输入平台单号', trigger: 'blur' }]">
        <el-input v-model="form.order_no" placeholder="订单列表里的平台单号" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="submit">提交</el-button>
    </template>
  </el-dialog>

  <el-drawer :model-value="detail !== null" title="争议详情" size="520px" @close="detail = null">
    <template v-if="detail">
      <el-descriptions :column="1" border>
        <el-descriptions-item label="编号">{{ detail.id }}</el-descriptions-item>
        <el-descriptions-item label="平台单号">{{ detail.order_no }}</el-descriptions-item>
        <el-descriptions-item label="商户单号">{{ detail.merchant_order_no }}</el-descriptions-item>
        <el-descriptions-item label="业务">{{ labelOf(businessLineLabels, detail.business_line) }}</el-descriptions-item>
        <el-descriptions-item label="订单金额">{{ money(detail.sale_price) }}</el-descriptions-item>
        <el-descriptions-item label="订单状态">
          <StatusTag :map="merchantOrderStatusLabels" :value="detail.order_status" />
        </el-descriptions-item>
        <el-descriptions-item label="处理状态">
          <StatusTag :map="disputeStatusLabels" :value="detail.status" />
        </el-descriptions-item>
        <el-descriptions-item label="提交时间">{{ detail.submitted_at }}</el-descriptions-item>
        <el-descriptions-item label="处理时间">{{ detail.resolved_at ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="处理说明">{{ detail.result_remark ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="凭证">
          <ul v-if="detail.evidence?.length" class="evidence">
            <li v-for="(item, i) in detail.evidence" :key="i">
              <el-link v-if="isHttpUrl(item)" :href="item" target="_blank" type="primary">{{ item }}</el-link>
              <span v-else>{{ item }}</span>
            </li>
          </ul>
          <span v-else>-</span>
        </el-descriptions-item>
      </el-descriptions>
    </template>
  </el-drawer>
</template>

<style scoped>
.block {
  margin-bottom: 16px;
}

.toolbar {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
}

.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}

.evidence {
  margin: 0;
  padding-left: 18px;
  word-break: break-all;
}
</style>
