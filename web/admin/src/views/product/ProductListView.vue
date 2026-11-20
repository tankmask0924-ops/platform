<script setup lang="ts">
import { labelOf, money, StatusTag, toOptions, usePagedList } from '@platform/shared'
import { Plus } from '@element-plus/icons-vue'
import { ElMessage } from 'element-plus'
import { onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { type CardBrand, cardBrandApi, type Product, productApi } from '@/api/admin'
import { cardTypeLabels, operatorLabels, productStatusLabels } from '@/labels'
import ProductFormDialog from './ProductFormDialog.vue'

/** 话费商品、卡券商品两个菜单共用这个页面，由路由传入业务线 */
const props = defineProps<{ businessLine: 'recharge' | 'card' }>()

const router = useRouter()
const route = useRoute()
const list = usePagedList<Product, { business_line: string; status: string; brand_id: number | '' }>(productApi.list, {
  business_line: props.businessLine,
  status: '',
  // 从「卡券品牌」页点商品数跳过来时带着品牌
  brand_id: props.businessLine === 'card' && route.query.brand_id ? Number(route.query.brand_id) : '',
})
const { rows, total, page, perPage, loading, filters } = list
const formDialog = ref<InstanceType<typeof ProductFormDialog>>()
const brands = ref<CardBrand[]>([])

async function loadBrands() {
  if (props.businessLine === 'card' && brands.value.length === 0) {
    brands.value = await cardBrandApi.list()
  }
}

// 从「话费商品」切到「卡券商品」时组件会复用，换业务线要重新查
watch(
  () => props.businessLine,
  (line) => {
    filters.business_line = line
    filters.brand_id = ''
    loadBrands()
    list.search()
  },
)

function spec(row: Product): string {
  if (row.business_line === 'recharge') {
    return [labelOf(operatorLabels, row.operator), row.province ?? '全国'].join(' / ')
  }
  return [row.brand_name ?? '未设置品牌', labelOf(cardTypeLabels, row.card_type)].join(' / ')
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

onMounted(() => {
  loadBrands()
  list.load()
})
</script>

<template>
  <el-card shadow="never">
    <div class="toolbar">
      <el-button type="primary" :icon="Plus" @click="formDialog?.open(undefined, props.businessLine)">
        {{ props.businessLine === 'card' ? '新增卡券商品' : '新增话费商品' }}
      </el-button>
      <div class="filters">
        <el-select v-if="props.businessLine === 'card'" v-model="filters.brand_id" clearable placeholder="全部品牌" style="width: 150px" @change="list.search">
          <el-option v-for="b in brands" :key="b.id" :value="b.id" :label="b.status === 'active' ? b.name : `${b.name}（已停用）`" />
        </el-select>
        <el-select v-model="filters.status" clearable placeholder="全部状态" style="width: 120px" @change="list.search">
          <el-option v-for="o in toOptions(productStatusLabels)" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </div>
    </div>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column label="图片" width="70">
        <template #default="{ row }">
          <el-image v-if="row.image_url" :src="row.image_url" fit="cover" class="thumb" />
          <span v-else class="muted">-</span>
        </template>
      </el-table-column>
      <el-table-column prop="name" label="名称" min-width="180" />
      <el-table-column :label="props.businessLine === 'card' ? '品牌 / 类型' : '运营商 / 地区'" min-width="150">
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
.thumb {
  width: 40px;
  height: 40px;
  border-radius: 4px;
}

.muted {
  color: #909399;
}

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
