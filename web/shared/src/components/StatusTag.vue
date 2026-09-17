<script setup lang="ts">
import { computed } from 'vue'
import type { LabelMap } from '../labels'

const props = defineProps<{
  map: LabelMap
  value: string | null | undefined
}>()

const item = computed(() => (props.value ? props.map[props.value] : undefined))
</script>

<template>
  <span v-if="!value">-</span>
  <el-tag v-else :type="item?.type ?? 'info'" disable-transitions>{{ item?.label ?? value }}</el-tag>
</template>
