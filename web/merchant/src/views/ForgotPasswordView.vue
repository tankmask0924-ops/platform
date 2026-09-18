<script setup lang="ts">
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { onBeforeUnmount, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { resetPassword, sendResetCode } from '@/api/auth'

const router = useRouter()
const title = import.meta.env.VITE_APP_TITLE

const formRef = ref<FormInstance>()
const form = reactive({ phone: '', code: '', new_password: '', confirm: '' })
const rules: FormRules = {
  phone: [
    { required: true, message: '请输入注册时的手机号', trigger: 'blur' },
    { pattern: /^1\d{10}$/, message: '请输入 11 位手机号', trigger: 'blur' },
  ],
  code: [
    { required: true, message: '请输入验证码', trigger: 'blur' },
    { pattern: /^\d{6}$/, message: '验证码是 6 位数字', trigger: 'blur' },
  ],
  new_password: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 8, message: '至少 8 位', trigger: 'blur' },
  ],
  confirm: [
    {
      validator: (_rule, value: string, callback) =>
        value === form.new_password ? callback() : callback(new Error('两次输入的密码不一致')),
      trigger: 'blur',
    },
  ],
}

const sending = ref(false)
const countdown = ref(0)
let timer: ReturnType<typeof setInterval> | undefined

function startCountdown(seconds: number) {
  countdown.value = seconds
  clearInterval(timer)
  timer = setInterval(() => {
    countdown.value -= 1
    if (countdown.value <= 0) {
      clearInterval(timer)
    }
  }, 1000)
}

async function sendCode() {
  if (!(await formRef.value?.validateField('phone').catch(() => false))) {
    return
  }
  sending.value = true
  try {
    const result = await sendResetCode(form.phone.trim())
    // 手机号没注册也会走到这里，提示里不能说"已发送"
    ElMessage.success('如果该手机号已注册，验证码短信已发出，10 分钟内有效')
    startCountdown(result.resend_after)
  } finally {
    sending.value = false
  }
}

const submitting = ref(false)
const done = ref(false)

async function submit() {
  if (!(await formRef.value?.validate().catch(() => false))) {
    return
  }
  submitting.value = true
  try {
    await resetPassword({ phone: form.phone.trim(), code: form.code.trim(), new_password: form.new_password })
    done.value = true
  } finally {
    submitting.value = false
  }
}

onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <div class="page">
    <el-card class="card" shadow="always">
      <h1 class="title">{{ title }} · 找回密码</h1>

      <el-result v-if="done" icon="success" title="密码已重置" sub-title="之前的登录已全部失效，请用新密码登录。">
        <template #extra>
          <el-button type="primary" @click="router.push({ name: 'login' })">去登录</el-button>
        </template>
      </el-result>

      <el-form v-else ref="formRef" :model="form" :rules="rules" label-width="90px" @submit.prevent="submit">
        <el-form-item label="手机号" prop="phone">
          <el-input v-model="form.phone" placeholder="注册时填写的手机号" maxlength="11" />
        </el-form-item>
        <el-form-item label="验证码" prop="code">
          <div class="code-row">
            <el-input v-model="form.code" maxlength="6" placeholder="6 位数字" />
            <el-button :disabled="countdown > 0" :loading="sending" @click="sendCode">
              {{ countdown > 0 ? `${countdown} 秒后重发` : '获取验证码' }}
            </el-button>
          </div>
        </el-form-item>
        <el-form-item label="新密码" prop="new_password">
          <el-input v-model="form.new_password" type="password" show-password autocomplete="new-password" placeholder="至少 8 位" />
        </el-form-item>
        <el-form-item label="确认密码" prop="confirm">
          <el-input v-model="form.confirm" type="password" show-password autocomplete="new-password" />
        </el-form-item>
        <el-form-item>
          <div class="tip">只能通过手机短信找回。注册时只填了邮箱的，请联系平台客服重置密码。</div>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" native-type="submit" :loading="submitting">重置密码</el-button>
          <el-button link type="primary" @click="router.push({ name: 'login' })">返回登录</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.page {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 100%;
  padding: 40px 16px;
  box-sizing: border-box;
  background: linear-gradient(135deg, #001529 0%, #0c3a6b 100%);
}

.card {
  width: 460px;
  max-width: 100%;
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

.code-row {
  display: flex;
  gap: 8px;
  width: 100%;
}
</style>
