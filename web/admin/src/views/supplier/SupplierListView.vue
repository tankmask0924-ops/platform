<script setup lang="ts">
import { businessLineLabels, labelOf, money, StatusTag, toOptions, usePagedList } from '@platform/shared'
import { Plus } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { type Supplier, supplierApi, type SupplierDetail, type SupplierForm } from '@/api/admin'
import { driverBusinessLines, driverConfigFields, driverLabels, supplierStatusLabels } from '@/labels'

const list = usePagedList<Supplier, object>(supplierApi.list, {})
const { rows, total, page, perPage, loading } = list

function balanceLow(row: Supplier): boolean {
  return row.balance !== null && row.balance_warning_threshold !== null && Number(row.balance) < Number(row.balance_warning_threshold)
}

async function toggle(row: Supplier) {
  const next = row.status === 'active' ? 'disabled' : 'active'
  if (next === 'disabled') {
    try {
      await ElMessageBox.confirm(`停用后下单路由不再使用「${row.name}」。确定停用吗？`, '停用供应商', { type: 'warning' })
    } catch {
      return
    }
  }
  await supplierApi.setStatus(row.id, next)
  ElMessage.success('已更新')
  await list.load()
}

// 新增 / 编辑
const dialogVisible = ref(false)
const editing = ref<SupplierDetail | null>(null)
const saving = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({
  name: '',
  code: '',
  business_line: 'recharge',
  driver: 'kasushou',
  balance_warning_threshold: '',
  contact: '',
  settlement_info: '',
  remark: '',
})
/** 编辑时是否重新填写接口配置（配置整体替换，敏感项回显是打码的，不能原样提交） */
const editConfig = ref(true)
const config = reactive<Record<string, string>>({})
const configFields = computed(() => driverConfigFields[form.driver] ?? [])
/** 只列出能用于当前业务线的驱动（后端同样会校验） */
const driverOptions = computed(() =>
  toOptions(driverLabels).filter((o) => driverBusinessLines[o.value]?.includes(form.business_line)),
)
watch(
  () => form.business_line,
  () => {
    if (!driverBusinessLines[form.driver]?.includes(form.business_line)) {
      form.driver = driverOptions.value[0]?.value ?? ''
    }
  },
)

const rules: FormRules = {
  name: [{ required: true, message: '请输入名称', trigger: 'blur' }],
  code: [
    { required: true, message: '请输入编码', trigger: 'blur' },
    { pattern: /^[a-z0-9_]+$/, message: '只能用小写字母、数字和下划线（回调地址 /notify/{编码} 会用到）', trigger: 'blur' },
  ],
  balance_warning_threshold: [{ pattern: /^\d+(\.\d{1,2})?$/, message: '必须是非负数，最多两位小数', trigger: 'blur' }],
}

async function openDialog(row?: Supplier) {
  editing.value = null
  Object.assign(form, {
    name: '',
    code: '',
    business_line: 'recharge',
    driver: 'kasushou',
    balance_warning_threshold: '',
    contact: '',
    settlement_info: '',
    remark: '',
  })
  for (const key of Object.keys(config)) {
    delete config[key]
  }
  editConfig.value = !row
  if (row) {
    const detail = await supplierApi.detail(row.id)
    editing.value = detail
    // 配置解不开时没有"保留原配置"这个选项，只能重填
    editConfig.value = detail.config_unreadable
    Object.assign(form, {
      name: detail.name,
      code: detail.code,
      business_line: detail.business_line,
      driver: detail.driver,
      balance_warning_threshold: detail.balance_warning_threshold ?? '',
      contact: detail.contact ?? '',
      settlement_info: detail.settlement_info ?? '',
      remark: detail.remark ?? '',
    })
  }
  dialogVisible.value = true
}

async function save() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  const data: SupplierForm = {
    name: form.name.trim(),
    business_line: form.business_line,
    driver: form.driver,
    balance_warning_threshold: form.balance_warning_threshold || null,
    contact: form.contact,
    settlement_info: form.settlement_info,
    remark: form.remark,
  }
  if (editConfig.value) {
    const missing = configFields.value.find((f) => !config[f.key]?.trim())
    if (missing) {
      ElMessage.warning(`请填写${missing.label}`)
      return
    }
    data.config = Object.fromEntries(configFields.value.map((f) => [f.key, (config[f.key] ?? '').trim()]))
  }
  saving.value = true
  try {
    if (editing.value) {
      await supplierApi.update(editing.value.id, data)
    } else {
      await supplierApi.create({ ...data, code: form.code.trim() })
    }
    ElMessage.success('已保存')
    dialogVisible.value = false
    await list.load()
  } finally {
    saving.value = false
  }
}

onMounted(list.load)
</script>

