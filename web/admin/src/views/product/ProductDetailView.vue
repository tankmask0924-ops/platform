<script setup lang="ts">
import { labelOf, money, percentToRate, ratePercent, StatusTag, toOptions } from '@platform/shared'
import { Plus } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { mappingApi, productApi, type ProductDetail, type ProductMapping, type Supplier, supplierApi } from '@/api/admin'
import { useLevels } from '@/composables/useLevels'
import {
  cardTypeLabels,
  chargeSpeedLabels,
  mappingStatusLabels,
  operatorLabels,
  productBusinessLineLabels,
  productStatusLabels,
} from '@/labels'
import ProductFormDialog from './ProductFormDialog.vue'

const route = useRoute()
const id = Number(route.params.id)
const { levels, ensure } = useLevels()

const product = ref<ProductDetail | null>(null)
const mappings = ref<ProductMapping[]>([])
const loading = ref(false)
const formDialog = ref<InstanceType<typeof ProductFormDialog>>()

async function load() {
  loading.value = true
  try {
    const [p, m] = await Promise.all([productApi.detail(id), mappingApi.list(id)])
    product.value = p
    mappings.value = m
  } finally {
    loading.value = false
  }
}

async function toggleStatus() {
  if (!product.value) {
    return
  }
  const next = product.value.status === 'on_shelf' ? 'off_shelf' : 'on_shelf'
  if (next === 'on_shelf' && !mappings.value.some((m) => m.status === 'active')) {
    try {
      await ElMessageBox.confirm('该商品还没有启用中的供应商映射，上架后商户下单会失败。仍要上架吗？', '上架', { type: 'warning' })
    } catch {
      return
    }
  }
  await productApi.setStatus(id, next)
  ElMessage.success(next === 'on_shelf' ? '已上架' : '已下架')
  await load()
}

// 5.5 价格与返佣保护提示（只提示，不拦截）
function margin(m: ProductMapping): number {
  if (!product.value) {
    return 0
  }
  return Number(product.value.sale_price) - Number(m.cost_price) - Number(product.value.rebate_amount)
}

const warnings = computed(() => {
  const p = product.value
  if (!p) {
    return []
  }
  const result: string[] = []
  const active = mappings.value.filter((m) => m.status === 'active')
  if (active.some((m) => Number(p.sale_price) < Number(m.cost_price))) {
    result.push('售价低于部分供应商的成本价')
  }
  if (active.some((m) => margin(m) < 0)) {
    result.push('按返佣金额全额计算，部分供应商的毛利为负')
  }
  if (p.level_rebates.some((r) => Number(r.rebate_rate) > 1)) {
    result.push('有等级的返佣比例超过 100%')
  }
  return result
})

// 等级比例覆盖
const overrideDialog = ref(false)
const overrideForm = reactive({ level_id: undefined as number | undefined, percent: 100 })

function openOverride(levelId?: number, rate?: string) {
  overrideForm.level_id = levelId
  overrideForm.percent = rate === undefined ? 100 : Math.round(Number(rate) * 10000) / 100
  overrideDialog.value = true
}

async function saveOverride() {
  if (!overrideForm.level_id) {
    ElMessage.warning('请选择等级')
    return
  }
  await productApi.setLevelRebate(id, overrideForm.level_id, percentToRate(overrideForm.percent))
  ElMessage.success('已保存')
  overrideDialog.value = false
  await load()
}

async function removeOverride(levelId: number, levelName: string | null) {
  try {
    await ElMessageBox.confirm(`删除后「${levelName ?? levelId}」回到该等级在本业务线的默认比例。确定删除吗？`, '删除单独比例', {
      type: 'warning',
    })
  } catch {
    return
  }
  await productApi.deleteLevelRebate(id, levelId)
  ElMessage.success('已删除')
  await load()
}

// 供应商映射
const suppliers = ref<Supplier[]>([])
const mappingDialog = ref(false)
const editingMapping = ref<ProductMapping | null>(null)
const mappingSaving = ref(false)
const mappingFormRef = ref<FormInstance>()
const mappingForm = reactive({
  supplier_id: undefined as number | undefined,
  supplier_product_code: '',
  cost_price: '',
  priority: 0,
  status: 'active',
  stock: undefined as number | undefined,
})
const mappingRules: FormRules = {
  supplier_id: [{ required: true, message: '请选择供应商', trigger: 'change' }],
  supplier_product_code: [{ required: true, message: '请输入供应商商品编码', trigger: 'blur' }],
  cost_price: [
    { required: true, message: '请输入成本价', trigger: 'blur' },
    { pattern: /^\d+(\.\d{1,2})?$/, message: '成本价必须是非负数，最多两位小数', trigger: 'blur' },
  ],
}

const availableSuppliers = computed(() =>
  suppliers.value.filter(
    (s) => s.business_line === product.value?.business_line && !mappings.value.some((m) => m.supplier_id === s.id),
  ),
)

