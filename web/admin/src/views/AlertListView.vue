<script setup lang="ts">
import { labelOf, StatusTag, toOptions, usePagedList } from '@platform/shared'
import { ElMessage, ElMessageBox } from 'element-plus'
import { computed, onMounted, ref } from 'vue'
import { type Alert, alertApi } from '@/api/admin'
import { alertLevelLabels, alertRelatedTypeLabels, alertStatusLabels, alertTypeLabels } from '@/labels'
import { usePermissionStore } from '@/stores/permission'

const permission = usePermissionStore()
const canHandle = computed(() => permission.can('alert.handle'))

const openCount = ref(0)
const initial = { status: 'open', type: '', level: '', triggered_from: '', triggered_to: '' }
const list = usePagedList<Alert, typeof initial>(
  (params) =>
    alertApi.list(params).then((result) => {
      openCount.value = result.open_count
      return result
    }),
  initial,
  20,
)
const { rows, total, page, perPage, loading, filters } = list

const typeOptions = toOptions(alertTypeLabels)
const levelOptions = toOptions(alertLevelLabels)
const statusOptions = toOptions(alertStatusLabels)

const triggeredRange = ref<[string, string] | null>(null)
function onRangeChange(value: [string, string] | null) {
  filters.triggered_from = value?.[0] ?? ''
  filters.triggered_to = value?.[1] ?? ''
  list.search()
}

function reset() {
  Object.assign(filters, { status: 'open', type: '', level: '', triggered_from: '', triggered_to: '' })
  triggeredRange.value = null
  list.search()
}

/** 关联对象跳转到对应的详情页；没有对应页面的（订单按 id）就只显示文字 */
function relatedRoute(row: Alert) {
  if (row.related_id === null) {
    return null
  }
  const routes: Record<string, string> = {
    supplier: 'supplier-detail',
    product: 'product-detail',
    merchant: 'merchant-detail',
    order: 'order-detail',
  }
  const name = routes[row.related_type ?? '']

  return name ? { name, params: { id: row.related_id } } : null
}

const handling = ref(0)
async function handle(row: Alert, action: 'resolve' | 'ignore') {
  const label = action === 'resolve' ? '已处理' : '已忽略'
  await ElMessageBox.confirm(`把这条告警标记为${label}？`, '标记' + label, { type: 'warning' })
  handling.value = row.id
  try {
    const result = await (action === 'resolve' ? alertApi.resolve(row.id, {}) : alertApi.ignore(row.id, {}))
    openCount.value = result.open_count
    // 标记后按当前筛选重新取一页：默认筛「未处理」时这条会从列表里消失
    list.load()
    ElMessage.success('已标记为' + label)
  } finally {
    handling.value = 0
  }
}

onMounted(list.load)
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="card-header">
        <span>
          告警
          <el-tag v-if="openCount > 0" type="danger" size="small" class="gap-left">{{ openCount }} 条未处理</el-tag>
          <el-tag v-else type="success" size="small" class="gap-left">没有未处理告警</el-tag>
        </span>
      </div>
    </template>

    <el-form inline @submit.prevent="list.search">
      <el-form-item label="状态">
        <el-select v-model="filters.status" clearable placeholder="全部" style="width: 120px" @change="list.search">
          <el-option v-for="o in statusOptions" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>
      <el-form-item label="类型">
        <el-select v-model="filters.type" clearable placeholder="全部" style="width: 180px" @change="list.search">
          <el-option v-for="o in typeOptions" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>
      <el-form-item label="级别">
        <el-select v-model="filters.level" clearable placeholder="全部" style="width: 110px" @change="list.search">
          <el-option v-for="o in levelOptions" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>
      <el-form-item label="触发时间">
        <el-date-picker
          v-model="triggeredRange"
          type="daterange"
          value-format="YYYY-MM-DD"
          start-placeholder="开始"
          end-placeholder="结束"
          style="width: 240px"
          @change="onRangeChange"
        />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" native-type="submit">查询</el-button>
        <el-button @click="reset">重置</el-button>
      </el-form-item>
    </el-form>

    <el-table v-loading="loading" :data="rows" border empty-text="没有告警">
      <el-table-column label="级别" width="90">
        <template #default="{ row }"><StatusTag :map="alertLevelLabels" :value="row.level" /></template>
      </el-table-column>
      <el-table-column label="类型" width="160">
        <template #default="{ row }">{{ labelOf(alertTypeLabels, row.type) }}</template>
      </el-table-column>
      <el-table-column label="关联对象" width="150">
        <template #default="{ row }">
          <span v-if="row.related_type === null">-</span>
          <router-link v-else-if="relatedRoute(row as Alert)" :to="relatedRoute(row as Alert)!">
            {{ labelOf(alertRelatedTypeLabels, row.related_type) }} #{{ row.related_id }}
          </router-link>
          <span v-else>{{ labelOf(alertRelatedTypeLabels, row.related_type) }} #{{ row.related_id }}</span>
        </template>
      </el-table-column>
      <el-table-column prop="message" label="内容" min-width="320" />
      <el-table-column label="次数" width="80" align="right">
        <template #default="{ row }">
          <!-- 同一条告警重复触发只累加次数，不刷新记录 -->
          <span :class="{ repeated: row.occurrence_count > 1 }">{{ row.occurrence_count }}</span>
        </template>
      </el-table-column>
      <el-table-column prop="triggered_at" label="最近触发" width="170" />
      <el-table-column label="状态" width="100">
        <template #default="{ row }"><StatusTag :map="alertStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column label="处理人" width="140">
        <template #default="{ row }">
          <span v-if="row.resolved_by">{{ row.resolved_by }}</span>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column v-if="canHandle" label="操作" width="150">
        <template #default="{ row }">
          <template v-if="row.status === 'open'">
            <el-button link type="primary" :loading="handling === row.id" @click="handle(row as Alert, 'resolve')">已处理</el-button>
            <el-button link type="info" :loading="handling === row.id" @click="handle(row as Alert, 'ignore')">忽略</el-button>
          </template>
          <span v-else>-</span>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      v-model:current-page="page"
      v-model:page-size="perPage"
      class="pagination"
      layout="total, sizes, prev, pager, next"
      :page-sizes="[20, 50, 100]"
      :total="total"
      @current-change="list.load"
      @size-change="list.search"
    />
  </el-card>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.gap-left {
  margin-left: 8px;
}

.repeated {
  color: #e6a23c;
  font-weight: 600;
}

.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}
</style>
