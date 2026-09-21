<script setup lang="ts">
import {
  businessLineLabels,
  copyText,
  type CsvColumn,
  csvFilename,
  downloadCsv,
  labelOf,
  merchantOrderStatusLabels,
  money,
  StatusTag,
  toOptions,
  usePagedList,
} from '@platform/shared'
import { ElMessage, ElMessageBox } from 'element-plus'
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { disputeApi, type Order, orderApi, type OrderDetail } from '@/api/merchant'

const router = useRouter()

const list = usePagedList<Order, Record<string, string>>(orderApi.list, {
  status: '',
  business_line: '',
  order_no: '',
  merchant_order_no: '',
  created_from: '',
  created_to: '',
})
const { rows, total, page, perPage, loading, filters } = list
const statusOptions = toOptions(merchantOrderStatusLabels)
const businessLineOptions = toOptions(businessLineLabels)

const createdRange = ref<[string, string] | null>(null)
function onRangeChange(value: [string, string] | null) {
  filters.created_from = value?.[0] ?? ''
  filters.created_to = value?.[1] ?? ''
  list.search()
}

function reset() {
  Object.assign(filters, { status: '', business_line: '', order_no: '', merchant_order_no: '', created_from: '', created_to: '' })
  createdRange.value = null
  list.search()
}

// 导出的列跟下面表格一一对应；失败原因用接口给的对外文案
const columns: CsvColumn<Order>[] = [
  { header: '下单时间', value: (r) => r.created_at },
  { header: '平台订单号', value: (r) => r.order_no },
  { header: '商户订单号', value: (r) => r.merchant_order_no },
  { header: '业务线', value: (r) => labelOf(businessLineLabels, r.business_line) },
  { header: '状态', value: (r) => labelOf(merchantOrderStatusLabels, r.status) },
  { header: '订单金额', value: (r) => r.sale_price },
  { header: '冻结金额', value: (r) => r.frozen_amount },
  { header: '实扣金额', value: (r) => r.deducted_amount },
  { header: '已退款', value: (r) => r.refunded_amount },
  { header: '完成时间', value: (r) => r.completed_at },
  { header: '失败原因', value: (r) => r.fail_reason },
]

const exporting = ref(false)
async function exportCsv() {
  exporting.value = true
  try {
    const { data } = await orderApi.export({ ...filters })
    if (data.length === 0) {
      ElMessage.warning('当前筛选条件下没有记录')

      return
    }
    downloadCsv(csvFilename('订单'), columns, data)
  } finally {
    exporting.value = false
  }
}

// 详情抽屉
const detail = ref<OrderDetail | null>(null)
const detailVisible = ref(false)
const detailLoading = ref(false)

async function openDetail(orderNo: string) {
  detailVisible.value = true
  detailLoading.value = true
  detail.value = null
  try {
    detail.value = await orderApi.detail(orderNo)
  } finally {
    detailLoading.value = false
  }
}

const renotifying = ref(false)
async function renotify() {
  if (!detail.value) {
    return
  }
  renotifying.value = true
  try {
    const orderNo = detail.value.order_no
    await orderApi.renotify(orderNo)
    ElMessage.success('已重新推送回调，结果稍后显示在回调记录里')
    // 回调走异步队列，接口返回时还没发出去，等一会儿再刷新记录
    await new Promise((resolve) => setTimeout(resolve, 2000))
    await openDetail(orderNo)
  } finally {
    renotifying.value = false
  }
}

const canNotify = (status: string) => ['success', 'failed', 'cancelled', 'refunded'].includes(status)
const canDispute = (order: Order) => order.status === 'success' && ['recharge', 'card'].includes(order.business_line)

async function submitDispute(order: Order) {
  try {
    await ElMessageBox.confirm(
      `确认对订单 ${order.order_no} 提交"未到账"争议吗？平台核实后会给出处理结果，每笔订单只能提交一次。`,
      '申请售后',
      { type: 'warning' },
    )
  } catch {
    return
  }
  await disputeApi.submit(order.order_no)
  ElMessage.success('已提交，可在"售后争议"中查看处理进度')
  router.push({ name: 'disputes' })
}

onMounted(list.load)
</script>

