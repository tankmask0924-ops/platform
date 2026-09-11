<script setup lang="ts">
import { AppLayout } from '@platform/shared'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { menus } from './menus'

const auth = useAuthStore()
const router = useRouter()
const title = import.meta.env.VITE_APP_TITLE

function logout() {
  auth.clear()
  router.push({ name: 'login' })
}
</script>

<template>
  <AppLayout :title="title" :menus="menus" :username="auth.username" @logout="logout" />
</template>
