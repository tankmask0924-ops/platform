<script setup lang="ts">
import { isHttpUrl, labelOf, merchantStatusLabels, merchantTypeLabels, qualificationStatusLabels, StatusTag } from '@platform/shared'
import { ElMessage, type FormInstance } from 'element-plus'
import { onMounted, reactive, ref } from 'vue'
import { qualificationApi, type QualificationStatus } from '@/api/merchant'
import QualificationFields from '@/components/QualificationFields.vue'
import { emptyQualificationForm, qualificationPayload, qualificationRules } from '@/qualification'

const data = ref<QualificationStatus | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    data.value = await qualificationApi.get()
  } finally {
    loading.value = false
  }
}

// 重新提交：用上次提交的资料预填，身份证号只拿得到后 4 位，需要重新填
const editing = ref(false)
const formRef = ref<FormInstance>()
const form = reactive(emptyQualificationForm())
const submitting = ref(false)

function startEdit() {
  const q = data.value?.qualification
  Object.assign(form, emptyQualificationForm(), {
    type: q?.type === 'individual' ? 'individual' : 'company',
    company_name: q?.company_name ?? '',
    business_license_no: q?.business_license_no ?? '',
    legal_person_name: q?.legal_person_name ?? '',
    contact_name: q?.contact_name ?? '',
    contact_phone: q?.contact_phone ?? '',
    id_card_name: q?.id_card_name ?? '',
  })
  editing.value = true
}

async function submit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  submitting.value = true
  try {
    data.value = await qualificationApi.resubmit(qualificationPayload(form))
    editing.value = false
    ElMessage.success('已重新提交，请等待平台审核')
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div v-loading="loading" class="page">
    <template v-if="data">
      <el-alert
        v-if="data.status === 'pending'"
        type="warning"
        show-icon
        :closable="false"
        title="资质审核中"
        description="平台审核通过后才能生成接口密钥和下单，请耐心等待。"
      />
      <el-alert v-else-if="data.status === 'rejected'" type="error" show-icon :closable="false" title="资质审核未通过">
        <div>驳回原因：{{ data.qualification?.reject_reason ?? '-' }}</div>
        <div>请按驳回原因修改资料后重新提交。</div>
      </el-alert>

      <el-card shadow="never" header="账户">
        <el-descriptions :column="3" border>
          <el-descriptions-item label="账户状态"><StatusTag :map="merchantStatusLabels" :value="data.status" /></el-descriptions-item>
          <el-descriptions-item label="商户类型">{{ labelOf(merchantTypeLabels, data.type) }}</el-descriptions-item>
          <el-descriptions-item label="当前等级">{{ data.level_name ?? (data.status === 'active' ? '未分配' : '审核通过后分配') }}</el-descriptions-item>
        </el-descriptions>
      </el-card>

      <el-card v-if="editing" shadow="never" header="修改资质资料">
        <el-form ref="formRef" :model="form" :rules="qualificationRules" label-width="110px" class="form" @submit.prevent="submit">
          <el-form-item label="商户类型">
            <el-radio-group v-model="form.type">
              <el-radio-button value="company">企业</el-radio-button>
              <el-radio-button value="individual">个人</el-radio-button>
            </el-radio-group>
          </el-form-item>
          <QualificationFields v-model="form" />
          <el-form-item>
            <el-button type="primary" native-type="submit" :loading="submitting">重新提交审核</el-button>
            <el-button @click="editing = false">取消</el-button>
          </el-form-item>
        </el-form>
      </el-card>

      <el-card v-else shadow="never">
        <template #header>
          <div class="card-header">
            <span>当前资质资料</span>
            <el-button v-if="data.can_resubmit" type="primary" @click="startEdit">修改并重新提交</el-button>
          </div>
        </template>
        <el-descriptions v-if="data.qualification" :column="2" border>
          <template v-if="data.qualification.type === 'company'">
            <el-descriptions-item label="公司名称">{{ data.qualification.company_name }}</el-descriptions-item>
            <el-descriptions-item label="营业执照号">{{ data.qualification.business_license_no }}</el-descriptions-item>
            <el-descriptions-item label="法人">{{ data.qualification.legal_person_name }}</el-descriptions-item>
            <el-descriptions-item label="联系人">{{ data.qualification.contact_name }}</el-descriptions-item>
            <el-descriptions-item label="营业执照图片" :span="2">
              <el-link v-if="isHttpUrl(data.qualification.business_license_image)" :href="data.qualification.business_license_image" target="_blank" type="primary">查看</el-link>
              <span v-else>未上传</span>
            </el-descriptions-item>
          </template>
          <template v-else>
            <el-descriptions-item label="姓名">{{ data.qualification.id_card_name }}</el-descriptions-item>
            <el-descriptions-item label="身份证号">{{ data.qualification.id_card_no_masked ?? '-' }}</el-descriptions-item>
          </template>
          <el-descriptions-item label="联系电话">{{ data.qualification.contact_phone }}</el-descriptions-item>
          <el-descriptions-item label="审核状态">
            <StatusTag :map="qualificationStatusLabels" :value="data.qualification.status" />
          </el-descriptions-item>
          <el-descriptions-item label="提交时间">{{ data.qualification.submitted_at ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="审核时间">{{ data.qualification.reviewed_at ?? '-' }}</el-descriptions-item>
        </el-descriptions>
        <el-empty v-else description="还没有资质资料" :image-size="60" />
        <p v-if="data.status === 'active'" class="muted">资质审核已通过，资料如有变更请联系平台。</p>
      </el-card>

      <el-card shadow="never" header="审核记录">
        <el-table :data="data.history" border>
          <el-table-column prop="submitted_at" label="提交时间" width="170" />
          <el-table-column label="类型" width="80">
            <template #default="{ row }">{{ labelOf(merchantTypeLabels, row.type) }}</template>
          </el-table-column>
          <el-table-column label="结果" width="90">
            <template #default="{ row }"><StatusTag :map="qualificationStatusLabels" :value="row.status" /></template>
          </el-table-column>
          <el-table-column label="驳回原因" min-width="200">
            <template #default="{ row }">{{ row.reject_reason ?? '-' }}</template>
          </el-table-column>
          <el-table-column label="审核时间" width="170">
            <template #default="{ row }">{{ row.reviewed_at ?? '-' }}</template>
          </el-table-column>
        </el-table>
      </el-card>
    </template>
  </div>
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

.form {
  max-width: 560px;
}

.muted {
  margin: 12px 0 0;
  color: var(--el-text-color-secondary);
  font-size: 12px;
}
</style>
