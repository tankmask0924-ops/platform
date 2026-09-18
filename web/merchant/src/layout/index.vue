<script setup lang="ts">
import { AppLayout, ChangePasswordDialog } from '@platform/shared'
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { changePassword } from '@/api/auth'
import { useAuthStore } from '@/stores/auth'
import { menus } from './menus'

const auth = useAuthStore()
const router = useRouter()
const title = import.meta.env.VITE_APP_TITLE
const passwordDialog = ref(false)

function logout() {
  auth.clear()
  router.push({ name: 'login' })
}
</script>

<template>
  <AppLayout :title="title" :menus="menus" :username="auth.username" @logout="logout" @change-password="passwordDialog = true" />
  <ChangePasswordDialog v-model="passwordDialog" :submit="changePassword" @changed="auth.setToken" />
</template>