async function openMapping(mapping?: ProductMapping) {
  editingMapping.value = mapping ?? null
  Object.assign(mappingForm, {
    supplier_id: mapping?.supplier_id,
    supplier_product_code: mapping?.supplier_product_code ?? '',
    cost_price: mapping?.cost_price ?? '',
    priority: mapping?.priority ?? (mappings.value.length ? Math.max(...mappings.value.map((m) => m.priority)) + 1 : 0),
    status: mapping?.status ?? 'active',
    stock: mapping?.stock ?? undefined,
  })
  mappingDialog.value = true
  if (!mapping && suppliers.value.length === 0) {
    suppliers.value = (await supplierApi.list({ page: 1, per_page: 100 })).data
  }
}

async function saveMapping() {
  const valid = await mappingFormRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  const stock = mappingForm.stock ?? null
  mappingSaving.value = true
  try {
    const m = editingMapping.value
    if (m === null) {
      await mappingApi.create({
        product_id: id,
        supplier_id: mappingForm.supplier_id as number,
        supplier_product_code: mappingForm.supplier_product_code.trim(),
        cost_price: mappingForm.cost_price,
        priority: mappingForm.priority,
        status: mappingForm.status,
        stock,
      })
    } else {
      // 成本价、优先级、状态、其它字段各有独立接口，只调改动了的
      if (mappingForm.supplier_product_code.trim() !== m.supplier_product_code || stock !== m.stock) {
        await mappingApi.update(m.id, { supplier_product_code: mappingForm.supplier_product_code.trim(), stock })
      }
      if (mappingForm.cost_price !== m.cost_price) {
        await mappingApi.setCostPrice(m.id, mappingForm.cost_price)
      }
      if (mappingForm.priority !== m.priority) {
        await mappingApi.setPriority(m.id, mappingForm.priority)
      }
      if (mappingForm.status !== m.status) {
        await mappingApi.setStatus(m.id, mappingForm.status)
      }
    }
    ElMessage.success('已保存')
    mappingDialog.value = false
  } finally {
    mappingSaving.value = false
    await load()
  }
}

onMounted(() => {
  load()
  ensure()
})
</script>

