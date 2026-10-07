<script setup lang="ts">
import { RechargeStatsPanel } from '@platform/shared'
import { rechargeStatsApi } from '@/api/admin'
</script>

<template>
  <RechargeStatsPanel :fetcher="rechargeStatsApi.get" show-merchant-filter />
</template>
