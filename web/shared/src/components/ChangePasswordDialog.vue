<script setup lang="ts">
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { reactive, ref } from 'vue'

/** 修改自己的密码；submit 返回新 token（后端让旧登录全部失效） */
const props = defineProps<{
  submit: (oldPassword: string, newPassword: string) => Promise<{ token: string }>
}>()

const emit = defineEmits<{
  changed: [token: string]
}>()

const visible = defineModel<boolean>({ required: true })
const formRef = ref<FormInstance>()
const saving = ref(false)
const form = reactive({ old_password: '', new_password: '', confirm: '' })
const rules: FormRules = {
  old_password: [{ required: true, message: '请输入原密码', trigger: 'blur' }],
  new_password: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 8, message: '新密码至少 8 位', trigger: 'blur' },
  ],
  confirm: [
    {
      validator: (_rule, value: string, callback) =>
        value === form.new_password ? callback() : callback(new Error('两次输入的新密码不一致')),
      trigger: 'blur',
    },
  ],
}

function reset() {
  Object.assign(form, { old_password: '', new_password: '', confirm: '' })
  formRef.value?.clearValidate()
}

async function onSubmit() {
  if (!(await formRef.value?.validate().catch(() => false))) {
    return
  }
  saving.value = true
  try {
    const { token } = await props.submit(form.old_password, form.new_password)
    ElMessage.success('密码已修改，其他地方的登录已失效')
    visible.value = false
    emit('changed', token)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <el-dialog v-model="visible" title="修改密码" width="420px" @closed="reset">
    <el-form ref="formRef" :model="form" :rules="rules" label-width="90px" @submit.prevent="onSubmit">
      <el-form-item label="原密码" prop="old_password">
        <el-input v-model="form.old_password" type="password" show-password autocomplete="current-password" />
      </el-form-item>
      <el-form-item label="新密码" prop="new_password">
        <el-input v-model="form.new_password" type="password" show-password autocomplete="new-password" />
      </el-form-item>
      <el-form-item label="确认新密码" prop="confirm">
        <el-input v-model="form.confirm" type="password" show-password autocomplete="new-password" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="visible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="onSubmit">确定</el-button>
    </template>
  </el-dialog>
</template>
