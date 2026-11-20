<script setup lang="ts">
import { StatusTag } from '@platform/shared'
import { Plus } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { computed, onMounted, reactive, ref } from 'vue'
import { type CardBrand, cardBrandApi } from '@/api/admin'
import { usePermissionStore } from '@/stores/permission'

const permission = usePermissionStore()
const canManage = computed(() => permission.can('product.manage'))

const statusLabels = {
  active: { label: '启用', type: 'success' as const },
  disabled: { label: '停用', type: 'info' as const },
}

const brands = ref<CardBrand[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    brands.value = await cardBrandApi.list()
  } finally {
    loading.value = false
  }
}

const dialog = ref(false)
const editing = ref<CardBrand | null>(null)
const saving = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({ name: '', sort: 0 })
const rules: FormRules = { name: [{ required: true, message: '请输入品牌名称', trigger: 'blur' }] }

function open(brand?: CardBrand) {
  editing.value = brand ?? null
  Object.assign(form, { name: brand?.name ?? '', sort: brand?.sort ?? 0 })
  dialog.value = true
}

async function save() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  saving.value = true
  try {
    const data = { name: form.name.trim(), sort: form.sort }
    if (editing.value) {
      await cardBrandApi.update(editing.value.id, data)
    } else {
      await cardBrandApi.create(data)
    }
    ElMessage.success('已保存')
    dialog.value = false
    await load()
  } finally {
    saving.value = false
  }
}

async function toggle(brand: CardBrand) {
  const next = brand.status === 'active' ? 'disabled' : 'active'
  await cardBrandApi.update(brand.id, { status: next })
  ElMessage.success(next === 'active' ? '已启用' : '已停用，新建卡券商品时选不到了，已有商品不受影响')
  await load()
}

async function remove(brand: CardBrand) {
  try {
    await ElMessageBox.confirm(`确定删除品牌「${brand.name}」吗？`, '删除品牌', { type: 'warning' })
  } catch {
    return
  }
  await cardBrandApi.remove(brand.id)
  ElMessage.success('已删除')
  await load()
}

onMounted(load)
</script>

<template>
  <el-card shadow="never">
    <div class="toolbar">
      <span class="muted">卡券商品按品牌归类，商户在商品列表里按品牌查看。停用的品牌新建商品时选不到，已有商品不受影响；有商品的品牌不能删除。</span>
      <el-button v-if="canManage" type="primary" :icon="Plus" @click="open()">新增品牌</el-button>
    </div>
    <el-table v-loading="loading" :data="brands" border empty-text="还没有品牌，先新增一个，再去建卡券商品">
      <el-table-column prop="name" label="品牌" min-width="180" />
      <el-table-column prop="sort" label="排序" width="80" />
      <el-table-column label="商品数" width="90">
        <template #default="{ row }">
          <router-link v-if="row.product_count > 0" :to="{ name: 'products-card', query: { brand_id: row.id } }">{{ row.product_count }}</router-link>
          <span v-else>0</span>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="80">
        <template #default="{ row }"><StatusTag :map="statusLabels" :value="row.status" /></template>
      </el-table-column>
      <el-table-column prop="updated_at" label="更新时间" width="170" />
      <el-table-column v-if="canManage" label="操作" width="170">
        <template #default="{ row }">
          <el-button link type="primary" @click="open(row as CardBrand)">编辑</el-button>
          <el-button link :type="row.status === 'active' ? 'warning' : 'success'" @click="toggle(row as CardBrand)">
            {{ row.status === 'active' ? '停用' : '启用' }}
          </el-button>
          <el-button link type="danger" :disabled="row.product_count > 0" @click="remove(row as CardBrand)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <el-dialog v-model="dialog" :title="editing ? '编辑品牌' : '新增品牌'" width="420px" @closed="formRef?.clearValidate()">
    <el-form ref="formRef" :model="form" :rules="rules" label-width="70px">
      <el-form-item label="名称" prop="name">
        <el-input v-model="form.name" maxlength="64" placeholder="如 爱奇艺、腾讯视频" />
      </el-form-item>
      <el-form-item label="排序">
        <el-input-number v-model="form.sort" :min="0" :max="9999" step-strictly />
        <div class="muted">数字小的排在前面</div>
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialog = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="save">保存</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
}

.muted {
  width: 100%;
  color: #909399;
  font-size: 12px;
}
</style>
