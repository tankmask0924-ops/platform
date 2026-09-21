<script setup lang="ts">
import {
  balanceLogTypeLabels,
  businessLineLabels,
  csvFilename,
  downloadCsv,
  isNegative,
  labelOf,
  StatusTag,
  toOptions,
} from '@platform/shared'
import { onMounted, reactive, ref } from 'vue'
import {
  type BalanceFlowRow,
  type ProfitGroupBy,
  type ProfitReport,
  type ProfitRow,
  reportApi,
} from '@/api/admin'
import { reportGroupByLabels } from '@/labels'

/** 默认最近 30 天（含今天），跟后端 FinanceReportService::DEFAULT_DAYS 一致 */
function isoDay(offsetDays = 0): string {
  const date = new Date()
  date.setDate(date.getDate() + offsetDays)
  return date.toISOString().slice(0, 10)
}

const groupByOptions = toOptions(reportGroupByLabels)
const filters = reactive({ group_by: 'day' as ProfitGroupBy, from: isoDay(-29), to: isoDay(), merchant_id: '' })
const range = ref<[string, string]>([filters.from, filters.to])

const loading = ref(false)
const profit = ref<ProfitReport | null>(null)
const flows = ref<BalanceFlowRow[]>([])

async function load() {
  loading.value = true
  try {
    const params = { ...filters }
    const [profitResult, flowResult] = await Promise.all([
      reportApi.profit(params),
      reportApi.balanceFlows({ from: params.from, to: params.to, merchant_id: params.merchant_id }),
    ])
    profit.value = profitResult
    flows.value = flowResult.data
  } finally {
    loading.value = false
  }
}

function onRangeChange(value: [string, string] | null) {
  filters.from = value?.[0] ?? isoDay(-29)
  filters.to = value?.[1] ?? isoDay()
  range.value = [filters.from, filters.to]
  load()
}

function reset() {
  Object.assign(filters, { group_by: 'day', from: isoDay(-29), to: isoDay(), merchant_id: '' })
  range.value = [filters.from, filters.to]
  load()
}

/** 分组列显示什么：按天是日期，按业务线转中文，其余用后端给的名称（商户是手机号/邮箱） */
function groupLabel(row: ProfitRow): string {
  if (row.key === null) {
    return '未知'
  }
  if (filters.group_by === 'day') {
    return row.key
  }
  if (filters.group_by === 'business_line') {
    return labelOf(businessLineLabels, row.key)
  }
  return row.label ? `${row.label}（#${row.key}）` : `#${row.key}`
}

function exportCsv() {
  if (!profit.value) {
    return
  }
  downloadCsv<ProfitRow>(
    csvFilename(`财务报表-${filters.group_by}-${filters.from}_${filters.to}`),
    [
      { header: labelOf(reportGroupByLabels, filters.group_by), value: (row) => groupLabel(row) },
      { header: '成功订单数', value: (row) => row.orders },
      { header: '售价合计', value: (row) => row.sale_total },
      { header: '成本合计', value: (row) => row.cost_total },
      { header: '订单毛利', value: (row) => row.gross_profit },
      { header: '商户返佣', value: (row) => row.merchant_rebate },
      { header: '供应商返佣', value: (row) => row.supplier_rebate },
      { header: '返佣收支', value: (row) => row.rebate_balance },
      { header: '合计利润', value: (row) => row.total_profit },
      { header: '已退款笔数', value: (row) => row.refunded_count },
      { header: '已退款金额', value: (row) => row.refunded_amount },
    ],
    profit.value.data,
  )
}