<template>
  <el-card shadow="never">
    <div class="toolbar">
      <el-button type="primary" :icon="Plus" @click="openDialog()">新增供应商</el-button>
    </div>

    <el-table v-loading="loading" :data="rows" border>
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column label="名称" min-width="140">
        <template #default="{ row }">
          <router-link :to="{ name: 'supplier-detail', params: { id: row.id } }">{{ row.name }}</router-link>
        </template>
      </el-table-column>
      <el-table-column prop="code" label="编码" width="130" />
      <el-table-column label="业务线" width="80">
        <template #default="{ row }">{{ labelOf(businessLineLabels, row.business_line) }}</template>
      </el-table-column>
      <el-table-column label="驱动" width="110">
        <template #default="{ row }">{{ labelOf(driverLabels, row.driver) }}</template>
      </el-table-column>
      <el-table-column label="余额" width="130" align="right">
        <template #default="{ row }">
          <span :class="{ danger: balanceLow(row as Supplier) }">{{ money(row.balance) }}</span>
        </template>
      </el-table-column>
      <el-table-column label="预警线" width="110" align="right">
        <template #default="{ row }">{{ money(row.balance_warning_threshold) }}</template>
      </el-table-column>
      <el-table-column label="余额同步时间" width="170">
        <template #default="{ row }">{{ row.balance_synced_at ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="状态" width="80">
        <template #default="{ row }"><StatusTag :map="supplierStatusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column label="操作" width="120" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openDialog(row as Supplier)">编辑</el-button>
          <el-button link :type="row.status === 'active' ? 'danger' : 'success'" @click="toggle(row as Supplier)">
            {{ row.status === 'active' ? '停用' : '启用' }}
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

  <el-dialog v-model="dialogVisible" :title="editing ? '编辑供应商' : '新增供应商'" width="600px" @closed="formRef?.clearValidate()">
    <el-form ref="formRef" :model="form" :rules="rules" label-width="130px">
      <el-form-item label="名称" prop="name">
        <el-input v-model="form.name" maxlength="64" />
      </el-form-item>
      <el-form-item label="编码" prop="code">
        <el-input v-model="form.code" maxlength="32" :disabled="editing !== null" placeholder="创建后不可修改" />
      </el-form-item>
      <el-form-item label="业务线">
        <el-select v-model="form.business_line">
          <el-option v-for="o in toOptions(businessLineLabels)" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>
      <el-form-item label="驱动">
        <el-select v-model="form.driver">
          <el-option v-for="o in driverOptions" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>

      <el-divider content-position="left">接口配置（加密存储）</el-divider>
      <template v-if="editing">
        <el-alert
          v-if="editing.config_unreadable"
          type="error"
          show-icon
          :closable="false"
          class="block"
          title="当前配置无法解密，必须整体重新填写"
          description="这条供应商的接口配置是用别的加密密钥存的（或数据已损坏）。在下面把配置重新填一遍保存即可恢复。"
        />
        <el-form-item v-else label="当前配置">
          <div class="config">
            <div v-for="(value, key) in editing.config ?? {}" :key="key">
              <span class="muted">{{ key }}：</span>{{ value }}
            </div>
          </div>
        </el-form-item>
        <el-form-item v-if="!editing.config_unreadable" label="重新填写配置">
          <el-switch v-model="editConfig" />
          <span class="muted gap-left">配置整体替换，密钥需要重新输入</span>
        </el-form-item>
      </template>
      <template v-if="editConfig">
        <el-form-item v-for="field in configFields" :key="field.key" :label="field.label" required>
          <el-input v-model="config[field.key]" :type="field.secret ? 'password' : 'text'" :show-password="field.secret" />
        </el-form-item>
      </template>

      <el-divider content-position="left">其它</el-divider>
      <el-form-item label="余额预警线" prop="balance_warning_threshold">
        <el-input v-model="form.balance_warning_threshold" placeholder="留空不预警"><template #prepend>¥</template></el-input>
      </el-form-item>
      <el-form-item label="联系人">
        <el-input v-model="form.contact" maxlength="255" />
      </el-form-item>
      <el-form-item label="结算信息">
        <el-input v-model="form.settlement_info" type="textarea" :rows="2" />
      </el-form-item>
      <el-form-item label="备注">
        <el-input v-model="form.remark" type="textarea" :rows="2" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="save">保存</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.toolbar {
  margin-bottom: 16px;
}

.pagination {
  justify-content: flex-end;
  margin-top: 16px;
}

.danger {
  color: #f56c6c;
  font-weight: 600;
}

.muted {
  color: #909399;
}

.gap-left {
  margin-left: 8px;
  font-size: 12px;
}

.block {
  margin-bottom: 12px;
}

.config {
  line-height: 1.8;
  word-break: break-all;
}
</style>
