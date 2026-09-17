<script setup lang="ts">
import { businessLineLabels, labelOf, money, orderStatusLabels, StatusTag, toOptions, usePagedList } from '@platform/shared'
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { type Order, orderApi } from '@/api/admin'

const route = useRoute()
const router = useRouter()

const initial = {
  status: typeof route.query.status === 'string' ? route.query.status : '',
  business_line: '',
  merchant_id: typeof route.query.merchant_id === 'string' ? route.query.merchant_id : '',
  order_no: '',
  merchant_order_no: '',
  created_from: '',
  created_to: '',
}
const list = usePagedList<Order, typeof initial>(orderApi.list, initial)
const { rows, total, page, perPage, loading, filters } = list

const createdRange = ref<[string, string] | null>(null)
function onRangeChange(value: [string, string] | null) {
  filters.created_from = value?.[0] ?? ''
  filters.created_to = value?.[1] ?? ''
  list.search()
}

function reset() {
  Object.assign(filters, {
    status: '',
    business_line: '',
    merchant_id: '',
    order_no: '',
    merchant_order_no: '',
    created_from: '',
    created_to: '',
  })
  createdRange.value = null
  list.search()
}

function open(id: number) {
  router.push({ name: 'order-detail', params: { id } })
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
        <el-input v-model="filters.merchant_order_no" clearable style="width: 180px" />
      </el-form-item>
      <el-form-item label="商户 ID">
        <el-input v-model="filters.merchant_id" clearable style="width: 100px" />
      </el-form-item>
      <el-form-item label="状态">
        <el-select v-model="filters.status" clearable placeholder="全部" style="width: 110px">
          <el-option v-for="o in toOptions(orderStatusLabels)" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>
      <el-form-item label="业务">
        <el-select v-model="filters.business_line" clearable placeholder="全部" style="width: 110px">
          <el-option v-for="o in toOptions(businessLineLabels)" :key="o.value" :value="o.value" :label="o.label" />
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
      </el-form-item>
    </el-form>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="id" label="ID" width="80" />
      <el-table-column prop="order_no" label="平台单号" min-width="200" />
      <el-table-column label="商户" width="80">
        <template #default="{ row }">
          <router-link :to="{ name: 'merchant-detail', params: { id: row.merchant_id } }">#{{ row.merchant_id }}</router-link>
        </template>
      </el-table-column>
      <el-table-column prop="merchant_order_no" label="商户单号" min-width="160" />
      <el-table-column label="业务" width="70">
        <template #default="{ row }">{{ labelOf(businessLineLabels, row.business_line) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="80">
        <template #default="{ row }"><StatusTag :map="orderStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column label="售价" width="100" align="right">
        <template #default="{ row }">{{ money(row.sale_price) }}</template>
      </el-table-column>
      <el-table-column label="成本" width="100" align="right">
        <template #default="{ row }">{{ money(row.cost_price) }}</template>
      </el-table-column>
      <el-table-column label="失败原因" min-width="140" show-overflow-tooltip>
        <template #default="{ row }">{{ row.fail_reason ?? '-' }}</template>
      </el-table-column>
      <el-table-column prop="created_at" label="下单时间" width="170" />
      <el-table-column label="操作" width="80" fixed="right">
        <template #default="{ row }">
          <el-button link :type="row.status === 'abnormal' ? 'danger' : 'primary'" @click="open(row.id)">
            {{ row.status === 'abnormal' ? '处理' : '详情' }}
          </el-button>
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
</template>

<style scoped>
.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}
</style>
