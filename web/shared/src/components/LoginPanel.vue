<script setup lang="ts">
import { Lock, User } from '@element-plus/icons-vue'
import type { FormInstance, FormRules } from 'element-plus'
import { reactive, ref } from 'vue'
import type { LoginForm } from '../types'

defineProps<{
  title: string
  loading?: boolean
}>()

const emit = defineEmits<{
  submit: [form: LoginForm]
}>()

const formRef = ref<FormInstance>()
const form = reactive<LoginForm>({ username: '', password: '' })
const rules: FormRules<LoginForm> = {
  username: [{ required: true, message: '请输入账号', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' }],
}

async function onSubmit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (valid) {
    emit('submit', { ...form })
  }
}
</script>

<template>
  <div class="login">
    <el-card class="card" shadow="always">
      <h1 class="title">{{ title }}</h1>
      <el-form ref="formRef" :model="form" :rules="rules" size="large" @submit.prevent="onSubmit">
        <el-form-item prop="username">
          <el-input v-model="form.username" placeholder="账号" :prefix-icon="User" />
        </el-form-item>
        <el-form-item prop="password">
          <el-input v-model="form.password" type="password" placeholder="密码" :prefix-icon="Lock" show-password />
        </el-form-item>
        <el-button type="primary" size="large" class="submit" native-type="submit" :loading="loading">登录</el-button>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.login {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  background: linear-gradient(135deg, #001529 0%, #0c3a6b 100%);
}

.card {
  width: 380px;
  padding: 12px 8px;
}

.title {
  margin: 0 0 28px;
  text-align: center;
  font-size: 24px;
  font-weight: 600;
}

.submit {
  width: 100%;
}
</style>