<template>
  <div v-loading="loading" class="page">
    <template v-if="product">
      <el-alert v-for="w in warnings" :key="w" type="warning" show-icon :closable="false" :title="w" />

      <el-card shadow="never">
        <template #header>
          <div class="card-header">
            <span>{{ product.name }}</span>
            <div>
              <el-button @click="formDialog?.open(product)">编辑</el-button>
              <el-button :type="product.status === 'on_shelf' ? 'danger' : 'success'" plain @click="toggleStatus">
                {{ product.status === 'on_shelf' ? '下架' : '上架' }}
              </el-button>
            </div>
          </div>
        </template>
        <el-descriptions :column="3" border>
          <el-descriptions-item label="ID">{{ product.id }}</el-descriptions-item>
          <el-descriptions-item label="业务线">{{ labelOf(productBusinessLineLabels, product.business_line) }}</el-descriptions-item>
          <el-descriptions-item label="状态"><StatusTag :map="productStatusLabels" :value="product.status" /></el-descriptions-item>
          <template v-if="product.business_line === 'recharge'">
            <el-descriptions-item label="运营商">{{ labelOf(operatorLabels, product.operator) }}</el-descriptions-item>
            <el-descriptions-item label="省份">{{ product.province ?? '全国' }}</el-descriptions-item>
            <el-descriptions-item label="充值速度">{{ product.charge_speed ? labelOf(chargeSpeedLabels, product.charge_speed) : '不限' }}</el-descriptions-item>
          </template>
          <el-descriptions-item v-else label="卡券类型" :span="3">{{ labelOf(cardTypeLabels, product.card_type) }}</el-descriptions-item>
          <el-descriptions-item label="面值">{{ money(product.face_value) }}</el-descriptions-item>
          <el-descriptions-item label="售价">{{ money(product.sale_price) }}</el-descriptions-item>
          <el-descriptions-item label="返佣金额">{{ money(product.rebate_amount) }}</el-descriptions-item>
          <el-descriptions-item label="适用地区">{{ product.applicable_region ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="创建时间">{{ product.created_at }}</el-descriptions-item>
          <el-descriptions-item label="更新时间">{{ product.updated_at }}</el-descriptions-item>
        </el-descriptions>
      </el-card>

      <el-card shadow="never">
        <template #header>
          <div class="card-header">
            <span>供应商映射（按优先级从小到大尝试）</span>
            <el-button type="primary" :icon="Plus" @click="openMapping()">添加映射</el-button>
          </div>
        </template>
        <el-table :data="mappings" border empty-text="还没有供应商映射，商品无法下单">
          <el-table-column prop="priority" label="优先级" width="80" />
          <el-table-column label="供应商" min-width="140">
            <template #default="{ row }">{{ row.supplier_name ?? `#${row.supplier_id}` }}</template>
          </el-table-column>
          <el-table-column prop="supplier_product_code" label="供应商商品编码" min-width="140" />
          <el-table-column label="成本价" width="100" align="right">
            <template #default="{ row }">
              <span :class="{ danger: Number(row.cost_price) > Number(product.sale_price) }">{{ money(row.cost_price) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="毛利（扣全额返佣）" width="150" align="right">
            <template #default="{ row }">
              <span :class="{ danger: margin(row as ProductMapping) < 0 }">{{ margin(row as ProductMapping).toFixed(2) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="库存" width="80">
            <template #default="{ row }">{{ row.stock ?? '不限' }}</template>
          </el-table-column>
          <el-table-column label="状态" width="80">
            <template #default="{ row }"><StatusTag :map="mappingStatusLabels" :value="row.status" /></template>
          </el-table-column>
          <el-table-column label="同步时间" width="170">
            <template #default="{ row }">{{ row.synced_at ?? '-' }}</template>
          </el-table-column>
          <el-table-column label="操作" width="80" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" @click="openMapping(row as ProductMapping)">编辑</el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-card>

      <el-card shadow="never">
        <template #header>
          <div class="card-header">
            <span>单独设置的等级返佣比例（未设置的等级用等级默认比例）</span>
            <el-button type="primary" :icon="Plus" @click="openOverride()">设置比例</el-button>
          </div>
        </template>
        <el-table :data="product.level_rebates" border empty-text="没有单独设置，全部按等级默认比例">
          <el-table-column label="等级" min-width="140">
            <template #default="{ row }">{{ row.level_name ?? `#${row.level_id}` }}</template>
          </el-table-column>
          <el-table-column label="比例" width="120">
            <template #default="{ row }">
              <span :class="{ danger: Number(row.rebate_rate) > 1 }">{{ ratePercent(row.rebate_rate) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="商户实得返佣" width="140">
            <template #default="{ row }">{{ money((Number(product.rebate_amount) * Number(row.rebate_rate)).toFixed(2)) }}</template>
          </el-table-column>
          <el-table-column label="操作" width="120">
            <template #default="{ row }">
              <el-button link type="primary" @click="openOverride(row.level_id, row.rebate_rate)">修改</el-button>
              <el-button link type="danger" @click="removeOverride(row.level_id, row.level_name)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-card>
    </template>
  </div>

  <ProductFormDialog ref="formDialog" @saved="load" />

  <el-dialog v-model="overrideDialog" title="单独设置等级返佣比例" width="440px">
    <el-form label-width="80px">
      <el-form-item label="等级">
        <el-select v-model="overrideForm.level_id" placeholder="选择等级">
          <el-option v-for="level in levels" :key="level.id" :value="level.id" :label="level.name" />
        </el-select>
      </el-form-item>
      <el-form-item label="比例（%）">
        <el-input-number v-model="overrideForm.percent" :min="0" :max="9999.99" :precision="2" />
        <div v-if="overrideForm.percent > 100" class="warn">超过 100%：商户拿到的返佣会高于商品返佣金额</div>
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="overrideDialog = false">取消</el-button>
      <el-button type="primary" @click="saveOverride">保存</el-button>
    </template>
  </el-dialog>

  <el-dialog v-model="mappingDialog" :title="editingMapping ? '编辑映射' : '添加映射'" width="520px" @closed="mappingFormRef?.clearValidate()">
    <el-form ref="mappingFormRef" :model="mappingForm" :rules="mappingRules" label-width="130px">
      <el-form-item label="供应商" prop="supplier_id">
        <el-input v-if="editingMapping" :model-value="editingMapping.supplier_name ?? `#${editingMapping.supplier_id}`" disabled />
        <el-select v-else v-model="mappingForm.supplier_id" placeholder="同业务线、尚未映射的供应商" style="width: 100%">
          <el-option v-for="s in availableSuppliers" :key="s.id" :value="s.id" :label="`${s.name}（${s.code}）`" />
        </el-select>
      </el-form-item>
      <el-form-item label="供应商商品编码" prop="supplier_product_code">
        <el-input v-model="mappingForm.supplier_product_code" maxlength="64" />
      </el-form-item>
      <el-form-item label="成本价" prop="cost_price">
        <el-input v-model="mappingForm.cost_price"><template #prepend>¥</template></el-input>
        <div v-if="editingMapping" class="tip">手动改价会留存价格历史</div>
      </el-form-item>
      <el-form-item label="优先级">
        <el-input-number v-model="mappingForm.priority" :min="0" :step="1" step-strictly />
        <div class="tip">数字越小越先尝试</div>
      </el-form-item>
      <el-form-item label="状态">
        <el-radio-group v-model="mappingForm.status">
          <el-radio-button v-for="o in toOptions(mappingStatusLabels)" :key="o.value" :value="o.value">{{ o.label }}</el-radio-button>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="库存">
        <el-input-number v-model="mappingForm.stock" :min="0" :step="1" step-strictly placeholder="不限" />
        <div class="tip">留空表示不限</div>
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="mappingDialog = false">取消</el-button>
      <el-button type="primary" :loading="mappingSaving" @click="saveMapping">保存</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.page {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-height: 200px;
}

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.danger {
  color: #f56c6c;
  font-weight: 600;
}

.warn {
  margin-top: 4px;
  color: #e6a23c;
  font-size: 12px;
}

.tip {
  width: 100%;
  color: #909399;
  font-size: 12px;
}
</style>
