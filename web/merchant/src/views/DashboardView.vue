<script setup lang="ts">
import { isNegative, labelOf, merchantStatusLabels, merchantTypeLabels, money, StatusTag } from '@platform/shared'
import { onMounted, ref } from 'vue'
import { fetchMe, type Me } from '@/api/auth'
import { dashboardApi, type DashboardStats } from '@/api/merchant'
import TrendChart from '@/components/TrendChart.vue'

const me = ref<Me | null>(null)
const stats = ref<DashboardStats | null>(null)
const loading = ref(false)

onMounted(async () => {
  loading.value = true
  try {
    ;[me.value, stats.value] = await Promise.all([fetchMe(), dashboardApi.stats()])
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div v-loading="loading" class="dashboard">
    <template v-if="me">
      <el-alert
        v-if="me.status === 'pending'"
        type="warning"
        show-icon
        :closable="false"
        title="资质审核中"
        description="平台审核通过后才能生成接口密钥和下单，请耐心等待。"
      />
      <el-alert v-else-if="me.status === 'rejected'" type="error" show-icon :closable="false" title="资质审核未通过">
        <router-link :to="{ name: 'qualification' }">查看驳回原因并修改资料重新提交</router-link>
      </el-alert>
      <el-alert
        v-if="me.debt_since"
        :type="me.debt_warning ? 'error' : 'warning'"
        show-icon
        :closable="false"
        :title="me.debt_warning ? '欠款已超过预警线，请尽快充值' : '账户余额为负，下单已暂停，请尽快充值'"
        :description="`欠款开始时间：${me.debt_since}。充值到账、余额回到 0 以上后自动恢复下单。`"
      />

      <el-row :gutter="16">
        <el-col :xs="24" :sm="8">
          <el-card shadow="never">
            <div class="stat-label">可用余额</div>
            <div class="stat-value" :class="{ negative: isNegative(me.available_balance) }">
              {{ money(me.available_balance) }}
            </div>
            <router-link to="/finance/recharge">去充值</router-link>
          </el-card>
        </el-col>
        <el-col :xs="24" :sm="8">
          <el-card shadow="never">
            <div class="stat-label">冻结金额</div>
            <div class="stat-value">{{ money(me.frozen_balance) }}</div>
            <span class="stat-hint">处理中订单占用的金额，订单有结果后扣款或解冻</span>
          </el-card>
        </el-col>
        <el-col :xs="24" :sm="8">
          <el-card shadow="never">
            <div class="stat-label">待到账返佣</div>
            <div class="stat-value">{{ money(stats?.pending_rebate ?? '0.00') }}</div>
            <router-link to="/finance/rebates">返佣明细</router-link>
          </el-card>
        </el-col>
      </el-row>

      <el-row v-if="stats" :gutter="16">
        <el-col :xs="24" :sm="8">
          <el-card shadow="never">
            <div class="stat-label">今日订单</div>
            <div class="stat-value">{{ stats.today.order_count }} <span class="stat-unit">单</span></div>
            <router-link to="/orders">订单列表</router-link>
          </el-card>
        </el-col>
        <el-col :xs="24" :sm="8">
          <el-card shadow="never">
            <div class="stat-label">今日消费</div>
            <div class="stat-value">{{ money(stats.today.amount) }}</div>
            <span class="stat-hint">实际扣款减去已退款</span>
          </el-card>
        </el-col>
        <el-col :xs="24" :sm="8">
          <el-card shadow="never">
            <div class="stat-label">今日成功率</div>
            <div class="stat-value">{{ stats.today.success_rate === null ? '-' : `${stats.today.success_rate}%` }}</div>
            <span class="stat-hint">
              已出结果 {{ stats.today.finished_count }} 单，成功 {{ stats.today.success_count }} 单（处理中的不计入）
            </span>
          </el-card>
        </el-col>
      </el-row>

      <el-card v-if="stats" shadow="never" header="近 7 天消费">
        <TrendChart :points="stats.trend" />
      </el-card>

      <el-card shadow="never" header="账户信息">
        <el-descriptions :column="2" border>
          <el-descriptions-item label="商户 ID">{{ me.id }}</el-descriptions-item>
          <el-descriptions-item label="类型">{{ labelOf(merchantTypeLabels, me.type) }}</el-descriptions-item>
          <el-descriptions-item label="手机号">{{ me.phone ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="邮箱">{{ me.email ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            <StatusTag :map="merchantStatusLabels" :value="me.status" />
          </el-descriptions-item>
        </el-descriptions>
      </el-card>
    </template>
  </div>
</template>

<style scoped>
.dashboard {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-height: 200px;
}

.stat-label {
  color: #909399;
  font-size: 14px;
}

.stat-value {
  margin: 8px 0;
  font-size: 28px;
  font-weight: 600;
}

.stat-unit {
  font-size: 14px;
  font-weight: normal;
}

.stat-value.negative {
  color: #f56c6c;
}

.stat-hint {
  color: #909399;
  font-size: 12px;
}

.el-col {
  margin-bottom: 0;
}

@media (max-width: 767px) {
  .el-col + .el-col {
    margin-top: 16px;
  }
}
</style>
