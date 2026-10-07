<script setup lang="ts">
import { RechargeStatsPanel } from '@platform/shared'
import { rechargeStatsApi } from '@/api/merchant'
</script>

<template>
  <RechargeStatsPanel :fetcher="rechargeStatsApi.get" />
</template>
