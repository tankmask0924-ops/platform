<script setup lang="ts">
import { isHttpUrl, money, rechargeRequestStatusLabels, StatusTag, usePagedList } from '@platform/shared'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { onMounted, reactive, ref } from 'vue'
import { type RechargeRequest, rechargeApi } from '@/api/merchant'

const list = usePagedList<RechargeRequest, object>(rechargeApi.list, {})
const { rows, total, page, perPage, loading } = list

const dialogVisible = ref(false)
const saving = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({ amount: '', proof_image: '', transfer_no: '' })
const rules: FormRules = {
  amount: [
    { required: true, message: '请输入充值金额', trigger: 'blur' },
    { pattern: /^\d+(\.\d{1,2})?$/, message: '金额格式不正确，最多两位小数', trigger: 'blur' },
    {
      validator: (_rule, value: string, callback) => callback(Number(value) > 0 ? undefined : new Error('金额必须大于 0')),
      trigger: 'blur',
    },
  ],
  proof_image: [
    { required: true, message: '请填写转账凭证地址', trigger: 'blur' },
    {
      validator: (_rule, value: string, callback) =>
        !value || isHttpUrl(value) ? callback() : callback(new Error('请填写 http:// 或 https:// 开头的图片链接')),
      trigger: 'blur',
    },
  ],
}

function openDialog() {
  Object.assign(form, { amount: '', proof_image: '', transfer_no: '' })
  dialogVisible.value = true
}

async function submit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  saving.value = true
  try {
    await rechargeApi.submit({ ...form })
    ElMessage.success('已提交，等待平台审核')
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
      :closable="false"
      show-icon
      title="线下转账到平台对公账户后提交充值申请，平台核对到账后审核通过，金额进入可用余额。"
      class="block"
    />
    <div class="toolbar">
      <el-button type="primary" :icon="Plus" @click="openDialog">提交充值申请</el-button>
    </div>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="id" label="申请编号" width="100" />
      <el-table-column label="金额" width="140">
        <template #default="{ row }">{{ money(row.amount) }}</template>
      </el-table-column>
      <el-table-column prop="transfer_no" label="转账流水号" min-width="160">
        <template #default="{ row }">{{ row.transfer_no ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="凭证" min-width="120">
        <template #default="{ row }">
          <el-link v-if="isHttpUrl(row.proof_image)" :href="row.proof_image" target="_blank" type="primary">查看</el-link>
          <span v-else>{{ row.proof_image }}</span>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="100">
        <template #default="{ row }"><StatusTag :map="rechargeRequestStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column prop="reject_reason" label="驳回原因" min-width="160">
        <template #default="{ row }">{{ row.reject_reason ?? '-' }}</template>
      </el-table-column>
      <el-table-column prop="created_at" label="提交时间" width="170" />
      <el-table-column label="审核时间" width="170">
        <template #default="{ row }">{{ row.reviewed_at ?? '-' }}</template>
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

  <el-dialog v-model="dialogVisible" title="提交充值申请" width="520px" @closed="formRef?.clearValidate()">
    <el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
      <el-form-item label="充值金额" prop="amount">
        <el-input v-model="form.amount" placeholder="与实际转账金额一致">
          <template #prepend>¥</template>
        </el-input>
      </el-form-item>
      <el-form-item label="转账凭证" prop="proof_image">
        <el-input v-model="form.proof_image" placeholder="转账截图的图片链接" />
        <div class="tip">图片上传暂未开放，请先把截图上传到网盘/图床后填写链接。</div>
      </el-form-item>
      <el-form-item label="转账流水号" prop="transfer_no">
        <el-input v-model="form.transfer_no" maxlength="64" placeholder="选填，便于平台核对" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="submit">提交</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.block,
.toolbar {
  margin-bottom: 16px;
}

.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}

.tip {
  color: #909399;
  font-size: 12px;
  line-height: 1.6;
}
</style>
