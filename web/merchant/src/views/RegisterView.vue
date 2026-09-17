<script setup lang="ts">
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { register, type RegisterForm } from '@/api/auth'

const router = useRouter()
const title = import.meta.env.VITE_APP_TITLE
const formRef = ref<FormInstance>()
const submitting = ref(false)
const done = ref(false)

const form = reactive({
  type: 'company' as RegisterForm['type'],
  phone: '',
  email: '',
  password: '',
  password_confirm: '',
  company_name: '',
  business_license_no: '',
  legal_person_name: '',
  contact_name: '',
  contact_phone: '',
  id_card_name: '',
  id_card_no: '',
})

const required = (message: string) => [{ required: true, message, trigger: 'blur' }]

const rules: FormRules = {
  phone: [
    {
      validator: (_rule, _value, callback) => {
        if (form.phone.trim() === '' && form.email.trim() === '') {
          callback(new Error('手机号和邮箱至少填写一个'))
        } else {
          callback()
        }
      },
      trigger: 'blur',
    },
  ],
  email: [{ type: 'email', message: '邮箱格式不正确', trigger: 'blur' }],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' },
    { min: 8, message: '密码至少 8 位', trigger: 'blur' },
  ],
  password_confirm: [
    {
      validator: (_rule, value, callback) => {
        callback(value === form.password ? undefined : new Error('两次输入的密码不一致'))
      },
      trigger: 'blur',
    },
  ],
  company_name: required('请输入公司名称'),
  business_license_no: required('请输入营业执照号'),
  legal_person_name: required('请输入法人姓名'),
  contact_name: required('请输入联系人姓名'),
  contact_phone: required('请输入联系电话'),
  id_card_name: required('请输入姓名'),
  id_card_no: required('请输入身份证号'),
}

async function submit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  const data: RegisterForm = {
    type: form.type,
    phone: form.phone.trim(),
    email: form.email.trim(),
    password: form.password,
    contact_phone: form.contact_phone.trim(),
  }
  if (form.type === 'company') {
    Object.assign(data, {
      company_name: form.company_name,
      business_license_no: form.business_license_no,
      legal_person_name: form.legal_person_name,
      contact_name: form.contact_name,
    })
  } else {
    Object.assign(data, { id_card_name: form.id_card_name, id_card_no: form.id_card_no })
  }
  submitting.value = true
  try {
    await register(data)
    done.value = true
    ElMessage.success('注册成功')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="register">
    <el-card class="card" shadow="always">
      <h1 class="title">{{ title }} · 商户注册</h1>

      <el-result v-if="done" icon="success" title="注册成功" sub-title="资料已提交，平台审核通过后即可生成接口密钥、开始下单。现在可以先登录查看审核状态。">
        <template #extra>
          <el-button type="primary" @click="router.push({ name: 'login' })">去登录</el-button>
        </template>
      </el-result>

      <el-form v-else ref="formRef" :model="form" :rules="rules" label-width="110px" @submit.prevent="submit">
        <el-form-item label="商户类型">
          <el-radio-group v-model="form.type">
            <el-radio-button value="company">企业</el-radio-button>
            <el-radio-button value="individual">个人</el-radio-button>
          </el-radio-group>
        </el-form-item>

        <el-divider content-position="left">登录账号</el-divider>
        <el-form-item label="手机号" prop="phone">
          <el-input v-model="form.phone" maxlength="20" placeholder="手机号和邮箱至少填一个，都可用于登录" />
        </el-form-item>
        <el-form-item label="邮箱" prop="email">
          <el-input v-model="form.email" maxlength="128" />
        </el-form-item>
        <el-form-item label="密码" prop="password">
          <el-input v-model="form.password" type="password" show-password placeholder="至少 8 位" />
        </el-form-item>
        <el-form-item label="确认密码" prop="password_confirm">
          <el-input v-model="form.password_confirm" type="password" show-password />
        </el-form-item>

        <el-divider content-position="left">资质信息</el-divider>
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

        <el-form-item>
          <el-button type="primary" native-type="submit" :loading="submitting">提交注册</el-button>
          <el-button link type="primary" @click="router.push({ name: 'login' })">已有账号，去登录</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.register {
  display: flex;
  justify-content: center;
  min-height: 100%;
  padding: 40px 16px;
  box-sizing: border-box;
  background: linear-gradient(135deg, #001529 0%, #0c3a6b 100%);
}

.card {
  width: 560px;
  max-width: 100%;
  align-self: flex-start;
}

.title {
  margin: 0 0 20px;
  text-align: center;
  font-size: 22px;
  font-weight: 600;
}

.tip {
  color: #909399;
  font-size: 12px;
  line-height: 1.6;
}
</style>
