<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
</script>

<template>
  <el-card shadow="never">
    <h2>欢迎回来，{{ auth.username }}</h2>
    <p>这里是商户管理后台，面向入驻商户，负责店铺、商品、订单等日常经营管理。</p>
  </el-card>
</template>
