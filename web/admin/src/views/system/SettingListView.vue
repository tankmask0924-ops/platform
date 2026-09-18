<script setup lang="ts">
import { ElMessage, ElMessageBox } from 'element-plus'
import { computed, onMounted, reactive, ref } from 'vue'
import { type Setting, settingApi } from '@/api/system'
import { usePermissionStore } from '@/stores/permission'

const permission = usePermissionStore()
const canManage = computed(() => permission.can('setting.manage'))

const settings = ref<Setting[]>([])
const loading = ref(false)
/** 正在编辑的参数 key → 输入框里的值 */
const drafts = reactive<Record<string, string>>({})
const saving = ref<string | null>(null)

async function load() {
  loading.value = true
  try {
    settings.value = await settingApi.list()
  } finally {
    loading.value = false
  }
}

function edit(row: Setting) {
  drafts[row.key] = String(row.value)
}

function cancel(row: Setting) {
  delete drafts[row.key]
}

async function save(row: Setting, value: string | null) {
  saving.value = row.key
  try {
    settings.value = await settingApi.update(row.key, value)
    delete drafts[row.key]
    ElMessage.success(value === null ? '已恢复默认值' : '已保存')
  } finally {
    saving.value = null
  }
}

async function restoreDefault(row: Setting) {
  try {
    await ElMessageBox.confirm(`把「${row.name}」恢复为默认值 ${row.default} ${row.unit}？`, '恢复默认', { type: 'warning' })
  } catch {
    return
  }
  await save(row, null)
}

onMounted(async () => {
  await permission.load()
  await load()
})
</script>

<template>
  <el-card shadow="never">
    <p class="muted">修改后立即生效，每次修改都会记入操作日志。</p>
    <el-table v-loading="loading" :data="settings" border>
      <el-table-column label="参数" width="160">
        <template #default="{ row }">
          <div>{{ row.name }}</div>
          <div class="muted">{{ row.key }}</div>
        </template>
      </el-table-column>
      <el-table-column label="当前值" width="260">
        <template #default="{ row }">
          <div v-if="row.key in drafts" class="edit">
            <el-input v-model="drafts[row.key]" size="small" style="width: 130px" @keyup.enter="save(row as Setting, drafts[row.key])">
              <template #append>{{ row.unit }}</template>
            </el-input>
            <el-button size="small" type="primary" :loading="saving === row.key" @click="save(row as Setting, drafts[row.key])">保存</el-button>
            <el-button size="small" @click="cancel(row as Setting)">取消</el-button>
          </div>
          <template v-else>
            <strong>{{ row.value }}</strong> {{ row.unit }}
            <el-tag v-if="row.is_default" size="small" type="info">默认</el-tag>
          </template>
        </template>
      </el-table-column>
      <el-table-column label="说明" min-width="260">
        <template #default="{ row }">
          <div>{{ row.description }}</div>
          <div class="muted">范围 {{ row.min }}~{{ row.max }} {{ row.unit }}，默认 {{ row.default }} {{ row.unit }}</div>
        </template>
      </el-table-column>
      <el-table-column label="最近修改" width="170">
        <template #default="{ row }">
          <template v-if="row.updated_at">
            <div>{{ row.updated_by ?? '-' }}</div>
            <div class="muted">{{ row.updated_at }}</div>
          </template>
          <span v-else class="muted">-</span>
        </template>
      </el-table-column>
      <el-table-column v-if="canManage" label="操作" width="140" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" :disabled="row.key in drafts" @click="edit(row as Setting)">修改</el-button>
          <el-button v-if="!row.is_default" link type="warning" @click="restoreDefault(row as Setting)">恢复默认</el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>
</template>

<style scoped>
.muted {
  color: #909399;
  font-size: 12px;
}

.edit {
  display: flex;
  gap: 4px;
  align-items: center;
}

.edit .el-button + .el-button {
  margin-left: 0;
}
</style>
