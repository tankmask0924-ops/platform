<script setup lang="ts">
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { register } from '@/api/auth'
import QualificationFields from '@/components/QualificationFields.vue'
import { emptyQualificationForm, qualificationPayload, qualificationRules } from '@/qualification'

const router = useRouter()
const title = import.meta.env.VITE_APP_TITLE
const formRef = ref<FormInstance>()
const submitting = ref(false)
const done = ref(false)

const form = reactive({
  ...emptyQualificationForm(),
  phone: '',
  email: '',
  password: '',
  password_confirm: '',
})

const rules: FormRules = {
  ...qualificationRules,
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
}

async function submit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  submitting.value = true
  try {
    await register({ ...qualificationPayload(form), phone: form.phone.trim(), email: form.email.trim(), password: form.password })
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
        <QualificationFields v-model="form" />

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
</style>
