<script setup lang="ts">
import {
  businessLineLabels,
  cardTypeLabels,
  chargeSpeedLabels,
  labelOf,
  money,
  operatorLabels,
  ratePercent,
  toOptions,
} from '@platform/shared'
import { computed, onMounted, ref } from 'vue'
import { productApi, type ProductPriceList } from '@/api/merchant'

const tabs = toOptions(businessLineLabels)
const active = ref('recharge')
const result = ref<ProductPriceList | null>(null)
const loading = ref(false)
const operator = ref('')
const operatorOptions = toOptions(operatorLabels)

async function load() {
  loading.value = true
  result.value = null
  try {
    result.value = await productApi.list(active.value)
  } finally {
    loading.value = false
  }
}

function switchTab() {
  operator.value = ''
  load()
}

// 商品不多，运营商筛选在前端做
const rows = computed(() => {
  const data = result.value?.data ?? []
  return operator.value ? data.filter((p) => p.operator === operator.value) : data
})

onMounted(load)
</script>

<template>
  <el-card shadow="never">
    <el-tabs v-model="active" @tab-change="switchTab">
      <el-tab-pane v-for="t in tabs" :key="t.value" :name="t.value" :label="t.label" />
    </el-tabs>

    <div v-loading="loading" class="body">
      <template v-if="result">
        <p v-if="result.level_rate !== null" class="muted">
          你当前等级在「{{ labelOf(businessLineLabels, result.business_line) }}」的返佣比例：{{ ratePercent(result.level_rate) }}
          <template v-if="result.available">（个别商品单独设置了比例，以下表每单返佣为准）</template>
        </p>

        <el-empty
          v-if="!result.available"
          :description="result.level_rate !== null ? '该业务线暂未开放，上线后按上面的等级比例返佣' : '该业务线暂未开放'"
          :image-size="80"
        />
        <el-empty v-else-if="!result.subscribed" description="还没有开通该业务线，开通后才能查看商品和下单" :image-size="80">
          <router-link :to="{ name: 'services' }">
            <el-button type="primary">去申请开通</el-button>
          </router-link>
        </el-empty>

        <template v-else>
          <el-form v-if="result.business_line === 'recharge'" inline>
            <el-form-item label="运营商">
              <el-select v-model="operator" clearable placeholder="全部" style="width: 120px">
                <el-option v-for="o in operatorOptions" :key="o.value" :value="o.value" :label="o.label" />
              </el-select>
            </el-form-item>
          </el-form>

          <el-table :data="rows" border empty-text="暂无在售商品">
            <el-table-column prop="id" label="商品 ID" width="90" />
            <el-table-column prop="name" label="商品" min-width="200" />
            <template v-if="result.business_line === 'recharge'">
              <el-table-column label="运营商" width="80">
                <template #default="{ row }">{{ labelOf(operatorLabels, row.operator) }}</template>
              </el-table-column>
              <el-table-column label="地区" width="100">
                <template #default="{ row }">{{ row.province ?? '全国' }}</template>
              </el-table-column>
              <el-table-column label="到账" width="80">
                <template #default="{ row }">{{ labelOf(chargeSpeedLabels, row.charge_speed) }}</template>
              </el-table-column>
            </template>
            <el-table-column v-else label="类型" width="90">
              <template #default="{ row }">{{ labelOf(cardTypeLabels, row.card_type) }}</template>
            </el-table-column>
            <el-table-column label="面值" width="110" align="right">
              <template #default="{ row }">{{ money(row.face_value) }}</template>
            </el-table-column>
            <el-table-column label="售价" width="110" align="right">
              <template #default="{ row }">{{ money(row.sale_price) }}</template>
            </el-table-column>
            <el-table-column label="每单返佣" width="110" align="right">
              <template #default="{ row }">{{ money(row.rebate) }}</template>
            </el-table-column>
          </el-table>
        </template>
      </template>
    </div>
  </el-card>
</template>

<style scoped>
.body {
  min-height: 120px;
}

.muted {
  margin: 0 0 12px;
  color: var(--el-text-color-secondary);
  font-size: 13px;
}
</style>
