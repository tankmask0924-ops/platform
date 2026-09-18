<script setup lang="ts">
import type { QualificationForm } from '@/qualification'

/** 资质资料的表单项，放在父组件的 el-form 里用（规则见 qualificationRules） */
const form = defineModel<QualificationForm>({ required: true })
</script>

<template>
  <template v-if="form.type === 'company'">
    <el-form-item label="公司名称" prop="company_name">
      <el-input v-model="form.company_name" maxlength="128" />
    </el-form-item>
    <el-form-item label="营业执照号" prop="business_license_no">
      <el-input v-model="form.business_license_no" maxlength="64" />
    </el-form-item>
    <el-form-item label="法人姓名" prop="legal_person_name">
      <el-input v-model="form.legal_person_name" maxlength="64" />
    </el-form-item>
    <el-form-item label="联系人" prop="contact_name">
      <el-input v-model="form.contact_name" maxlength="64" />
    </el-form-item>
  </template>
  <template v-else>
    <el-form-item label="姓名" prop="id_card_name">
      <el-input v-model="form.id_card_name" maxlength="64" />
    </el-form-item>
    <el-form-item label="身份证号" prop="id_card_no">
      <el-input v-model="form.id_card_no" maxlength="18" />
    </el-form-item>
  </template>
  <el-form-item label="联系电话" prop="contact_phone">
    <el-input v-model="form.contact_phone" maxlength="20" />
  </el-form-item>
  <el-form-item>
    <div class="tip">营业执照、身份证照片上传暂未开放，审核时平台可能会另行联系你补充。</div>
  </el-form-item>
</template>

<style scoped>
.tip {
  color: #909399;
  font-size: 12px;
  line-height: 1.6;
}
</style>
