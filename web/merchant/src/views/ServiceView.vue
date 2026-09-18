<script setup lang="ts">
import { businessLineLabels, labelOf, StatusTag, subscriptionStatusLabels } from '@platform/shared'
import { ElMessage, ElMessageBox } from 'element-plus'
import { onMounted, ref } from 'vue'
import { fetchMe } from '@/api/auth'
import { type BusinessSubscription, subscriptionApi } from '@/api/merchant'

const rows = ref<BusinessSubscription[]>([])
const merchantStatus = ref<string | null>(null)
const loading = ref(false)
const applying = ref<string | null>(null)

async function load() {
  loading.value = true
  try {
    const [list, me] = await Promise.all([subscriptionApi.list(), fetchMe()])
    rows.value = list
    merchantStatus.value = me.status
  } finally {
    loading.value = false
  }
}

async function apply(row: BusinessSubscription) {
  const name = labelOf(businessLineLabels, row.business_line)
  try {
    await ElMessageBox.confirm(`提交「${name}」业务线开通申请？平台审核通过后才能查询该业务线商品和下单。`, '申请开通', {
      type: 'info',
    })
  } catch {
    return
  }
  applying.value = row.business_line
  try {
    rows.value = await subscriptionApi.apply(row.business_line)
    ElMessage.success('已提交，请等待平台审核')
  } finally {
    applying.value = null
  }
}

onMounted(load)
</script>

<template>
  <el-card v-loading="loading" shadow="never">
    <el-alert
      v-if="merchantStatus && merchantStatus !== 'active'"
      type="warning"
      show-icon
      :closable="false"
      class="tip"
      title="资质审核通过后才能申请开通业务线"
    >
      <router-link :to="{ name: 'qualification' }">查看资质审核状态</router-link>
    </el-alert>
    <p class="muted">按业务线开通，开通后即可购买该业务线的所有商品；未开通的业务线调用商品查询和下单接口会返回 42007。</p>

    <el-table :data="rows" border>
      <el-table-column label="业务线" width="120">
        <template #default="{ row }">{{ labelOf(businessLineLabels, row.business_line) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="120">
        <template #default="{ row }">
          <StatusTag v-if="row.status" :map="subscriptionStatusLabels" :value="row.status" />
          <span v-else-if="!row.available" class="muted">暂未开放</span>
          <span v-else class="muted">未开通</span>
        </template>
      </el-table-column>
      <el-table-column label="驳回原因" min-width="200">
        <template #default="{ row }">{{ row.status === 'rejected' ? row.reject_reason : '-' }}</template>
      </el-table-column>
      <el-table-column label="申请时间" width="170">
        <template #default="{ row }">{{ row.applied_at ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="审核时间" width="170">
        <template #default="{ row }">{{ row.reviewed_at ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="操作" width="120" fixed="right">
        <template #default="{ row }">
          <el-button
            v-if="row.available && (row.status === null || row.status === 'rejected')"
            link
            type="primary"
            :disabled="merchantStatus !== 'active'"
            :loading="applying === row.business_line"
            @click="apply(row as BusinessSubscription)"
          >
            {{ row.status === 'rejected' ? '重新申请' : '申请开通' }}
          </el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>
</template>

<style scoped>
.tip {
  margin-bottom: 12px;
}

.muted {
  margin: 0 0 12px;
  color: var(--el-text-color-secondary);
  font-size: 12px;
}
</style>
