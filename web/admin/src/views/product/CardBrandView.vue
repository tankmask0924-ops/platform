<script setup lang="ts">
import { StatusTag } from '@platform/shared'
import { Plus } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { computed, onMounted, reactive, ref } from 'vue'
import { type CardBrand, cardBrandApi, type CardCategory, cardCategoryApi } from '@/api/admin'
import { usePermissionStore } from '@/stores/permission'

/**
 * 卡券分类（两级）：左边分类，右边选中分类下的品牌。卡券商品选品牌，分类由品牌带出来；
 * 会员等级、时长（黄金/白金、月卡/年卡）是商品本身的区别，不在这里建。
 */
const permission = usePermissionStore()
const canManage = computed(() => permission.can('product.manage'))

const statusLabels = {
  active: { label: '启用', type: 'success' as const },
  disabled: { label: '停用', type: 'info' as const },
}

const categories = ref<CardCategory[]>([])
const brands = ref<CardBrand[]>([])
const selectedId = ref<number | null>(null)
const categoriesLoading = ref(false)
const brandsLoading = ref(false)
const selected = computed(() => categories.value.find((c) => c.id === selectedId.value) ?? null)

async function loadCategories() {
  categoriesLoading.value = true
  try {
    categories.value = await cardCategoryApi.list()
    if (!categories.value.some((c) => c.id === selectedId.value)) {
      selectedId.value = categories.value[0]?.id ?? null
    }
  } finally {
    categoriesLoading.value = false
  }
  await loadBrands()
}

async function loadBrands() {
  if (selectedId.value === null) {
    brands.value = []
    return
  }
  brandsLoading.value = true
  try {
    brands.value = await cardBrandApi.list({ category_id: selectedId.value })
  } finally {
    brandsLoading.value = false
  }
}

function select(category: CardCategory) {
  selectedId.value = category.id
  loadBrands()
}

// 分类、品牌共用一个编辑弹窗
type Kind = 'category' | 'brand'
const dialog = ref(false)
const kind = ref<Kind>('category')
const editingId = ref<number | null>(null)
const saving = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({ name: '', sort: 0, category_id: undefined as number | undefined })
const rules: FormRules = {
  name: [{ required: true, message: '请输入名称', trigger: 'blur' }],
  category_id: [{ required: true, message: '请选择分类', trigger: 'change' }],
}
const activeCategories = computed(() => categories.value.filter((c) => c.status === 'active' || c.id === form.category_id))

function open(k: Kind, row?: CardCategory | CardBrand) {
  kind.value = k
  editingId.value = row?.id ?? null
  Object.assign(form, {
    name: row?.name ?? '',
    sort: row?.sort ?? 0,
    category_id: k === 'brand' ? ((row as CardBrand | undefined)?.category_id ?? selectedId.value ?? undefined) : undefined,
  })
  dialog.value = true
}

async function save() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  saving.value = true
  try {
    const base = { name: form.name.trim(), sort: form.sort }
    if (kind.value === 'category') {
      editingId.value ? await cardCategoryApi.update(editingId.value, base) : await cardCategoryApi.create(base)
    } else {
      const data = { ...base, category_id: form.category_id as number }
      editingId.value ? await cardBrandApi.update(editingId.value, data) : await cardBrandApi.create(data)
    }
    ElMessage.success('已保存')
    dialog.value = false
    await loadCategories()
  } finally {
    saving.value = false
  }
}

async function toggle(k: Kind, row: CardCategory | CardBrand) {
  const next = row.status === 'active' ? 'disabled' : 'active'
  await (k === 'category' ? cardCategoryApi.update(row.id, { status: next }) : cardBrandApi.update(row.id, { status: next }))
  ElMessage.success(next === 'active' ? '已启用' : '已停用，新建时选不到了，已有数据不受影响')
  await loadCategories()
}

async function remove(k: Kind, row: CardCategory | CardBrand) {
  try {
    await ElMessageBox.confirm(`确定删除${k === 'category' ? '分类' : '品牌'}「${row.name}」吗？`, '删除', { type: 'warning' })
  } catch {
    return
  }
  await (k === 'category' ? cardCategoryApi.remove(row.id) : cardBrandApi.remove(row.id))
  ElMessage.success('已删除')
  await loadCategories()
}

onMounted(loadCategories)
</script>

