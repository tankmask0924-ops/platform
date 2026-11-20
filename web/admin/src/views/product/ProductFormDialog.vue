<script setup lang="ts">
import { money, StatusTag, toOptions } from '@platform/shared'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { reactive, ref, watch } from 'vue'
import { type Product, productApi, type ProductForm, type SupplierGoods } from '@/api/admin'
import { cardTypeLabels, chargeSpeedLabels, mappingStatusLabels, operatorLabels, productBusinessLineLabels } from '@/labels'
import SupplierGoodsPicker from './SupplierGoodsPicker.vue'

const emit = defineEmits<{ saved: [id: number, created: boolean] }>()

const visible = ref(false)
const editingId = ref<number | null>(null)
const saving = ref(false)
const formRef = ref<FormInstance>()

const empty = () => ({
  business_line: 'recharge' as Product['business_line'],
  name: '',
  operator: '',
  province: '',
  charge_speed: '',
  card_type: '',
  face_value: '',
  sale_price: '',
  rebate_amount: '0',
  applicable_region: '',
})
const form = reactive(empty())

// 新建时要选的供应商商品，顺序即优先级（第一个最先尝试）
const goods = ref<SupplierGoods[]>([])
const picker = ref<InstanceType<typeof SupplierGoodsPicker>>()

function openPicker() {
  picker.value?.open({
    businessLine: form.business_line,
    excludeSupplierIds: goods.value.map((g) => g.supplier_id),
    title: `选择供应商商品（${productBusinessLineLabels[form.business_line]?.label ?? form.business_line}）`,
  })
}

function onPick(g: SupplierGoods) {
  goods.value.push(g)
  // 第一个选中的供应商商品带出名称和面值，已经填了的不覆盖
  if (goods.value.length === 1) {
    if (!form.name.trim() && g.name) {
      form.name = g.name
    }
    if (!form.face_value && g.face_value) {
      form.face_value = g.face_value
    }
  }
}

function moveGoods(index: number, delta: number) {
  const target = index + delta
  if (target < 0 || target >= goods.value.length) {
    return
  }
  const list = goods.value
  ;[list[index], list[target]] = [list[target], list[index]]
}

// 换业务线后已选的供应商商品都对不上了
watch(
  () => form.business_line,
  () => {
    if (editingId.value === null) {
      goods.value = []
    }
  },
)

const decimal = (label: string) => [
  { required: true, message: `请输入${label}`, trigger: 'blur' },
  { pattern: /^\d+(\.\d{1,2})?$/, message: `${label}必须是非负数，最多两位小数`, trigger: 'blur' },
]
const rules: FormRules = {
  name: [{ required: true, message: '请输入商品名称', trigger: 'blur' }],
  operator: [{ required: true, message: '请选择运营商', trigger: 'change' }],
  card_type: [{ required: true, message: '请选择卡券类型', trigger: 'change' }],
  face_value: decimal('面值'),
  sale_price: decimal('售价'),
  rebate_amount: decimal('返佣金额'),
}

function open(product?: Product) {
  editingId.value = product?.id ?? null
  Object.assign(form, empty())
  goods.value = []
  if (product) {
    Object.assign(form, {
      business_line: product.business_line,
      name: product.name,
      operator: product.operator ?? '',
      province: product.province ?? '',
      charge_speed: product.charge_speed ?? '',
      card_type: product.card_type ?? '',
      face_value: product.face_value,
      sale_price: product.sale_price,
      rebate_amount: product.rebate_amount,
      applicable_region: product.applicable_region ?? '',
    })
  }
  visible.value = true
}

defineExpose({ open })

