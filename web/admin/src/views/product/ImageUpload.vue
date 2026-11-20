<script setup lang="ts">
import { Plus } from '@element-plus/icons-vue'
import { ElMessage } from 'element-plus'
import { ref } from 'vue'
import { uploadApi } from '@/api/admin'

/**
 * 图片上传：选文件后上传到云存储（系统设置 - 云存储），v-model 是图片地址。
 * 文件格式和大小前端先挡一下，后端还会按内容再校验。
 */
const model = defineModel<string | null>({ default: null })
const uploading = ref(false)
const input = ref<HTMLInputElement>()

const ACCEPT = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']
const MAX_BYTES = 2 * 1024 * 1024

async function onChange(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  ;(event.target as HTMLInputElement).value = ''
  if (!file) {
    return
  }
  if (!ACCEPT.includes(file.type)) {
    ElMessage.warning('只支持 jpg、png、gif、webp 格式的图片')
    return
  }
  if (file.size > MAX_BYTES) {
    ElMessage.warning('图片不能超过 2MB')
    return
  }
  uploading.value = true
  try {
    model.value = (await uploadApi.image(file)).url
  } finally {
    uploading.value = false
  }
}
</script>

<template>
  <div class="image-upload">
    <div v-loading="uploading" class="box" @click="input?.click()">
      <el-image v-if="model" :src="model" fit="cover" class="image" />
      <el-icon v-else class="placeholder"><Plus /></el-icon>
    </div>
    <div class="side">
      <el-button v-if="model" link type="primary" :disabled="uploading" @click="input?.click()">更换</el-button>
      <el-button v-if="model" link type="danger" :disabled="uploading" @click="model = null">移除</el-button>
      <div class="tip">jpg / png / gif / webp，不超过 2MB</div>
    </div>
    <input ref="input" type="file" :accept="ACCEPT.join(',')" hidden @change="onChange" />
  </div>
</template>

<style scoped>
.image-upload {
  display: flex;
  gap: 12px;
  align-items: flex-end;
}

.box {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 96px;
  height: 96px;
  overflow: hidden;
  border: 1px dashed var(--el-border-color);
  border-radius: 6px;
  cursor: pointer;
}

.box:hover {
  border-color: var(--el-color-primary);
}

.image {
  width: 100%;
  height: 100%;
}

.placeholder {
  color: #909399;
  font-size: 24px;
}

.side {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
}

.tip {
  color: #909399;
  font-size: 12px;
}
</style>
