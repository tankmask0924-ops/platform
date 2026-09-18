<script setup lang="ts">
import { StatusTag, toOptions, usePagedList } from '@platform/shared'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { computed, onMounted, reactive, ref } from 'vue'
import { type AdminUser, adminUserApi, type RoleOption } from '@/api/system'
import { adminUserStatusLabels, roleLabel } from '@/labels'
import { usePermissionStore } from '@/stores/permission'

const permission = usePermissionStore()
const canManage = computed(() => permission.can('admin_user.manage'))
const myId = computed(() => permission.me?.id)

const list = usePagedList<AdminUser, { keyword: string; role_id: number | ''; status: string }>(adminUserApi.list, {
  keyword: '',
  role_id: '',
  status: '',
})
const { rows, total, page, perPage, loading, filters } = list

const roles = ref<RoleOption[]>([])
// 非超管不能分配超级管理员角色
const assignableRoles = computed(() => roles.value.filter((r) => !r.is_super_admin || permission.me?.is_super_admin))
// 非超管不能动超管账号
const editable = (row: AdminUser) =>
  permission.me?.is_super_admin || !roles.value.find((r) => r.id === row.role_id)?.is_super_admin

const dialogVisible = ref(false)
const editing = ref<AdminUser | null>(null)
const saving = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({ username: '', real_name: '', role_id: undefined as number | undefined, password: '' })
const rules = computed<FormRules>(() => ({
  username: editing.value
    ? []
    : [
        { required: true, message: '请输入用户名', trigger: 'blur' },
        { pattern: /^[A-Za-z0-9_.@-]{3,64}$/, message: '3~64 位字母、数字或 _ . @ -', trigger: 'blur' },
      ],
  real_name: [{ required: true, message: '请输入姓名', trigger: 'blur' }],
  role_id: [{ required: true, message: '请选择角色', trigger: 'change' }],
  password: editing.value
    ? []
    : [
        { required: true, message: '请输入初始密码', trigger: 'blur' },
        { min: 8, message: '至少 8 位', trigger: 'blur' },
      ],
}))

function openCreate() {
  editing.value = null
  Object.assign(form, { username: '', real_name: '', role_id: undefined, password: '' })
  dialogVisible.value = true
}

function openEdit(row: AdminUser) {
  editing.value = row
  Object.assign(form, { username: row.username, real_name: row.real_name, role_id: row.role_id, password: '' })
  dialogVisible.value = true
}

async function save() {
  if (!(await formRef.value?.validate().catch(() => false))) {
    return
  }
  saving.value = true
  try {
    if (editing.value) {
      const data: { real_name: string; role_id?: number } = { real_name: form.real_name }
      if (form.role_id !== editing.value.role_id) {
        data.role_id = form.role_id
      }
      await adminUserApi.update(editing.value.id, data)
    } else {
      await adminUserApi.create({ ...form, role_id: form.role_id as number })
    }
    ElMessage.success('已保存')
    dialogVisible.value = false
    await list.load()
  } finally {
    saving.value = false
  }
}

async function toggleStatus(row: AdminUser) {
  const next = row.status === 'active' ? 'disabled' : 'active'
  try {
    await ElMessageBox.confirm(
      next === 'disabled' ? `禁用后「${row.real_name}」会立即退出登录，确定禁用吗？` : `确定启用「${row.real_name}」吗？`,
      next === 'disabled' ? '禁用管理员' : '启用管理员',
      { type: 'warning' },
    )
  } catch {
    return
  }
  await adminUserApi.changeStatus(row.id, next)
  ElMessage.success(next === 'disabled' ? '已禁用' : '已启用')
  await list.load()
}

async function resetPassword(row: AdminUser) {
  let password: string
  try {
    const result = await ElMessageBox.prompt(`给「${row.real_name}」设置新密码，对方之前的登录会失效`, '重置密码', {
      inputType: 'password',
      inputValidator: (v) => (v && v.length >= 8) || '至少 8 位',
    })
    password = result.value
  } catch {
    return
  }
  await adminUserApi.resetPassword(row.id, password)
  ElMessage.success('密码已重置，请把新密码告诉对方')
}

onMounted(async () => {
  await permission.load()
  list.load()
  roles.value = await adminUserApi.roleOptions()
})
</script>

<template>
  <el-card shadow="never">
    <div class="toolbar">
      <el-form inline @submit.prevent="list.search">
        <el-form-item>
          <el-input v-model="filters.keyword" placeholder="用户名 / 姓名" clearable style="width: 180px" />
        </el-form-item>
        <el-form-item>
          <el-select v-model="filters.role_id" placeholder="全部角色" clearable style="width: 150px">
            <el-option v-for="r in roles" :key="r.id" :label="roleLabel(r.name)" :value="r.id" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-select v-model="filters.status" placeholder="全部状态" clearable style="width: 110px">
            <el-option v-for="o in toOptions(adminUserStatusLabels)" :key="o.value" v-bind="o" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" native-type="submit">查询</el-button>
        </el-form-item>
      </el-form>
      <el-button v-if="canManage" type="primary" :icon="Plus" @click="openCreate">新建管理员</el-button>
    </div>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="username" label="用户名" min-width="140" />
      <el-table-column prop="real_name" label="姓名" min-width="110" />
      <el-table-column label="角色" min-width="110">
        <template #default="{ row }">{{ roleLabel(row.role_name) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="80">
        <template #default="{ row }"><StatusTag :map="adminUserStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column label="最近登录" width="170">
        <template #default="{ row }">{{ row.last_login_at ?? '-' }}</template>
      </el-table-column>
      <el-table-column prop="created_at" label="创建时间" width="170" />
      <el-table-column v-if="canManage" label="操作" width="200" fixed="right">
        <template #default="{ row }">
          <span v-if="!editable(row as AdminUser)" class="muted">仅超级管理员可修改</span>
          <el-button v-else link type="primary" @click="openEdit(row as AdminUser)">编辑</el-button>
          <template v-if="row.id !== myId && editable(row as AdminUser)">
            <el-button link type="primary" @click="resetPassword(row as AdminUser)">重置密码</el-button>
            <el-button link :type="row.status === 'active' ? 'danger' : 'success'" @click="toggleStatus(row as AdminUser)">
              {{ row.status === 'active' ? '禁用' : '启用' }}
            </el-button>
          </template>
          <span v-if="row.id === myId" class="muted">（我）</span>
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

  <el-dialog v-model="dialogVisible" :title="editing ? '编辑管理员' : '新建管理员'" width="460px" @closed="formRef?.clearValidate()">
    <el-form ref="formRef" :model="form" :rules="rules" label-width="80px">
      <el-form-item label="用户名" prop="username">
        <el-input v-model="form.username" :disabled="!!editing" placeholder="登录用" />
      </el-form-item>
      <el-form-item label="姓名" prop="real_name">
        <el-input v-model="form.real_name" maxlength="64" />
      </el-form-item>
      <el-form-item label="角色" prop="role_id">
        <el-select v-model="form.role_id" :disabled="editing?.id === myId" style="width: 100%">
          <el-option v-for="r in assignableRoles" :key="r.id" :label="roleLabel(r.name)" :value="r.id" />
        </el-select>
        <div v-if="editing?.id === myId" class="tip">不能修改自己的角色</div>
      </el-form-item>
      <el-form-item v-if="!editing" label="初始密码" prop="password">
        <el-input v-model="form.password" type="password" show-password autocomplete="new-password" />
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
  align-items: flex-start;
}

.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}

.muted,
.tip {
  color: #909399;
  font-size: 12px;
}
</style>
