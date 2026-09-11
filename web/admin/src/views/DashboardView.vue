<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
</script>

<template>
  <el-card shadow="never">
    <h2>欢迎回来，{{ auth.username }}</h2>
    <p>这里是系统管理后台，面向平台运营人员，负责平台级配置、商户管理和系统用户管理。</p>
  </el-card>
</template>
