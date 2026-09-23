<script setup lang="ts">
import { labelOf, money, StatusTag, toOptions, usePagedList } from '@platform/shared'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { onMounted, reactive, ref } from 'vue'
import { type ExpressWorkorder, workorderApi } from '@/api/admin'
import { workorderStatusLabels, workorderTypeLabels } from '@/labels'
import { usePermissionStore } from '@/stores/permission'

const permission = usePermissionStore()
const processingCount = ref(0)

const initial = { status: 'processing', type: '', replied: '', order_no: '' }
const list = usePagedList<ExpressWorkorder, typeof initial>(
  (params) =>
    workorderApi.list(params).then((result) => {
      processingCount.value = result.processing_count
      return result
    }),
  initial,
)
const { rows, total, page, perPage, loading, filters } = list
const statusOptions = toOptions(workorderStatusLabels)
const typeOptions = toOptions(workorderTypeLabels)

function reset() {
  Object.assign(filters, { ...initial, status: '' })
  list.search()
}

// 结单：完成（理赔可带核实金额，会调账给商户）或驳回
const dialog = ref(false)
const action = ref<'complete' | 'reject'>('complete')
const current = ref<ExpressWorkorder | null>(null)
const saving = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({ result_remark: '', claim_amount: '' })
const rules: FormRules = {
  result_remark: [{ required: true, message: '请填写处理结果', trigger: 'blur' }],
  claim_amount: [{ pattern: /^\d+(\.\d{1,2})?$/, message: '金额最多两位小数', trigger: 'blur' }],
}

function open(row: ExpressWorkorder, kind: 'complete' | 'reject') {
  current.value = row
  action.value = kind
  Object.assign(form, { result_remark: '', claim_amount: '' })
  dialog.value = true
}

async function submit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid || current.value === null) {
    return
  }
  saving.value = true
  try {
    if (action.value === 'reject') {
      await workorderApi.reject(current.value.id, form.result_remark.trim())
    } else {
      await workorderApi.complete(current.value.id, {
        result_remark: form.result_remark.trim(),
        ...(current.value.type === 'claim' && form.claim_amount !== '' ? { claim_amount: form.claim_amount } : {}),
      })
    }
    ElMessage.success('已处理')
    dialog.value = false
    list.load()
  } finally {
    saving.value = false
  }
}

onMounted(list.load)
</script>

<template>
  <el-card shadow="never">
    <template #header>
      快递工单 <el-tag v-if="processingCount > 0" type="warning" size="small">{{ processingCount }} 张处理中</el-tag>
    </template>
    <el-alert type="info" :closable="false" class="tip">
      工单在快递订单详情里提交。云洋的工单回调没有签名，回复和金额只作参考、需要核实：重量核实、状态异常退回的运费会按费用调整自动退给商户，
      不用在这里再调账；理赔款按核实的实际赔付在「完成」时填写，会调账加到商户可用余额。
    </el-alert>

    <el-form inline @submit.prevent="list.search">
      <el-form-item label="平台单号">
        <el-input v-model="filters.order_no" clearable style="width: 200px" />
      </el-form-item>
      <el-form-item label="状态">
        <el-select v-model="filters.status" clearable placeholder="全部" style="width: 110px">
          <el-option v-for="o in statusOptions" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>
      <el-form-item label="类型">
        <el-select v-model="filters.type" clearable placeholder="全部" style="width: 110px">
          <el-option v-for="o in typeOptions" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>
      <el-form-item label="云洋回复">
        <el-select v-model="filters.replied" clearable placeholder="全部" style="width: 110px">
          <el-option value="1" label="已回复" />
          <el-option value="0" label="未回复" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" native-type="submit">查询</el-button>
        <el-button @click="reset">重置</el-button>
      </el-form-item>
    </el-form>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column label="平台单号" min-width="190">
        <template #default="{ row }">
          <router-link v-if="permission.can('order.view')" :to="{ name: 'order-detail', params: { id: row.order_id } }">
            {{ row.order_no }}
          </router-link>
          <span v-else>{{ row.order_no }}</span>
        </template>
      </el-table-column>
      <el-table-column label="类型" width="90">
        <template #default="{ row }">{{ labelOf(workorderTypeLabels, row.type) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }"><StatusTag :map="workorderStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column prop="content" label="内容" min-width="180" />
      <el-table-column label="云洋回复（未验证）" min-width="200">
        <template #default="{ row }">
          <template v-if="row.supplier_replied_at">
            {{ row.supplier_reply }}
            <span v-if="row.supplier_amount" class="muted">金额 {{ money(row.supplier_amount) }}</span>
            <div class="muted">{{ row.supplier_replied_at }}</div>
          </template>
          <span v-else class="muted">未回复</span>
        </template>
      </el-table-column>
      <el-table-column label="处理结果" min-width="160">
        <template #default="{ row }">
          {{ row.result_remark ?? '-' }}
          <span v-if="row.claim_amount" class="muted">理赔调账 {{ money(row.claim_amount) }}</span>
        </template>
      </el-table-column>
      <el-table-column label="云洋工单号" width="140">
        <template #default="{ row }">{{ row.supplier_workorder_no ?? '-' }}</template>
      </el-table-column>
      <el-table-column prop="created_at" label="提交时间" width="170" />
      <el-table-column v-if="permission.can('aftersale.handle')" label="操作" width="130" fixed="right">
        <template #default="{ row }">
          <template v-if="row.status === 'processing'">
            <el-button link type="primary" @click="open(row as ExpressWorkorder, 'complete')">完成</el-button>
            <el-button link type="danger" @click="open(row as ExpressWorkorder, 'reject')">驳回</el-button>
          </template>
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

  <el-dialog v-model="dialog" :title="action === 'complete' ? '完成工单' : '驳回工单'" width="480px" @closed="formRef?.clearValidate()">
    <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
      <el-form-item label="处理结果" prop="result_remark">
        <el-input v-model="form.result_remark" type="textarea" :rows="3" maxlength="255" show-word-limit />
      </el-form-item>
      <el-form-item v-if="action === 'complete' && current?.type === 'claim'" label="理赔金额" prop="claim_amount">
        <el-input v-model="form.claim_amount" placeholder="核实的实际赔付，不赔留空">
          <template #append>元</template>
        </el-input>
        <div class="muted">填写后按调账加到商户可用余额，不可撤销</div>
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialog = false">取消</el-button>
      <el-button :type="action === 'complete' ? 'primary' : 'danger'" :loading="saving" @click="submit">提交</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.tip {
  margin-bottom: 16px;
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
