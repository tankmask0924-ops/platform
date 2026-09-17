<script setup lang="ts">
import { businessLineLabels, percentToRate, ratePercent } from '@platform/shared'
import { Plus } from '@element-plus/icons-vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { onMounted, reactive, ref } from 'vue'
import { type BusinessLine, levelApi, type MerchantLevel, type MerchantLevelDetail } from '@/api/admin'
import { useLevels } from '@/composables/useLevels'

const { levels, reload } = useLevels()
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    await reload()
  } finally {
    loading.value = false
  }
}

// 新增 / 编辑
const dialogVisible = ref(false)
const editingId = ref<number | null>(null)
const saving = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({ name: '', remark: '' })
const rules: FormRules = {
  name: [{ required: true, message: '请输入等级名称', trigger: 'blur' }],
}

function openDialog(level?: MerchantLevel) {
  editingId.value = level?.id ?? null
  form.name = level?.name ?? ''
  form.remark = level?.remark ?? ''
  dialogVisible.value = true
}

async function save() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  saving.value = true
  try {
    if (editingId.value === null) {
      await levelApi.create({ ...form })
    } else {
      await levelApi.update(editingId.value, { ...form })
    }
    ElMessage.success('保存成功')
    dialogVisible.value = false
    await load()
  } finally {
    saving.value = false
  }
}

// 返佣比例
const businessLines = Object.keys(businessLineLabels) as BusinessLine[]
const rateRows = businessLines.map((line) => ({ line }))
const rateDrawer = ref(false)
const rateLoading = ref(false)
const current = ref<MerchantLevelDetail | null>(null)
/** 百分比输入值，undefined 表示未设置 */
const percents = reactive<Record<string, number | undefined>>({})

async function openRates(level: MerchantLevel) {
  rateDrawer.value = true
  rateLoading.value = true
  current.value = null
  try {
    current.value = await levelApi.detail(level.id)
    for (const line of businessLines) {
      const rate = current.value.rates[line]
      percents[line] = rate === null ? undefined : Math.round(Number(rate) * 10000) / 100
    }
  } finally {
    rateLoading.value = false
  }
}

async function saveRate(line: BusinessLine) {
  const percent = percents[line]
  if (!current.value || percent === undefined || percent === null) {
    ElMessage.warning('请输入比例')
    return
  }
  const rate = percentToRate(percent)
  await levelApi.setRate(current.value.id, line, rate)
  current.value.rates[line] = rate
  ElMessage.success(`${businessLineLabels[line]?.label}返佣比例已保存`)
}

onMounted(load)
</script>

<template>
  <el-card shadow="never">
    <el-alert
      type="info"
      show-icon
      :closable="false"
      title="商户实际返佣 = 商品返佣金额 × 商户等级在该业务线的比例（商品可单独覆盖某等级的比例）。新订单按下单时的比例计算。"
      class="block"
    />
    <div class="toolbar">
      <el-button type="primary" :icon="Plus" @click="openDialog()">新增等级</el-button>
    </div>

    <el-table v-loading="loading" :data="levels" border>
      <el-table-column prop="id" label="ID" width="80" />
      <el-table-column prop="name" label="名称" min-width="140" />
      <el-table-column label="备注" min-width="200">
        <template #default="{ row }">{{ row.remark ?? '-' }}</template>
      </el-table-column>
      <el-table-column prop="updated_at" label="更新时间" width="170" />
      <el-table-column label="操作" width="160" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openRates(row as MerchantLevel)">返佣比例</el-button>
          <el-button link type="primary" @click="openDialog(row as MerchantLevel)">编辑</el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <el-dialog v-model="dialogVisible" :title="editingId === null ? '新增等级' : '编辑等级'" width="460px" @closed="formRef?.clearValidate()">
    <el-form ref="formRef" :model="form" :rules="rules" label-width="60px">
      <el-form-item label="名称" prop="name">
        <el-input v-model="form.name" maxlength="32" />
      </el-form-item>
      <el-form-item label="备注" prop="remark">
        <el-input v-model="form.remark" type="textarea" maxlength="255" :rows="3" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="save">保存</el-button>
    </template>
  </el-dialog>

  <el-drawer v-model="rateDrawer" :title="current ? `「${current.name}」返佣比例` : '返佣比例'" size="520px">
    <div v-loading="rateLoading">
      <el-table v-if="current" :data="rateRows" border>
        <el-table-column label="业务线" width="90">
          <template #default="{ row }">{{ businessLineLabels[row.line]?.label }}</template>
        </el-table-column>
        <el-table-column label="当前" width="90">
          <template #default="{ row }">{{ current.rates[row.line as BusinessLine] === null ? '未设置' : ratePercent(current.rates[row.line as BusinessLine]) }}</template>
        </el-table-column>
        <el-table-column label="设置为（%）">
          <template #default="{ row }">
            <div class="rate-cell">
              <el-input-number v-model="percents[row.line]" :min="0" :max="9999.99" :precision="2" :step="1" controls-position="right" />
              <el-button size="small" type="primary" @click="saveRate(row.line as BusinessLine)">保存</el-button>
            </div>
            <div v-if="(percents[row.line] ?? 0) > 100" class="warn">超过 100%：商户拿到的返佣会高于商品返佣金额</div>
          </template>
        </el-table-column>
      </el-table>
    </div>
  </el-drawer>
</template>

<style scoped>
.block,
.toolbar {
  margin-bottom: 16px;
}

.rate-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.warn {
  margin-top: 4px;
  color: #e6a23c;
  font-size: 12px;
}
</style>
