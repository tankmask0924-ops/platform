<script setup lang="ts">
import { labelOf, toOptions, usePagedList } from '@platform/shared'
import { onMounted, ref } from 'vue'
import { type OperationLog, operationLogApi } from '@/api/system'
import { moduleLabels, operationActionLabels } from '@/labels'

const list = usePagedList<OperationLog, { module: string; target_id: string; dates: [string, string] | null }>(
  (params) => {
    // 日期范围拆成 created_from / created_to
    const { dates, ...rest } = params as Record<string, unknown> & { dates?: [string, string] }
    return operationLogApi.list(dates ? { ...rest, created_from: dates[0], created_to: dates[1] } : rest)
  },
  { module: '', target_id: '', dates: null },
  20,
)
const { rows, total, page, perPage, loading, filters } = list

const detail = ref<OperationLog | null>(null)

const json = (value: unknown) => (value === null || value === undefined ? '-' : JSON.stringify(value, null, 2))

onMounted(list.load)
</script>

<template>
  <el-card shadow="never">
    <el-form inline @submit.prevent="list.search">
      <el-form-item>
        <el-select v-model="filters.module" placeholder="全部模块" clearable style="width: 130px">
          <el-option v-for="o in toOptions(moduleLabels)" :key="o.value" v-bind="o" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-input v-model="filters.target_id" placeholder="对象 ID" clearable style="width: 120px" />
      </el-form-item>
      <el-form-item>
        <el-date-picker
          v-model="filters.dates"
          type="daterange"
          value-format="YYYY-MM-DD"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          style="width: 240px"
        />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" native-type="submit">查询</el-button>
      </el-form-item>
    </el-form>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="created_at" label="时间" width="170" />
      <el-table-column label="操作人" width="130">
        <template #default="{ row }">{{ row.admin_real_name ?? row.admin_username ?? `#${row.admin_user_id}` }}</template>
      </el-table-column>
      <el-table-column label="模块" width="100">
        <template #default="{ row }">{{ labelOf(moduleLabels, row.module) }}</template>
      </el-table-column>
      <el-table-column label="动作" min-width="150">
        <template #default="{ row }">{{ labelOf(operationActionLabels, row.action) }}</template>
      </el-table-column>
      <el-table-column label="对象" width="160">
        <template #default="{ row }">{{ row.target_type ?? '-' }}{{ row.target_id ? ` #${row.target_id}` : '' }}</template>
      </el-table-column>
      <el-table-column prop="ip" label="IP" width="140" />
      <el-table-column label="操作" width="80" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="detail = row as OperationLog">详情</el-button>
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

  <el-dialog :model-value="!!detail" title="操作详情" width="760px" @close="detail = null">
    <template v-if="detail">
      <p class="muted">
        {{ detail.created_at }} · {{ detail.admin_real_name ?? detail.admin_username }} ·
        {{ labelOf(moduleLabels, detail.module) }} / {{ labelOf(operationActionLabels, detail.action) }}
      </p>
      <div class="diff">
        <div>
          <div class="diff-title">修改前</div>
          <pre>{{ json(detail.before_data) }}</pre>
        </div>
        <div>
          <div class="diff-title">修改后 / 请求参数</div>
          <pre>{{ json(detail.after_data) }}</pre>
        </div>
      </div>
    </template>
  </el-dialog>
</template>

<style scoped>
.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}

.muted {
  color: #909399;
  font-size: 12px;
}

.diff {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

.diff-title {
  font-weight: 600;
  margin-bottom: 4px;
}

pre {
  margin: 0;
  max-height: 420px;
  overflow: auto;
  padding: 8px;
  background: #f5f7fa;
  border-radius: 4px;
  font-size: 12px;
  white-space: pre-wrap;
  word-break: break-all;
}
</style>