onMounted(load)
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>财务报表</span>
          <el-button :disabled="!profit" @click="exportCsv">导出 CSV</el-button>
        </div>
      </template>

      <el-alert type="info" :closable="false" show-icon class="tip">
        按订单完成时间取数：订单毛利（售价 − 成本）和返佣收支（供应商返佣 − 商户返佣）分开统计，最后相加得到合计利润。
        已退款订单不计毛利、单独列出；返佣只算待到账和已到账，作废和已扣回的不算支出。话费、卡券没有供应商返佣（电影票、快递三期）。
      </el-alert>

      <el-form inline @submit.prevent="load">
        <el-form-item label="统计维度">
          <el-select v-model="filters.group_by" style="width: 140px" @change="load">
            <el-option v-for="o in groupByOptions" :key="o.value" :value="o.value" :label="o.label" />
          </el-select>
        </el-form-item>
        <el-form-item label="日期">
          <el-date-picker
            v-model="range"
            type="daterange"
            value-format="YYYY-MM-DD"
            :clearable="false"
            start-placeholder="开始"
            end-placeholder="结束"
            style="width: 240px"
            @change="onRangeChange"
          />
        </el-form-item>
        <el-form-item label="商户 ID">
          <el-input v-model="filters.merchant_id" placeholder="全部商户" clearable style="width: 140px" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" native-type="submit">查询</el-button>
          <el-button @click="reset">重置</el-button>
        </el-form-item>
      </el-form>

      <el-row v-if="profit" :gutter="12" class="stats">
        <el-col :span="6">
          <el-statistic title="订单毛利" :value="Number(profit.summary.gross_profit)" :precision="2" />
        </el-col>
        <el-col :span="6">
          <el-statistic title="返佣收支" :value="Number(profit.summary.rebate_balance)" :precision="2" />
        </el-col>
        <el-col :span="6">
          <el-statistic title="合计利润" :value="Number(profit.summary.total_profit)" :precision="2" />
        </el-col>
        <el-col :span="6">
          <el-statistic title="成功订单" :value="profit.summary.orders" />
        </el-col>
      </el-row>
    </el-card>

    <el-card shadow="never" class="gap-top">
      <template #header>订单毛利与返佣收支</template>
      <el-table :data="profit?.data ?? []" border empty-text="这段时间没有完成的订单">
        <el-table-column :label="labelOf(reportGroupByLabels, filters.group_by)" min-width="170">
          <template #default="{ row }">{{ groupLabel(row as ProfitRow) }}</template>
        </el-table-column>
        <el-table-column prop="orders" label="成功订单" width="100" align="right" />
        <el-table-column prop="sale_total" label="售价合计" width="120" align="right" />
        <el-table-column prop="cost_total" label="成本合计" width="120" align="right" />
        <el-table-column label="订单毛利" width="120" align="right">
          <template #default="{ row }">
            <span :class="{ negative: isNegative(row.gross_profit) }">{{ row.gross_profit }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="merchant_rebate" label="商户返佣" width="110" align="right" />
        <el-table-column prop="supplier_rebate" label="供应商返佣" width="110" align="right" />
        <el-table-column label="返佣收支" width="110" align="right">
          <template #default="{ row }">
            <span :class="{ negative: isNegative(row.rebate_balance) }">{{ row.rebate_balance }}</span>
          </template>
        </el-table-column>
        <el-table-column label="合计利润" width="120" align="right">
          <template #default="{ row }">
            <strong :class="{ negative: isNegative(row.total_profit) }">{{ row.total_profit }}</strong>
          </template>
        </el-table-column>
        <el-table-column label="已退款" width="140" align="right">
          <template #default="{ row }">
            <span v-if="row.refunded_count === 0">-</span>
            <span v-else class="negative">{{ row.refunded_count }} 笔 / {{ row.refunded_amount }}</span>
          </template>
        </el-table-column>
      </el-table>
      <div v-if="profit" class="summary">
        合计：成功订单 {{ profit.summary.orders }} 笔，售价 {{ profit.summary.sale_total }}，成本
        {{ profit.summary.cost_total }}，毛利 {{ profit.summary.gross_profit }}，商户返佣
        {{ profit.summary.merchant_rebate }}，合计利润 {{ profit.summary.total_profit }}
      </div>
    </el-card>

    <el-card shadow="never" class="gap-top">
      <template #header>资金流水汇总</template>
      <el-alert type="info" :closable="false" show-icon class="tip">
        按流水发生时间统计，跟上面的毛利不是同一条时间轴，不要直接相减。冻结、解冻只是可用余额和冻结余额之间的搬运，不是平台收入。
      </el-alert>
      <el-table :data="flows" border empty-text="这段时间没有资金流水">
        <el-table-column label="类型" width="160">
          <template #default="{ row }"><StatusTag :map="balanceLogTypeLabels" :value="row.type" /></template>
        </el-table-column>
        <el-table-column prop="count" label="笔数" width="120" align="right" />
        <el-table-column label="金额合计" min-width="140" align="right">
          <template #default="{ row }">
            <span :class="{ negative: isNegative(row.amount) }">{{ row.amount }}</span>
            <span v-if="row.type === 'adjustment'" class="muted">（净额）</span>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.tip {
  margin-bottom: 16px;
}

.stats {
  margin-top: 8px;
}

.gap-top {
  margin-top: 16px;
}

.summary {
  margin-top: 12px;
  color: #606266;
  font-size: 13px;
  text-align: right;
}

.negative {
  color: #f56c6c;
}

.muted {
  margin-left: 4px;
  color: #909399;
  font-size: 12px;
}
</style>
