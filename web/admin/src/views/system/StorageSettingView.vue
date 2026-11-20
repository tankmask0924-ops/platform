<script setup lang="ts">
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { computed, onMounted, reactive, ref } from 'vue'
import { type StorageSetting, storageSettingApi, type StorageSettingForm } from '@/api/system'
import { usePermissionStore } from '@/stores/permission'

const permission = usePermissionStore()
const canManage = computed(() => permission.can('setting.manage'))

const setting = ref<StorageSetting | null>(null)
const loading = ref(false)
const saving = ref(false)
const testing = ref(false)
const testResult = ref<{ ok: boolean; message: string; url: string } | null>(null)
const formRef = ref<FormInstance>()

const form = reactive<StorageSettingForm>({
  driver: 'aliyun_oss',
  bucket: '',
  region: '',
  access_key_id: '',
  access_key_secret: '',
  domain: '',
  path_prefix: 'uploads',
})

const isOss = computed(() => form.driver === 'aliyun_oss')
const defaultHost = computed(() => {
  if (!form.bucket || !form.region) {
    return ''
  }
  return isOss.value
    ? `https://${form.bucket}.oss-${form.region.replace(/^oss-/, '')}.aliyuncs.com`
    : `https://${form.bucket}.cos.${form.region}.myqcloud.com`
})

// 换云厂商或换 AccessKey ID 时后端要求重填密钥
const secretRequired = computed(
  () =>
    !setting.value?.access_key_secret_set ||
    setting.value.driver !== form.driver ||
    setting.value.access_key_id !== form.access_key_id.trim(),
)

const rules: FormRules = {
  bucket: [{ required: true, message: '请填写存储空间名称', trigger: 'blur' }],
  region: [{ required: true, message: '请填写地域', trigger: 'blur' }],
  access_key_id: [{ required: true, message: '请填写 AccessKey ID', trigger: 'blur' }],
}

function fill(s: StorageSetting) {
  setting.value = s
  Object.assign(form, {
    driver: s.driver ?? 'aliyun_oss',
    bucket: s.bucket ?? '',
    region: s.region ?? '',
    access_key_id: s.access_key_id ?? '',
    access_key_secret: '',
    domain: s.domain ?? '',
    path_prefix: s.path_prefix,
  })
}

async function load() {
  loading.value = true
  try {
    fill(await storageSettingApi.get())
  } finally {
    loading.value = false
  }
}

async function save() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  if (secretRequired.value && !form.access_key_secret.trim()) {
    ElMessage.warning('请填写 AccessKey Secret')
    return
  }
  saving.value = true
  try {
    fill(await storageSettingApi.update({ ...form }))
    testResult.value = null
    ElMessage.success('已保存，建议点「测试连接」确认一下')
  } finally {
    saving.value = false
  }
}

async function test() {
  testing.value = true
  testResult.value = null
  try {
    const r = await storageSettingApi.test()
    testResult.value = { ok: r.public_readable, message: r.message, url: r.url }
  } finally {
    testing.value = false
  }
}

onMounted(load)
</script>

<template>
  <el-card v-loading="loading" shadow="never">
    <template #header>
      <div class="card-header">
        <span>云存储（商品图片等上传到这里）</span>
        <el-tag v-if="setting && !setting.configured" type="warning">未配置，后台暂时不能上传图片</el-tag>
      </div>
    </template>

    <el-form ref="formRef" :model="form" :rules="rules" label-width="140px" class="form" :disabled="!canManage">
      <el-form-item label="云存储">
        <el-radio-group v-model="form.driver">
          <el-radio-button value="aliyun_oss">阿里云 OSS</el-radio-button>
          <el-radio-button value="tencent_cos">腾讯云 COS</el-radio-button>
        </el-radio-group>
      </el-form-item>
      <el-form-item :label="isOss ? '存储空间' : '存储桶'" prop="bucket">
        <el-input v-model.trim="form.bucket" :placeholder="isOss ? 'Bucket 名称，如 platform-images' : 'Bucket 名称-APPID，如 images-1250000000'" />
      </el-form-item>
      <el-form-item label="地域" prop="region">
        <el-input v-model.trim="form.region" :placeholder="isOss ? '地域 ID，如 cn-hangzhou' : '如 ap-guangzhou'" />
      </el-form-item>
      <el-form-item :label="isOss ? 'AccessKey ID' : 'SecretId'" prop="access_key_id">
        <el-input v-model.trim="form.access_key_id" autocomplete="off" />
      </el-form-item>
      <el-form-item :label="isOss ? 'AccessKey Secret' : 'SecretKey'" :required="secretRequired">
        <el-input
          v-model="form.access_key_secret"
          type="password"
          show-password
          autocomplete="new-password"
          :placeholder="secretRequired ? '必填' : '已设置，留空表示不修改'"
        />
        <div class="tip">只保存不显示，加密存储。建议用只有这个存储空间上传权限的子账号密钥</div>
      </el-form-item>
      <el-form-item label="访问域名">
        <el-input v-model.trim="form.domain" placeholder="选填，绑定的自定义域名或 CDN，如 https://img.example.com" />
        <div class="tip">
          不填就用默认域名<template v-if="defaultHost">：{{ defaultHost }}</template>。存储空间需要设为<b>公共读</b>，商户才能直接打开图片
        </div>
      </el-form-item>
      <el-form-item label="路径前缀">
        <el-input v-model.trim="form.path_prefix" placeholder="uploads" />
        <div class="tip">图片存在「前缀/images/日期/随机文件名」下，多个系统共用一个存储空间时用来区分</div>
      </el-form-item>
      <el-form-item v-if="canManage">
        <el-button type="primary" :loading="saving" @click="save">保存</el-button>
        <el-button :disabled="!setting?.configured" :loading="testing" @click="test">测试连接</el-button>
      </el-form-item>
    </el-form>

    <el-alert
      v-if="testResult"
      :type="testResult.ok ? 'success' : 'warning'"
      show-icon
      :closable="false"
      :title="testResult.message"
      :description="`测试文件：${testResult.url}（测试完已删除）`"
    />
    <div v-if="setting?.updated_at" class="tip footer">最后修改：{{ setting.updated_by ?? '-' }}，{{ setting.updated_at }}</div>
  </el-card>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.form {
  max-width: 720px;
}

.tip {
  width: 100%;
  color: #909399;
  font-size: 12px;
  line-height: 1.6;
}

.footer {
  margin-top: 12px;
}
</style>
