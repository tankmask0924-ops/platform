<script setup lang="ts">
import { computed } from 'vue'
import { money } from '../format'
import type { MovieDetail } from '../types'

const props = defineProps<{ detail: MovieDetail }>()

const isAdmin = computed(() => props.detail.unit_cost !== undefined)

const seatText = computed(() =>
  props.detail.seats
    .map((seat) => {
      const name = seat.row_label && seat.col_label ? `${seat.row_label}排${seat.col_label}座` : seat.seat_code
      return seat.love_status ? `${name}（情侣座）` : name
    })
    .join('、'),
)

/** 芒果的取票码结构没有文档，常见是字符串或 {code, verify} 这种对象，都转成一行文字 */
function ticketText(ticket: unknown): string {
  if (typeof ticket === 'string' || typeof ticket === 'number') {
    return String(ticket)
  }
  if (ticket && typeof ticket === 'object') {
    return Object.values(ticket as Record<string, unknown>)
      .filter((v) => v !== null && v !== '')
      .join(' / ')
  }
  return '-'
}
</script>

<template>
  <el-descriptions :column="2" border>
    <el-descriptions-item label="影院">{{ detail.cinema_name ?? '-' }}</el-descriptions-item>
    <el-descriptions-item label="影片">{{ detail.film_name ?? '-' }}</el-descriptions-item>
    <el-descriptions-item label="开场时间">{{ detail.show_time }}</el-descriptions-item>
    <el-descriptions-item label="取票手机号">{{ detail.mobile }}</el-descriptions-item>
    <el-descriptions-item label="座位" :span="2">{{ seatText }}（{{ detail.seat_count }} 张）</el-descriptions-item>
    <el-descriptions-item label="每张售价">{{ money(detail.unit_price) }}</el-descriptions-item>
    <el-descriptions-item v-if="isAdmin" label="每张成本">{{ money(detail.unit_cost) }}</el-descriptions-item>
    <el-descriptions-item v-if="isAdmin" label="供应商返佣">{{ money(detail.supplier_rebate) }}</el-descriptions-item>
    <el-descriptions-item label="锁座有效期">{{ detail.lock_expire_at }}</el-descriptions-item>
    <el-descriptions-item label="确认出票">{{ detail.confirmed_at ?? '未确认' }}</el-descriptions-item>
    <el-descriptions-item label="取票码" :span="2">
      <template v-if="detail.ticket_codes.length > 0">
        <div v-for="(ticket, i) in detail.ticket_codes" :key="i"><code>{{ ticketText(ticket) }}</code></div>
      </template>
      <span v-else>-</span>
    </el-descriptions-item>
    <el-descriptions-item v-if="isAdmin" label="场次 ID" :span="2"><code>{{ detail.show_id }}</code></el-descriptions-item>
  </el-descriptions>
</template>
