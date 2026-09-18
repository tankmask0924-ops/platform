<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

/** 近 7 天消费柱状图：柱高是消费金额，订单数放在悬浮提示里（不做第二条 y 轴） */
const props = defineProps<{
  points: { date: string; order_count: number; amount: string }[]
}>()

// 按容器实际宽度画（viewBox = 像素），文字在任何宽度下都是 12px，不跟着缩放
const container = ref<HTMLElement>()
const width = ref(640)
const HEIGHT = 220
const PAD = { top: 16, right: 8, bottom: 28, left: 56 }
const plotW = computed(() => width.value - PAD.left - PAD.right)
const plotH = HEIGHT - PAD.top - PAD.bottom

let observer: ResizeObserver | undefined
onMounted(() => {
  observer = new ResizeObserver(([entry]) => {
    width.value = Math.max(280, Math.round(entry.contentRect.width))
  })
  if (container.value) {
    observer.observe(container.value)
  }
})
onBeforeUnmount(() => observer?.disconnect())

/** 取 1/2/5 × 10^n 的整齐上限，刻度好读 */
function niceMax(value: number): number {
  if (value <= 0) {
    return 100
  }
  const power = 10 ** Math.floor(Math.log10(value))
  const step = [1, 2, 5, 10].find((m) => m * power >= value) ?? 10
  return step * power
}

const max = computed(() => niceMax(Math.max(...props.points.map((p) => Number(p.amount)))))
const ticks = computed(() => [0, 0.5, 1].map((f) => max.value * f))
const slot = computed(() => plotW.value / Math.max(props.points.length, 1))
const barW = computed(() => Math.min(40, slot.value * 0.5))

const y = (value: number) => PAD.top + plotH - (value / max.value) * plotH

const bars = computed(() =>
  props.points.map((p, i) => {
    const amount = Math.max(0, Number(p.amount))
    const top = y(amount)
    const x = PAD.left + slot.value * i + (slot.value - barW.value) / 2
    return { ...p, x, top, height: PAD.top + plotH - top, label: p.date.slice(5) }
  }),
)

/** 顶部 4px 圆角、底部贴着基线是直角的柱子 */
function barPath(x: number, top: number, w: number, h: number): string {
  if (h <= 0) {
    return ''
  }
  const r = Math.min(4, h, w / 2)
  const bottom = top + h
  return `M${x},${bottom}V${top + r}Q${x},${top} ${x + r},${top}H${x + w - r}Q${x + w},${top} ${x + w},${top + r}V${bottom}Z`
}

const formatTick = (v: number) => (v >= 10000 ? `${v / 10000}万` : String(v))

const hover = ref<number | null>(null)
const tooltipStyle = computed(() => {
  if (hover.value === null) {
    return {}
  }
  const bar = bars.value[hover.value]
  // 柱子太高时提示框放在柱顶下方，免得被卡片上沿裁掉
  const below = bar.top < HEIGHT * 0.4
  return {
    left: `${bar.x + barW.value / 2}px`,
    top: `${bar.top}px`,
    transform: below ? 'translate(-50%, 8px)' : 'translate(-50%, calc(-100% - 8px))',
  }
})
</script>

<template>
  <div ref="container" class="trend">
    <svg :viewBox="`0 0 ${width} ${HEIGHT}`" :height="HEIGHT" role="img" aria-label="近 7 天消费金额" @mouseleave="hover = null">
      <g class="grid">
        <template v-for="t in ticks" :key="t">
          <line :x1="PAD.left" :x2="width - PAD.right" :y1="y(t)" :y2="y(t)" />
          <text :x="PAD.left - 8" :y="y(t)" text-anchor="end" dominant-baseline="middle">{{ formatTick(t) }}</text>
        </template>
      </g>
      <g v-for="(bar, i) in bars" :key="bar.date">
        <path class="bar" :class="{ active: hover === i }" :d="barPath(bar.x, bar.top, barW, bar.height)" />
        <text class="axis-label" :x="bar.x + barW / 2" :y="HEIGHT - 8" text-anchor="middle">{{ bar.label }}</text>
        <!-- 整列都是悬浮区域，比柱子本身大 -->
        <rect
          class="hit"
          :x="PAD.left + slot * i"
          :y="PAD.top"
          :width="slot"
          :height="plotH"
          @mouseenter="hover = i"
        >
          <title>{{ bar.date }}：消费 ¥{{ bar.amount }}，{{ bar.order_count }} 单</title>
        </rect>
      </g>
    </svg>
    <div v-if="hover !== null" class="tooltip" :style="tooltipStyle">
      <div class="tooltip-date">{{ bars[hover].date }}</div>
      <div>消费 <b>¥{{ bars[hover].amount }}</b></div>
      <div>订单 <b>{{ bars[hover].order_count }}</b> 单</div>
    </div>
  </div>
</template>

<style scoped>
.trend {
  position: relative;
}

svg {
  display: block;
  width: 100%;
}

.grid line {
  stroke: var(--el-border-color-lighter);
  stroke-width: 1;
}

.grid text,
.axis-label {
  fill: var(--el-text-color-secondary);
  font-size: 12px;
}

.bar {
  fill: var(--el-color-primary);
}

.bar.active {
  fill: var(--el-color-primary-dark-2);
}

.hit {
  fill: transparent;
  cursor: default;
}

.tooltip {
  position: absolute;
  padding: 6px 10px;
  border: 1px solid var(--el-border-color-light);
  border-radius: 4px;
  background: var(--el-bg-color-overlay);
  box-shadow: var(--el-box-shadow-light);
  color: var(--el-text-color-regular);
  font-size: 12px;
  line-height: 1.6;
  white-space: nowrap;
  pointer-events: none;
}

.tooltip-date {
  color: var(--el-text-color-secondary);
}
</style>
