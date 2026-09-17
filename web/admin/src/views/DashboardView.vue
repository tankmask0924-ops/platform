<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { disputeApi, orderApi, rechargeApi } from '@/api/admin'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

interface Todo {
  title: string
  count: number | null
  to: { name: string; query?: Record<string, string> }
}

const todos = ref<Todo[]>([
  { title: '待审核充值', count: null, to: { name: 'recharge-review' } },
  { title: '异常订单', count: null, to: { name: 'orders', query: { status: 'abnormal' } } },
  { title: '处理中的售后争议', count: null, to: { name: 'disputes' } },
])

onMounted(() => {
  const fetchers = [
    () => rechargeApi.list({ status: 'pending', per_page: 1 }),
    () => orderApi.list({ status: 'abnormal', per_page: 1 }),
    () => disputeApi.list({ status: 'processing', per_page: 1 }),
  ]
  // 没有对应权限的会 403，单独失败不影响其它
  fetchers.forEach((fetch, i) => {
    fetch()
      .then((result) => {
        todos.value[i]!.count = result.total
      })
      .catch(() => {})
  })
})
</script>

<template>
  <el-card shadow="never">
    <h2>欢迎回来，{{ auth.username }}</h2>
  </el-card>
  <el-row :gutter="16" class="todos">
    <el-col v-for="todo in todos" :key="todo.title" :xs="24" :sm="8">
      <router-link :to="todo.to" class="todo-link">
        <el-card shadow="hover">
          <div class="label">{{ todo.title }}</div>
          <div class="value" :class="{ alert: (todo.count ?? 0) > 0 }">{{ todo.count ?? '-' }}</div>
        </el-card>
      </router-link>
    </el-col>
  </el-row>
</template>

<style scoped>
.todos {
  margin-top: 16px;
}

.todo-link {
  text-decoration: none;
}

.label {
  color: #909399;
}

.value {
  margin-top: 8px;
  font-size: 28px;
  font-weight: 600;
  color: #303133;
}

.value.alert {
  color: #f56c6c;
}

@media (max-width: 767px) {
  .el-col + .el-col {
    margin-top: 16px;
  }
}
</style>
