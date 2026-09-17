<script setup lang="ts">
import { money, rechargeRequestStatusLabels, StatusTag, usePagedList } from '@platform/shared'
import { ElMessage, ElMessageBox } from 'element-plus'
import { onMounted } from 'vue'
import { type RechargeRequest, rechargeApi } from '@/api/admin'

const list = usePagedList<RechargeRequest, { status: string }>(rechargeApi.list, { status: 'pending' })
const { rows, total, page, perPage, loading, filters } = list

async function approve(row: RechargeRequest) {
  try {
    await ElMessageBox.confirm(
      `确认已收到商户 #${row.merchant_id} 的转账 ¥${row.amount}${row.transfer_no ? `（流水号 ${row.transfer_no}）` : ''}？通过后金额立即进入商户可用余额。`,
      '充值审核通过',
      { type: 'warning' },
    )
  } catch {
    return
  }
  await rechargeApi.approve(row.id)
  ElMessage.success('已通过，余额已到账')
  await list.load()
}

async function reject(row: RechargeRequest) {
  let reason: string
  try {
    const result = await ElMessageBox.prompt('驳回原因（商户可见）', `驳回充值申请 #${row.id}`, {
      inputValidator: (value) => (value && value.trim() !== '' ? true : '请填写驳回原因'),
      type: 'warning',
    })
    reason = result.value.trim()
  } catch {
    return
  }
  await rechargeApi.reject(row.id, reason)
  ElMessage.success('已驳回')
  await list.load()
}

onMounted(list.load)
</script>

<template>
  <el-card shadow="never">
    <el-radio-group v-model="filters.status" class="toolbar" @change="list.search">
      <el-radio-button value="pending">待审核</el-radio-button>
      <el-radio-button value="approved">已通过</el-radio-button>
      <el-radio-button value="rejected">已驳回</el-radio-button>
      <el-radio-button value="">全部</el-radio-button>
    </el-radio-group>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="id" label="编号" width="80" />
      <el-table-column label="商户" width="100">
        <template #default="{ row }">
          <router-link :to="{ name: 'merchant-detail', params: { id: row.merchant_id } }">#{{ row.merchant_id }}</router-link>
        </template>
      </el-table-column>
      <el-table-column label="金额" width="130" align="right">
        <template #default="{ row }">{{ money(row.amount) }}</template>
      </el-table-column>
      <el-table-column label="转账流水号" min-width="160">
        <template #default="{ row }">{{ row.transfer_no ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="凭证" width="90">
        <template #default="{ row }">
          <el-link :href="row.proof_image" target="_blank" type="primary">查看</el-link>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }"><StatusTag :map="rechargeRequestStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column label="驳回原因" min-width="140">
        <template #default="{ row }">{{ row.reject_reason ?? '-' }}</template>
      </el-table-column>
      <el-table-column prop="created_at" label="提交时间" width="170" />
      <el-table-column label="审核" width="190">
        <template #default="{ row }">
          <span v-if="row.reviewed_at">#{{ row.reviewed_by }} · {{ row.reviewed_at }}</span>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="120" fixed="right">
        <template #default="{ row }">
          <template v-if="row.status === 'pending'">
            <el-button link type="primary" @click="approve(row as RechargeRequest)">通过</el-button>
            <el-button link type="danger" @click="reject(row as RechargeRequest)">驳回</el-button>
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
.toolbar {
  margin-bottom: 16px;
}

.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}
</style>
