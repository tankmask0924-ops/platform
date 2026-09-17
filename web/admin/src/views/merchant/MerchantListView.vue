<script setup lang="ts">
import { labelOf, merchantStatusLabels, merchantTypeLabels, StatusTag, usePagedList } from '@platform/shared'
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { type Merchant, merchantApi } from '@/api/admin'
import { useLevels } from '@/composables/useLevels'

const router = useRouter()
const list = usePagedList<Merchant, object>(merchantApi.list, {})
const { rows, total, page, perPage, loading } = list
const { ensure, levelName } = useLevels()

function open(id: number) {
  router.push({ name: 'merchant-detail', params: { id } })
}

onMounted(() => {
  list.load()
  ensure()
})
</script>

<template>
  <el-card shadow="never">
    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="id" label="ID" width="80" />
      <el-table-column label="类型" width="80">
        <template #default="{ row }">{{ labelOf(merchantTypeLabels, row.type) }}</template>
      </el-table-column>
      <el-table-column label="手机号" min-width="140">
        <template #default="{ row }">{{ row.phone ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="邮箱" min-width="180">
        <template #default="{ row }">{{ row.email ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="状态" width="100">
        <template #default="{ row }"><StatusTag :map="merchantStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column label="等级" width="120">
        <template #default="{ row }">{{ levelName(row.level_id) }}</template>
      </el-table-column>
      <el-table-column prop="created_at" label="注册时间" width="170" />
      <el-table-column label="操作" width="100" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="open(row.id)">{{ row.status === 'pending' ? '审核' : '详情' }}</el-button>
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
.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}
</style>
