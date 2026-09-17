<script setup lang="ts">
import { balanceLogTypeLabels, StatusTag, toOptions, usePagedList } from '@platform/shared'
import { onMounted } from 'vue'
import { type BalanceLog, balanceLogApi } from '@/api/merchant'

const list = usePagedList<BalanceLog, { type: string }>(balanceLogApi.list, { type: '' })
const { rows, total, page, perPage, loading, filters } = list
const typeOptions = toOptions(balanceLogTypeLabels)

onMounted(list.load)
</script>

<template>
  <el-card shadow="never">
    <el-form inline @submit.prevent="list.search">
      <el-form-item label="类型">
        <el-select v-model="filters.type" clearable placeholder="全部" style="width: 160px" @change="list.search">
          <el-option v-for="o in typeOptions" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>
    </el-form>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="created_at" label="时间" width="170" />
      <el-table-column label="类型" width="110">
        <template #default="{ row }"><StatusTag :map="balanceLogTypeLabels" :value="row.type" /></template>
      </el-table-column>
      <el-table-column prop="amount" label="金额" width="120" align="right" />
      <el-table-column label="可用余额（前 → 后）" min-width="200">
        <template #default="{ row }">{{ row.available_before }} → {{ row.available_after }}</template>
      </el-table-column>
      <el-table-column label="冻结金额（前 → 后）" min-width="200">
        <template #default="{ row }">{{ row.frozen_before }} → {{ row.frozen_after }}</template>
      </el-table-column>
      <el-table-column label="说明" min-width="200">
        <template #default="{ row }">{{ row.reason ?? '-' }}</template>
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