async function save() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  if (editingId.value === null && goods.value.length === 0) {
    ElMessage.warning('请至少选择一个供应商商品')
    return
  }
  const data: ProductForm = {
    name: form.name.trim(),
    province: form.province.trim() || null,
    face_value: form.face_value,
    sale_price: form.sale_price,
    rebate_amount: form.rebate_amount,
    applicable_region: form.applicable_region.trim() || null,
  }
  // 后端对不属于该业务线的字段直接报错，只发对应业务线的字段
  if (form.business_line === 'recharge') {
    data.operator = form.operator
    data.charge_speed = form.charge_speed || null
  } else {
    data.card_type = form.card_type
  }
  saving.value = true
  try {
    let id: number
    if (editingId.value === null) {
      data.business_line = form.business_line
      data.supplier_goods_ids = goods.value.map((g) => g.id)
      id = (await productApi.create(data)).id
      ElMessage.success('已创建，新商品默认下架，确认售价和返佣后再上架')
    } else {
      id = (await productApi.update(editingId.value, data)).id
      ElMessage.success('已保存')
    }
    visible.value = false
    emit('saved', id, editingId.value === null)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <el-dialog v-model="visible" :title="editingId === null ? '新增商品' : '编辑商品'" :width="editingId === null ? '760px' : '560px'" @closed="formRef?.clearValidate()">
    <el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
      <el-form-item label="业务线">
        <el-radio-group v-model="form.business_line" :disabled="editingId !== null">
          <el-radio-button v-for="o in toOptions(productBusinessLineLabels)" :key="o.value" :value="o.value">{{ o.label }}</el-radio-button>
        </el-radio-group>
      </el-form-item>
      <el-form-item v-if="editingId === null" label="供应商商品" required>
        <div class="goods">
          <el-table v-if="goods.length" :data="goods" border size="small">
            <el-table-column label="优先级" width="70">
              <template #default="{ $index }">{{ $index }}</template>
            </el-table-column>
            <el-table-column label="供应商" min-width="110">
              <template #default="{ row }">{{ row.supplier_name ?? `#${row.supplier_id}` }}</template>
            </el-table-column>
            <el-table-column label="商品" min-width="160">
              <template #default="{ row }">{{ row.name ?? '-' }}<span class="muted">（{{ row.code }}）</span></template>
            </el-table-column>
            <el-table-column label="成本价" width="85" align="right">
              <template #default="{ row }">{{ money(row.cost_price) }}</template>
            </el-table-column>
            <el-table-column label="状态" width="65">
              <template #default="{ row }"><StatusTag :map="mappingStatusLabels" :value="row.status" /></template>
            </el-table-column>
            <el-table-column label="操作" width="120">
              <template #default="{ $index }">
                <el-button link :disabled="$index === 0" @click="moveGoods($index, -1)">上移</el-button>
                <el-button link :disabled="$index === goods.length - 1" @click="moveGoods($index, 1)">下移</el-button>
                <el-button link type="danger" @click="goods.splice($index, 1)">移除</el-button>
              </template>
            </el-table-column>
          </el-table>
          <el-button class="add-goods" @click="openPicker">{{ goods.length ? '再加一个供应商' : '选择供应商商品' }}</el-button>
          <div class="tip">每个供应商选一个，按上面的顺序尝试；编码、成本价、状态、库存从供应商商品带过来，之后自动同步</div>
        </div>
      </el-form-item>
      <el-form-item label="名称" prop="name">
        <el-input v-model="form.name" maxlength="128" />
      </el-form-item>
      <template v-if="form.business_line === 'recharge'">
        <el-form-item label="运营商" prop="operator">
          <el-select v-model="form.operator" placeholder="请选择">
            <el-option v-for="o in toOptions(operatorLabels)" :key="o.value" :value="o.value" :label="o.label" />
          </el-select>
        </el-form-item>
        <el-form-item label="充值速度">
          <el-select v-model="form.charge_speed" clearable placeholder="不限">
            <el-option v-for="o in toOptions(chargeSpeedLabels)" :key="o.value" :value="o.value" :label="o.label" />
          </el-select>
        </el-form-item>
        <el-form-item label="省份">
          <el-input v-model="form.province" maxlength="32" placeholder="留空表示全国" />
        </el-form-item>
      </template>
      <el-form-item v-else label="卡券类型" prop="card_type">
        <el-select v-model="form.card_type" placeholder="请选择">
          <el-option v-for="o in toOptions(cardTypeLabels)" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>
      <el-form-item label="面值" prop="face_value">
        <el-input v-model="form.face_value"><template #prepend>¥</template></el-input>
      </el-form-item>
      <el-form-item label="售价" prop="sale_price">
        <el-input v-model="form.sale_price"><template #prepend>¥</template></el-input>
      </el-form-item>
      <el-form-item label="返佣金额" prop="rebate_amount">
        <el-input v-model="form.rebate_amount"><template #prepend>¥</template></el-input>
        <div class="tip">商户实际返佣 = 返佣金额 × 商户等级比例</div>
      </el-form-item>
      <el-form-item label="适用地区">
        <el-input v-model="form.applicable_region" maxlength="64" placeholder="选填" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="visible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="save">保存</el-button>
    </template>
  </el-dialog>
  <SupplierGoodsPicker ref="picker" @select="onPick" />
</template>

<style scoped>
.tip {
  color: #909399;
  font-size: 12px;
}

.goods {
  width: 100%;
}

.add-goods {
  margin-top: 8px;
}

.muted {
  color: #909399;
}
</style>
