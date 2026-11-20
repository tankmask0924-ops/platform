<script setup lang="ts">
import { money, StatusTag, usePagedList } from '@platform/shared'
import { ref } from 'vue'
import { type SupplierGoods, supplierGoodsApi } from '@/api/admin'
import { mappingStatusLabels, supplierGoodsTypeLabels } from '@/labels'

/**
 * 从供应商商品库里选一个供应商商品。每次 open() 选一个，选中即关闭；
 * 已经用过的供应商（excludeSupplierIds）那一行不能选，因为一个本地商品在每个供应商只能映射一个商品。
 */
const emit = defineEmits<{ select: [goods: SupplierGoods] }>()

const visible = ref(false)
const title = ref('选择供应商商品')
const excluded = ref<number[]>([])

const list = usePagedList<SupplierGoods, { business_line: string; supplier_id: number | ''; goods_type: string; keyword: string }>(
  supplierGoodsApi.list,
  { business_line: '', supplier_id: '', goods_type: '', keyword: '' },
  10,
)
const { rows, total, page, perPage, loading, filters } = list

/** goodsType：只列这种类型（类型未知的也会列出来） */
function open(options: { businessLine: string; goodsType?: string; supplierId?: number; excludeSupplierIds?: number[]; title?: string }) {
  filters.business_line = options.businessLine
  filters.goods_type = options.goodsType ?? ''
  filters.supplier_id = options.supplierId ?? ''
  filters.keyword = ''
  excluded.value = options.excludeSupplierIds ?? []
  title.value = options.title ?? '选择供应商商品'
  visible.value = true
  list.search()
}

defineExpose({ open })

function pick(row: SupplierGoods) {
  emit('select', row)
  visible.value = false
}
</script>

<template>
  <el-dialog v-model="visible" :title="title" width="960px" append-to-body>
    <el-form inline @submit.prevent="list.search">
      <el-form-item>
        <el-input v-model="filters.keyword" clearable placeholder="名称关键字或商品编码" style="width: 260px" @clear="list.search" />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" native-type="submit">搜索</el-button>
      </el-form-item>
    </el-form>

    <el-table v-loading="loading" :data="rows" border size="small" empty-text="没有供应商商品。先到供应商详情点「同步商品」，同步完再来选">
      <el-table-column label="供应商" min-width="120">
        <template #default="{ row }">{{ row.supplier_name ?? `#${row.supplier_id}` }}</template>
      </el-table-column>
      <el-table-column prop="code" label="编码" min-width="110" />
      <el-table-column label="名称" min-width="180">
        <template #default="{ row }">{{ row.name ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="类型" width="70">
        <template #default="{ row }">
          <StatusTag v-if="row.goods_type" :map="supplierGoodsTypeLabels" :value="row.goods_type" />
          <span v-else class="muted">未知</span>
        </template>
      </el-table-column>
      <el-table-column label="面值" width="90" align="right">
        <template #default="{ row }">{{ money(row.face_value) }}</template>
      </el-table-column>
      <el-table-column label="成本价" width="90" align="right">
        <template #default="{ row }">{{ money(row.cost_price) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="70">
        <template #default="{ row }"><StatusTag :map="mappingStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column label="库存" width="70">
        <template #default="{ row }">{{ row.stock ?? '不限' }}</template>
      </el-table-column>
      <el-table-column label="已用于" min-width="140">
        <template #default="{ row }">
          <span v-if="row.used_by.length === 0" class="muted">-</span>
          <span v-else>{{ (row as SupplierGoods).used_by.map((p) => p.name ?? `#${p.id}`).join('、') }}</span>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="90" fixed="right">
        <template #default="{ row }">
          <el-tooltip v-if="excluded.includes(row.supplier_id)" content="这个供应商已经选过商品了" placement="left">
            <el-button link disabled>选择</el-button>
          </el-tooltip>
          <el-button v-else link type="primary" @click="pick(row as SupplierGoods)">选择</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      v-model:current-page="page"
      v-model:page-size="perPage"
      class="pagination"
      layout="total, prev, pager, next"
      :total="total"
      @current-change="list.load"
    />
  </el-dialog>
</template>

<style scoped>
.pagination {
  margin-top: 12px;
  justify-content: flex-end;
}

.muted {
  color: #909399;
}
</style>