<template>
  <el-card shadow="never">
    <el-form inline @submit.prevent="list.search">
      <el-form-item label="平台单号">
        <el-input v-model="filters.order_no" clearable style="width: 200px" />
      </el-form-item>
      <el-form-item label="商户单号">
        <el-input v-model="filters.merchant_order_no" clearable style="width: 200px" />
      </el-form-item>
      <el-form-item label="状态">
        <el-select v-model="filters.status" clearable placeholder="全部" style="width: 120px">
          <el-option v-for="o in statusOptions" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>
      <el-form-item label="业务">
        <el-select v-model="filters.business_line" clearable placeholder="全部" style="width: 120px">
          <el-option v-for="o in businessLineOptions" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>
      <el-form-item label="下单时间">
        <el-date-picker
          v-model="createdRange"
          type="datetimerange"
          value-format="YYYY-MM-DD HH:mm:ss"
          start-placeholder="开始"
          end-placeholder="结束"
          @change="onRangeChange"
        />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" native-type="submit">查询</el-button>
        <el-button @click="reset">重置</el-button>
        <el-button :loading="exporting" @click="exportCsv">导出</el-button>
      </el-form-item>
    </el-form>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="order_no" label="平台单号" min-width="200" />
      <el-table-column prop="merchant_order_no" label="商户单号" min-width="180" />
      <el-table-column label="业务" width="80">
        <template #default="{ row }">{{ labelOf(businessLineLabels, row.business_line) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }"><StatusTag :map="merchantOrderStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column label="售价" width="110" align="right">
        <template #default="{ row }">{{ money(row.sale_price) }}</template>
      </el-table-column>
      <el-table-column label="实扣" width="110" align="right">
        <template #default="{ row }">{{ money(row.deducted_amount) }}</template>
      </el-table-column>
      <el-table-column label="失败原因" min-width="160">
        <template #default="{ row }">{{ row.fail_reason ?? '-' }}</template>
      </el-table-column>
      <el-table-column prop="created_at" label="下单时间" width="170" />
      <el-table-column label="操作" width="150" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openDetail(row.order_no)">详情</el-button>
          <el-button v-if="canDispute(row as Order)" link type="warning" @click="submitDispute(row as Order)">申请售后</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      v-model:current-page="page"
      v-model:page-size="perPage"
      class="pagination"
      layout="total, sizes, prev, pager, next"
      :total="total"
      @current-change="list.load"
      @size-change="list.search"
    />
  </el-card>

  <el-drawer v-model="detailVisible" title="订单详情" size="640px">
    <div v-loading="detailLoading">
      <template v-if="detail">
        <el-descriptions :column="2" border>
          <el-descriptions-item label="平台单号" :span="2">{{ detail.order_no }}</el-descriptions-item>
          <el-descriptions-item label="商户单号" :span="2">{{ detail.merchant_order_no }}</el-descriptions-item>
          <el-descriptions-item v-if="detail.recharge_account" label="充值账号" :span="2">{{ detail.recharge_account }}</el-descriptions-item>
          <el-descriptions-item label="业务">{{ labelOf(businessLineLabels, detail.business_line) }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            <StatusTag :map="merchantOrderStatusLabels" :value="detail.status" />
          </el-descriptions-item>
          <el-descriptions-item label="售价">{{ money(detail.sale_price) }}</el-descriptions-item>
          <el-descriptions-item label="冻结">{{ money(detail.frozen_amount) }}</el-descriptions-item>
          <el-descriptions-item label="实扣">{{ money(detail.deducted_amount) }}</el-descriptions-item>
          <el-descriptions-item label="已退款">{{ money(detail.refunded_amount) }}</el-descriptions-item>
          <el-descriptions-item label="下单时间">{{ detail.created_at ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="完成时间">{{ detail.completed_at ?? '-' }}</el-descriptions-item>
          <el-descriptions-item v-if="detail.fail_reason" label="失败原因" :span="2">
            {{ detail.fail_reason }}（{{ detail.fail_code }}）
          </el-descriptions-item>
          <el-descriptions-item v-if="detail.card_no" label="卡号" :span="2">
            <code>{{ detail.card_no }}</code>
            <el-button link type="primary" @click="copyText(detail.card_no)">复制</el-button>
          </el-descriptions-item>
          <el-descriptions-item v-if="detail.card_pwd" label="卡密" :span="2">
            <code>{{ detail.card_pwd }}</code>
            <el-button link type="primary" @click="copyText(detail.card_pwd)">复制</el-button>
          </el-descriptions-item>
        </el-descriptions>

        <div class="section-header">
          <h4>回调记录</h4>
          <el-button v-if="canNotify(detail.status)" size="small" :loading="renotifying" @click="renotify">重新推送</el-button>
        </div>
        <el-table :data="detail.notify_logs" border size="small" empty-text="暂无回调记录">
          <el-table-column prop="attempt_no" label="次数" width="60" />
          <el-table-column label="结果" width="80">
            <template #default="{ row }">
              <el-tag :type="row.success ? 'success' : 'danger'" size="small">{{ row.success ? '成功' : '失败' }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="HTTP" width="70">
            <template #default="{ row }">{{ row.http_status ?? '-' }}</template>
          </el-table-column>
          <el-table-column prop="response_body" label="响应" min-width="140" show-overflow-tooltip />
          <el-table-column prop="created_at" label="时间" width="160" />
        </el-table>
      </template>
    </div>
  </el-drawer>
</template>

<style scoped>
.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}

.section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin: 20px 0 8px;
}

.section-header h4 {
  margin: 0;
}
</style>
