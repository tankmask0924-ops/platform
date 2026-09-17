<script setup lang="ts">
import { copyText, splitLines } from '@platform/shared'
import { ElMessage, ElMessageBox } from 'element-plus'
import { onMounted, ref } from 'vue'
import { fetchMe } from '@/api/auth'
import { type DevSettings, devSettingsApi, type SecretResult } from '@/api/merchant'

const loading = ref(false)
const settings = ref<DevSettings | null>(null)
const active = ref(false)

async function load() {
  loading.value = true
  try {
    const [s, me] = await Promise.all([devSettingsApi.get(), fetchMe()])
    settings.value = s
    active.value = me.status === 'active'
    ipText.value = s.ip_whitelist.join('\n')
  } finally {
    loading.value = false
  }
}

// 密钥只在生成/重置的这一次响应里返回明文
const secret = ref<SecretResult | null>(null)
const working = ref(false)

async function generate() {
  working.value = true
  try {
    secret.value = await devSettingsApi.generate()
    await load()
  } finally {
    working.value = false
  }
}

async function resetSecret() {
  try {
    await ElMessageBox.confirm('重置后旧的 AppSecret 立即失效，正在调用接口的系统需要同步更换。确定重置吗？', '重置 AppSecret', {
      type: 'warning',
    })
  } catch {
    return
  }
  working.value = true
  try {
    secret.value = await devSettingsApi.resetSecret()
    await load()
  } finally {
    working.value = false
  }
}

const ipText = ref('')
const savingIp = ref(false)

async function saveIps() {
  savingIp.value = true
  try {
    const result = await devSettingsApi.updateIpWhitelist(splitLines(ipText.value))
    ipText.value = result.ip_whitelist.join('\n')
    ElMessage.success('IP 白名单已保存')
  } finally {
    savingIp.value = false
  }
}

onMounted(load)
</script>

<template>
  <div v-loading="loading" class="page">
    <el-card shadow="never" header="接口密钥">
      <el-alert
        v-if="!active"
        type="warning"
        show-icon
        :closable="false"
        title="商户审核通过后才能生成接口密钥"
        class="block"
      />
      <el-descriptions v-if="settings" :column="1" border>
        <el-descriptions-item label="AppKey">
          <template v-if="settings.app_key">
            <code>{{ settings.app_key }}</code>
            <el-button link type="primary" @click="copyText(settings.app_key)">复制</el-button>
          </template>
          <span v-else class="muted">尚未生成</span>
        </el-descriptions-item>
        <el-descriptions-item label="AppSecret">
          <span v-if="settings.app_secret_generated" class="muted">已生成（出于安全考虑不再显示，遗失请重置）</span>
          <span v-else class="muted">尚未生成</span>
        </el-descriptions-item>
        <el-descriptions-item label="最近生成/重置时间">{{ settings.app_secret_reset_at ?? '-' }}</el-descriptions-item>
      </el-descriptions>
      <div v-if="settings" class="actions">
        <el-button v-if="!settings.app_key" type="primary" :disabled="!active" :loading="working" @click="generate">
          生成密钥
        </el-button>
        <el-button v-else type="danger" plain :disabled="!active" :loading="working" @click="resetSecret">
          重置 AppSecret
        </el-button>
      </div>
    </el-card>

    <el-card shadow="never" header="IP 白名单">
      <p class="muted">每行一个 IP（支持 IPv4 / IPv6）。留空表示不限制来源 IP。</p>
      <el-input v-model="ipText" type="textarea" :rows="6" placeholder="例如：&#10;1.2.3.4&#10;5.6.7.8" />
      <div class="actions">
        <el-button type="primary" :loading="savingIp" @click="saveIps">保存</el-button>
      </div>
    </el-card>

    <el-dialog :model-value="secret !== null" title="请立即保存 AppSecret" width="560px" :close-on-click-modal="false" @close="secret = null">
      <el-alert type="warning" show-icon :closable="false" title="AppSecret 只显示这一次，关闭后无法再次查看。" class="block" />
      <el-descriptions v-if="secret" :column="1" border>
        <el-descriptions-item label="AppKey">
          <code>{{ secret.app_key }}</code>
          <el-button link type="primary" @click="copyText(secret.app_key)">复制</el-button>
        </el-descriptions-item>
        <el-descriptions-item label="AppSecret">
          <code class="break">{{ secret.app_secret }}</code>
          <el-button link type="primary" @click="copyText(secret.app_secret)">复制</el-button>
        </el-descriptions-item>
      </el-descriptions>
      <template #footer>
        <el-button type="primary" @click="secret = null">我已保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.page {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.block {
  margin-bottom: 16px;
}

.actions {
  margin-top: 16px;
}

.muted {
  color: #909399;
}

.break {
  word-break: break-all;
}
</style>
