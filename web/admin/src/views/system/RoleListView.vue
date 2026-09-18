<script setup lang="ts">
import { labelOf } from '@platform/shared'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { computed, onMounted, reactive, ref } from 'vue'
import { type Permission, type Role, roleApi } from '@/api/system'
import { moduleLabels, roleLabel } from '@/labels'
import { usePermissionStore } from '@/stores/permission'

const permission = usePermissionStore()
const canManage = computed(() => permission.can('role.manage'))

const roles = ref<Role[]>([])
const permissions = ref<Permission[]>([])
const loading = ref(false)

/** 按模块分组展示权限 */
const groups = computed(() => {
  const map = new Map<string, Permission[]>()
  for (const p of permissions.value) {
    map.set(p.module, [...(map.get(p.module) ?? []), p])
  }
  return [...map.entries()].map(([module, items]) => ({ module, items }))
})
const permissionName = (code: string) => permissions.value.find((p) => p.code === code)?.name ?? code

async function load() {
  loading.value = true
  try {
    ;[roles.value, permissions.value] = await Promise.all([roleApi.list(), roleApi.permissions()])
  } finally {
    loading.value = false
  }
}

const dialogVisible = ref(false)
const editing = ref<Role | null>(null)
const saving = ref(false)
const form = reactive({ name: '', remark: '', permissions: [] as string[] })

// 自己所在的角色不能改权限；非超管只能勾自己有的权限（已有的可以取消）
const isOwnRole = computed(() => editing.value?.id === permission.me?.role_id)
const grantable = (code: string) =>
  permission.me?.is_super_admin || permission.can(code) || (editing.value?.permissions.includes(code) ?? false)

function openCreate() {
  editing.value = null
  Object.assign(form, { name: '', remark: '', permissions: [] })
  dialogVisible.value = true
}

function openEdit(role: Role) {
  editing.value = role
  Object.assign(form, { name: role.name, remark: role.remark ?? '', permissions: [...role.permissions] })
  dialogVisible.value = true
}

async function save() {
  if (form.name.trim() === '') {
    ElMessage.error('请输入角色名称')
    return
  }
  saving.value = true
  try {
    if (editing.value) {
      const data: { name?: string; remark: string; permissions?: string[] } = { remark: form.remark }
      if (!editing.value.is_system) {
        data.name = form.name
      }
      if (!isOwnRole.value) {
        data.permissions = form.permissions
      }
      await roleApi.update(editing.value.id, data)
    } else {
      await roleApi.create({ ...form })
    }
    ElMessage.success('已保存')
    dialogVisible.value = false
    await load()
  } finally {
    saving.value = false
  }
}

async function remove(role: Role) {
  try {
    await ElMessageBox.confirm(`确定删除角色「${role.name}」吗？`, '删除角色', { type: 'warning' })
  } catch {
    return
  }
  await roleApi.remove(role.id)
  ElMessage.success('已删除')
  await load()
}

onMounted(async () => {
  await permission.load()
  await load()
})
</script>

<template>
  <el-card shadow="never">
    <div class="toolbar">
      <span class="muted">超级管理员固定拥有全部权限；预置角色不能改名和删除，权限可以调整。</span>
      <el-button v-if="canManage" type="primary" :icon="Plus" @click="openCreate">新建角色</el-button>
    </div>

    <el-table v-loading="loading" :data="roles" border>
      <el-table-column label="角色" width="140">
        <template #default="{ row }">
          {{ roleLabel(row.name) }}
          <el-tag v-if="row.is_system" size="small" type="info">预置</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="备注" min-width="160">
        <template #default="{ row }">{{ row.remark ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="权限" min-width="320">
        <template #default="{ row }">
          <span v-if="row.is_super_admin">全部权限</span>
          <span v-else-if="row.permissions.length === 0" class="muted">无</span>
          <el-tag v-for="code in row.permissions" v-else :key="code" size="small" class="perm">{{ permissionName(code) }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="user_count" label="管理员数" width="90" align="center" />
      <el-table-column v-if="canManage" label="操作" width="120" fixed="right">
        <template #default="{ row }">
          <template v-if="!row.is_super_admin">
            <el-button link type="primary" @click="openEdit(row as Role)">编辑</el-button>
            <el-button v-if="!row.is_system" link type="danger" :disabled="row.user_count > 0" @click="remove(row as Role)">删除</el-button>
          </template>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <el-dialog v-model="dialogVisible" :title="editing ? '编辑角色' : '新建角色'" width="680px">
    <el-form label-width="80px">
      <el-form-item label="名称" required>
        <el-input v-model="form.name" maxlength="32" :disabled="editing?.is_system" />
      </el-form-item>
      <el-form-item label="备注">
        <el-input v-model="form.remark" maxlength="255" />
      </el-form-item>
      <el-form-item label="权限">
        <div v-if="isOwnRole" class="muted">不能修改自己所在角色的权限</div>
        <el-checkbox-group v-model="form.permissions" :disabled="isOwnRole" class="perm-groups">
          <div v-for="group in groups" :key="group.module" class="perm-group">
            <div class="perm-group-title">{{ labelOf(moduleLabels, group.module) }}</div>
            <el-checkbox v-for="p in group.items" :key="p.code" :value="p.code" :disabled="!grantable(p.code)">
              {{ p.name }}
            </el-checkbox>
          </div>
        </el-checkbox-group>
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="save">保存</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.muted {
  color: #909399;
  font-size: 12px;
}

.perm {
  margin: 2px 4px 2px 0;
}

.perm-groups {
  width: 100%;
}

.perm-group {
  margin-bottom: 8px;
}

.perm-group-title {
  font-weight: 600;
  line-height: 24px;
}
</style>
