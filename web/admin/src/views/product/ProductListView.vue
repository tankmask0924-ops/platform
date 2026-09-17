<script setup lang="ts">
import { labelOf, money, StatusTag, toOptions, usePagedList } from '@platform/shared'
import { Plus } from '@element-plus/icons-vue'
import { ElMessage } from 'element-plus'
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { type Product, productApi } from '@/api/admin'
import { cardTypeLabels, operatorLabels, productBusinessLineLabels, productStatusLabels } from '@/labels'
import ProductFormDialog from './ProductFormDialog.vue'

const router = useRouter()
const list = usePagedList<Product, { business_line: string; status: string }>(productApi.list, {
  business_line: '',
  status: '',
})
const { rows, total, page, perPage, loading, filters } = list
const formDialog = ref<InstanceType<typeof ProductFormDialog>>()

function spec(row: Product): string {
  if (row.business_line === 'recharge') {
    return [labelOf(operatorLabels, row.operator), row.province ?? '全国'].join(' / ')
  }
  return labelOf(cardTypeLabels, row.card_type)
}

async function toggle(row: Product) {
  const next = row.status === 'on_shelf' ? 'off_shelf' : 'on_shelf'
  await productApi.setStatus(row.id, next)
  ElMessage.success(next === 'on_shelf' ? '已上架' : '已下架')
  await list.load()
}

function onSaved(id: number, created: boolean) {
  if (created) {
    router.push({ name: 'product-detail', params: { id } })
  } else {
    list.load()
  }
}

onMounted(list.load)
</script>

<template>
  <el-card shadow="never">
    <div class="toolbar">
      <el-button type="primary" :icon="Plus" @click="formDialog?.open()">新增商品</el-button>
      <div class="filters">
        <el-select v-model="filters.business_line" clearable placeholder="全部业务" style="width: 120px" @change="list.search">
          <el-option v-for="o in toOptions(productBusinessLineLabels)" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
        <el-select v-model="filters.status" clearable placeholder="全部状态" style="width: 120px" @change="list.search">
          <el-option v-for="o in toOptions(productStatusLabels)" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </div>
    </div>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column label="业务" width="70">
        <template #default="{ row }">{{ labelOf(productBusinessLineLabels, row.business_line) }}</template>
      </el-table-column>
      <el-table-column prop="name" label="名称" min-width="180" />
      <el-table-column label="规格" min-width="130">
        <template #default="{ row }">{{ spec(row as Product) }}</template>
      </el-table-column>
      <el-table-column label="面值" width="100" align="right">
        <template #default="{ row }">{{ money(row.face_value) }}</template>
      </el-table-column>
      <el-table-column label="售价" width="100" align="right">
        <template #default="{ row }">{{ money(row.sale_price) }}</template>
      </el-table-column>
      <el-table-column label="返佣金额" width="100" align="right">
        <template #default="{ row }">{{ money(row.rebate_amount) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="80">
        <template #default="{ row }"><StatusTag :map="productStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column label="操作" width="170" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="router.push({ name: 'product-detail', params: { id: row.id } })">详情</el-button>
          <el-button link type="primary" @click="formDialog?.open(row as Product)">编辑</el-button>
          <el-button link :type="row.status === 'on_shelf' ? 'danger' : 'success'" @click="toggle(row as Product)">
            {{ row.status === 'on_shelf' ? '下架' : '上架' }}
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

  <ProductFormDialog ref="formDialog" @saved="onSaved" />
</template>

<style scoped>
.toolbar {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
}

.filters {
  display: flex;
  gap: 8px;
}

.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}
</style>
