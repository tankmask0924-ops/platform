<script setup lang="ts">
import { Plus } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { onMounted, reactive, ref } from 'vue'
import { type User, userApi, type UserForm } from '@/api/user'

const perPage = 15
const loading = ref(false)
const users = ref<User[]>([])
const page = ref(1)

async function fetchUsers() {
  loading.value = true
  try {
    users.value = await userApi.list({ page: page.value, per_page: perPage })
  } finally {
    loading.value = false
  }
}

function changePage(delta: number) {
  page.value += delta
  fetchUsers()
}

const dialogVisible = ref(false)
const editingId = ref<number | null>(null)
const saving = ref(false)
const formRef = ref<FormInstance>()
const form = reactive<UserForm>({ name: '', email: '' })
const rules: FormRules<UserForm> = {
  name: [
    { required: true, message: '请输入姓名', trigger: 'blur' },
    { max: 64, message: '姓名最多 64 个字符', trigger: 'blur' },
  ],
  email: [
    { required: true, message: '请输入邮箱', trigger: 'blur' },
    { type: 'email', message: '邮箱格式不正确', trigger: 'blur' },
  ],
}

function openDialog(user?: User) {
  editingId.value = user?.id ?? null
  form.name = user?.name ?? ''
  form.email = user?.email ?? ''
  dialogVisible.value = true
}

async function submit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  saving.value = true
  try {
    if (editingId.value === null) {
      await userApi.create({ ...form })
    } else {
      await userApi.update(editingId.value, { ...form })
    }
    ElMessage.success('保存成功')
    dialogVisible.value = false
    await fetchUsers()
  } finally {
    saving.value = false
  }
}

async function remove(user: User) {
  try {
    await ElMessageBox.confirm(`确定删除用户「${user.name}」吗？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  await userApi.remove(user.id)
  ElMessage.success('删除成功')
  // 删掉的是当前页最后一条时回到上一页
  if (users.value.length === 1 && page.value > 1) {
    page.value--
  }
  await fetchUsers()
}

onMounted(fetchUsers)
</script>

<template>
  <el-card shadow="never">
    <div class="toolbar">
      <el-button type="primary" :icon="Plus" @click="openDialog()">新增用户</el-button>
    </div>

    <el-table v-loading="loading" :data="users" border>
      <el-table-column prop="id" label="ID" width="80" />
      <el-table-column prop="name" label="姓名" />
      <el-table-column prop="email" label="邮箱" />
      <el-table-column prop="created_at" label="创建时间" width="180" />
      <el-table-column label="操作" width="140" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openDialog(row as User)">编辑</el-button>
          <el-button link type="danger" @click="remove(row as User)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <!-- 后端 GET /users 目前不返回总数，只能按本页是否满页判断有没有下一页 -->
    <div class="pagination">
      <el-button :disabled="page <= 1 || loading" @click="changePage(-1)">上一页</el-button>
      <span>第 {{ page }} 页</span>
      <el-button :disabled="users.length < perPage || loading" @click="changePage(1)">下一页</el-button>
    </div>
  </el-card>

  <el-dialog
    v-model="dialogVisible"
    :title="editingId === null ? '新增用户' : '编辑用户'"
    width="480px"
    @closed="formRef?.clearValidate()"
  >
    <el-form ref="formRef" :model="form" :rules="rules" label-width="60px">
      <el-form-item label="姓名" prop="name">
        <el-input v-model="form.name" maxlength="64" />
      </el-form-item>
      <el-form-item label="邮箱" prop="email">
        <el-input v-model="form.email" maxlength="128" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="submit">保存</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.toolbar {
  margin-bottom: 16px;
}

.pagination {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 12px;
  margin-top: 16px;
}
</style>