<template>
  <div class="layout">
    <el-card v-loading="categoriesLoading" shadow="never" class="categories">
      <template #header>
        <div class="card-header">
          <span>分类</span>
          <el-button v-if="canManage" type="primary" :icon="Plus" size="small" @click="open('category')">新增分类</el-button>
        </div>
      </template>
      <el-table :data="categories" border highlight-current-row :current-row-key="selectedId ?? undefined" row-key="id" empty-text="还没有分类，先新增一个" @row-click="select">
        <el-table-column prop="name" label="分类" min-width="120" />
        <el-table-column prop="brand_count" label="品牌数" width="70" />
        <el-table-column label="状态" width="70">
          <template #default="{ row }"><StatusTag :map="statusLabels" :value="row.status" /></template>
        </el-table-column>
        <el-table-column v-if="canManage" label="操作" width="150">
          <template #default="{ row }">
            <el-button link type="primary" @click.stop="open('category', row as CardCategory)">编辑</el-button>
            <el-button link :type="row.status === 'active' ? 'warning' : 'success'" @click.stop="toggle('category', row as CardCategory)">
              {{ row.status === 'active' ? '停用' : '启用' }}
            </el-button>
            <el-button link type="danger" :disabled="row.brand_count > 0" @click.stop="remove('category', row as CardCategory)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-card v-loading="brandsLoading" shadow="never" class="brands">
      <template #header>
        <div class="card-header">
          <span>{{ selected ? `「${selected.name}」下的品牌` : '品牌' }}</span>
          <el-button v-if="canManage && selected" type="primary" :icon="Plus" size="small" @click="open('brand')">新增品牌</el-button>
        </div>
      </template>
      <div class="muted block">卡券商品选品牌，分类由品牌带出来。黄金/白金会员、月卡/年卡这些是不同的商品，在商品里区分，不用建分类。停用的不能新选，已有数据不受影响；下面还有东西的不能删除。</div>
      <el-table :data="brands" border :empty-text="selected ? '这个分类下还没有品牌' : '先在左边选一个分类'">
        <el-table-column prop="name" label="品牌" min-width="150" />
        <el-table-column prop="sort" label="排序" width="70" />
        <el-table-column label="商品数" width="80">
          <template #default="{ row }">
            <router-link v-if="row.product_count > 0" :to="{ name: 'products-card', query: { brand_id: row.id } }">{{ row.product_count }}</router-link>
            <span v-else>0</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="70">
          <template #default="{ row }"><StatusTag :map="statusLabels" :value="row.status" /></template>
        </el-table-column>
        <el-table-column v-if="canManage" label="操作" width="150">
          <template #default="{ row }">
            <el-button link type="primary" @click="open('brand', row as CardBrand)">编辑</el-button>
            <el-button link :type="row.status === 'active' ? 'warning' : 'success'" @click="toggle('brand', row as CardBrand)">
              {{ row.status === 'active' ? '停用' : '启用' }}
            </el-button>
            <el-button link type="danger" :disabled="row.product_count > 0" @click="remove('brand', row as CardBrand)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>

  <el-dialog
    v-model="dialog"
    :title="`${editingId ? '编辑' : '新增'}${kind === 'category' ? '分类' : '品牌'}`"
    width="420px"
    @closed="formRef?.clearValidate()"
  >
    <el-form ref="formRef" :model="form" :rules="rules" label-width="70px">
      <el-form-item v-if="kind === 'brand'" label="分类" prop="category_id">
        <el-select v-model="form.category_id" placeholder="请选择" style="width: 100%">
          <el-option v-for="c in activeCategories" :key="c.id" :value="c.id" :label="c.status === 'active' ? c.name : `${c.name}（已停用）`" />
        </el-select>
      </el-form-item>
      <el-form-item label="名称" prop="name">
        <el-input v-model="form.name" maxlength="64" :placeholder="kind === 'category' ? '如 影音会员、游戏点卡' : '如 爱奇艺、腾讯视频'" />
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
.layout {
  display: grid;
  grid-template-columns: minmax(360px, 2fr) 3fr;
  gap: 16px;
  align-items: start;
}

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.categories :deep(.el-table__row) {
  cursor: pointer;
}

.muted {
  width: 100%;
  color: #909399;
  font-size: 12px;
}

.block {
  margin-bottom: 12px;
}
</style>
