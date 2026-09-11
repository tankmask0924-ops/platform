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
  <LoginPanel :title="title" :loading="loading" @submit="onSubmit" />
</template>
