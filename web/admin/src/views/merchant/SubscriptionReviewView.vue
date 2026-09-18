<script setup lang="ts">
import {
  businessLineLabels,
  labelOf,
  merchantStatusLabels,
  StatusTag,
  subscriptionStatusLabels,
  toOptions,
  usePagedList,
} from '@platform/shared'
import { ElMessage, ElMessageBox } from 'element-plus'
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { type Subscription, subscriptionApi } from '@/api/admin'
import { usePermissionStore } from '@/stores/permission'

const route = useRoute()
const permission = usePermissionStore()
const canReview = computed(() => permission.can('subscription.review'))

const merchantId = typeof route.query.merchant_id === 'string' ? route.query.merchant_id : ''
// 从商户详情点进来看的是这个商户的全部开通记录，默认不只看待审核
const initial = { status: merchantId ? '' : 'pending', business_line: '', merchant_id: merchantId }
const list = usePagedList<Subscription, typeof initial>(subscriptionApi.list, initial)
const { rows, total, page, perPage, loading, filters } = list
const businessLineOptions = toOptions(businessLineLabels)

function merchantLabel(row: Subscription): string {
  return row.merchant_name ?? row.merchant_phone ?? row.merchant_email ?? `#${row.merchant_id}`
}

async function approve(row: Subscription) {
  const line = labelOf(businessLineLabels, row.business_line)
  try {
    await ElMessageBox.confirm(`通过后商户「${merchantLabel(row)}」立即可以查询和下单「${line}」业务线。`, '开通审核通过', {
      type: 'warning',
    })
  } catch {
    return
  }
  await subscriptionApi.approve(row.id)
  ElMessage.success('已开通')
  await list.load()
}

async function reject(row: Subscription) {
  let reason: string
  try {
    const result = await ElMessageBox.prompt('驳回原因（商户可见）', `驳回「${labelOf(businessLineLabels, row.business_line)}」开通申请`, {
      inputValidator: (value) => (value && value.trim() !== '' ? true : '请填写驳回原因'),
      type: 'warning',
    })
    reason = result.value.trim()
  } catch {
    return
  }
  await subscriptionApi.reject(row.id, reason)
  ElMessage.success('已驳回')
  await list.load()
}

onMounted(list.load)
</script>

<template>
  <el-card shadow="never">
    <el-form inline @submit.prevent="list.search">
      <el-form-item>
        <el-radio-group v-model="filters.status" @change="list.search">
          <el-radio-button value="pending">待审核</el-radio-button>
          <el-radio-button value="approved">已开通</el-radio-button>
          <el-radio-button value="rejected">已驳回</el-radio-button>
          <el-radio-button value="">全部</el-radio-button>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="业务线">
        <el-select v-model="filters.business_line" clearable placeholder="全部" style="width: 120px" @change="list.search">
          <el-option v-for="o in businessLineOptions" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>
      <el-form-item label="商户 ID">
        <el-input v-model="filters.merchant_id" clearable style="width: 100px" />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" native-type="submit">查询</el-button>
      </el-form-item>
    </el-form>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column label="商户" min-width="200">
        <template #default="{ row }">
          <router-link :to="{ name: 'merchant-detail', params: { id: row.merchant_id } }">#{{ row.merchant_id }}</router-link>
          {{ row.merchant_name ?? '' }}
          <div class="muted">{{ row.merchant_phone ?? row.merchant_email ?? '' }}</div>
        </template>
      </el-table-column>
      <el-table-column label="商户状态" width="90">
        <template #default="{ row }"><StatusTag :map="merchantStatusLabels" :value="row.merchant_status" /></template>
      </el-table-column>
      <el-table-column label="业务线" width="90">
        <template #default="{ row }">{{ labelOf(businessLineLabels, row.business_line) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }"><StatusTag :map="subscriptionStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column label="驳回原因" min-width="160">
        <template #default="{ row }">{{ row.reject_reason ?? '-' }}</template>
      </el-table-column>
      <el-table-column prop="applied_at" label="申请时间" width="170" />
      <el-table-column label="审核" width="190">
        <template #default="{ row }">
          <span v-if="row.reviewed_at">#{{ row.reviewed_by }} · {{ row.reviewed_at }}</span>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column v-if="canReview" label="操作" width="120" fixed="right">
        <template #default="{ row }">
          <template v-if="row.status === 'pending'">
            <el-button link type="primary" @click="approve(row as Subscription)">通过</el-button>
            <el-button link type="danger" @click="reject(row as Subscription)">驳回</el-button>
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
</template>

<style scoped>
.muted {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}
</style>
