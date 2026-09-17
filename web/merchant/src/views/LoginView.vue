<script setup lang="ts">
import { type LoginForm, LoginPanel } from '@platform/shared'
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { login } from '@/api/auth'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const loading = ref(false)
const title = import.meta.env.VITE_APP_TITLE

async function onSubmit(form: LoginForm) {
  loading.value = true
  try {
    const result = await login(form)
    auth.setSession(result.token, result.username)
    const redirect = route.query.redirect
    await router.replace(typeof redirect === 'string' && redirect.startsWith('/') ? redirect : '/')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <LoginPanel :title="title" :loading="loading" @submit="onSubmit">
    <template #footer>
      还没有账号？<router-link :to="{ name: 'register' }">注册商户</router-link>
      <div class="hint">使用注册时填写的手机号或邮箱登录</div>
    </template>
  </LoginPanel>
</template>

<style scoped>
.hint {
  margin-top: 8px;
  color: #909399;
  font-size: 12px;
}
</style>
