<script setup lang="ts">
import { businessLineLabels, labelOf, toOptions } from '@platform/shared'
import { ElMessage } from 'element-plus'
import { computed, onMounted, reactive, ref } from 'vue'
import { type PricingPreview, type PricingRule, pricingRuleApi } from '@/api/admin'
import { pricingRuleTypeLabels } from '@/labels'
import { usePermissionStore } from '@/stores/permission'

const permission = usePermissionStore()
const canManage = computed(() => permission.can('pricing.manage'))

const typeOptions = toOptions(pricingRuleTypeLabels)
const rules = ref<PricingRule[]>([])
const loading = ref(false)

/** 每条业务线一份编辑中的值，跟服务端返回的分开放，避免没保存就显示成已生效 */
const drafts = reactive<Record<string, { rule_type: string; value: string }>>({})

function syncDrafts(list: PricingRule[]) {
  rules.value = list
  for (const rule of list) {
    drafts[rule.business_line] = {
      rule_type: rule.rule_type ?? 'fixed',
      // 百分比在界面上按「%」输入，存的是比例，这里换算一次
      value: rule.value === null ? '' : rule.rule_type === 'percentage' ? percentOf(rule.value) : rule.value,
    }
  }
}

function percentOf(rate: string): string {
  return String(Number(rate) * 100)
}

function rateOf(percent: string): string {
  return String(Number(percent) / 100)
}

async function load() {
  loading.value = true
  try {
    syncDrafts((await pricingRuleApi.list()).data)
  } finally {
    loading.value = false
  }
}

const saving = ref('')
async function save(businessLine: string) {
  const draft = drafts[businessLine]
  saving.value = businessLine
  try {
    const payload = {
      rule_type: draft.rule_type,
      value: draft.rule_type === 'percentage' ? rateOf(draft.value) : draft.value,
    }
    syncDrafts((await pricingRuleApi.update(businessLine, payload)).data)
    ElMessage.success('已保存，只影响之后下的订单')
  } finally {
    saving.value = ''
  }
}

/** 价格预览：用当前编辑中的规则算，不用保存也能试 */
const previewForm = reactive({ business_line: 'express', cost: '10.00' })
const preview = ref<PricingPreview | null>(null)
const previewing = ref(false)

async function runPreview() {
  const draft = drafts[previewForm.business_line]
  previewing.value = true
  try {
    preview.value = await pricingRuleApi.preview({
      business_line: previewForm.business_line,
      cost: previewForm.cost,
      rule_type: draft?.rule_type ?? '',
      value: draft === undefined ? '' : draft.rule_type === 'percentage' ? rateOf(draft.value) : draft.value,
    })
  } finally {
    previewing.value = false
  }
}

function ruleSummary(rule: PricingRule): string {
  if (rule.rule_type === null || rule.value === null) {
    return '未设置'
  }
  return rule.rule_type === 'fixed' ? `成本 + ${rule.value} 元` : `成本 × (1 + ${percentOf(rule.value)}%)`
}

onMounted(load)
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <template #header>加价规则</template>
      <el-alert type="info" :closable="false" show-icon class="tip">
        电影票按场次成本、快递按<strong>运费</strong>成本加价，所有商户统一；保价费、耗材费、逆向费按成本转给商户，不加价。
        百分比算出的售价四舍五入到分。改规则只影响之后下的订单，已下单的订单存的是售价快照。
      </el-alert>

      <el-row :gutter="16">
        <el-col v-for="rule in rules" :key="rule.business_line" :span="12">
          <el-card shadow="never" class="rule-card">
            <template #header>
              <div class="rule-header">
                <span>{{ labelOf(businessLineLabels, rule.business_line) }}</span>
                <span class="muted">当前：{{ ruleSummary(rule) }}</span>
              </div>
            </template>

            <el-form label-width="80px">
              <el-form-item label="加价方式">
                <el-radio-group v-model="drafts[rule.business_line].rule_type" :disabled="!canManage">
                  <el-radio v-for="o in typeOptions" :key="o.value" :value="o.value">{{ o.label }}</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-form-item :label="drafts[rule.business_line].rule_type === 'fixed' ? '加价金额' : '加价比例'">
                <el-input
                  v-model="drafts[rule.business_line].value"
                  :disabled="!canManage"
                  style="width: 200px"
                  :placeholder="drafts[rule.business_line].rule_type === 'fixed' ? '如 2' : '如 5'"
                >
                  <template #append>{{ drafts[rule.business_line].rule_type === 'fixed' ? '元' : '%' }}</template>
                </el-input>
              </el-form-item>
              <el-form-item v-if="rule.updated_by" label="最后修改">
                <span class="muted">{{ rule.updated_by }} · {{ rule.updated_at }}</span>
              </el-form-item>
              <el-form-item v-if="canManage">
                <el-button type="primary" :loading="saving === rule.business_line" @click="save(rule.business_line)">
                  保存
                </el-button>
              </el-form-item>
            </el-form>
          </el-card>
        </el-col>
      </el-row>
    </el-card>

    <el-card shadow="never" class="gap-top">
      <template #header>价格预览</template>
      <el-alert type="info" :closable="false" show-icon class="tip">
        按<strong>当前编辑中</strong>的规则试算，不用先保存。快递填运费成本。
      </el-alert>
      <el-form inline @submit.prevent="runPreview">
        <el-form-item label="业务线">
          <el-select v-model="previewForm.business_line" style="width: 130px">
            <el-option v-for="rule in rules" :key="rule.business_line" :value="rule.business_line" :label="labelOf(businessLineLabels, rule.business_line)" />
          </el-select>
        </el-form-item>
        <el-form-item label="成本">
          <el-input v-model="previewForm.cost" style="width: 140px" placeholder="如 10.00">
            <template #append>元</template>
          </el-input>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" native-type="submit" :loading="previewing">试算</el-button>
        </el-form-item>
      </el-form>

      <el-descriptions v-if="preview" :column="4" border>
        <el-descriptions-item label="成本">{{ preview.cost }}</el-descriptions-item>
        <el-descriptions-item label="加价方式">
          {{ labelOf(pricingRuleTypeLabels, preview.rule_type) }}
          {{ preview.rule_type === 'fixed' ? preview.value + ' 元' : Number(preview.value) * 100 + '%' }}
        </el-descriptions-item>
        <el-descriptions-item label="售价">
          <strong>{{ preview.sale_price }}</strong>
        </el-descriptions-item>
        <el-descriptions-item label="毛利">{{ preview.gross_profit }}</el-descriptions-item>
      </el-descriptions>
    </el-card>
  </div>
</template>

<style scoped>
.tip {
  margin-bottom: 16px;
}

.rule-card {
  margin-bottom: 8px;
}

.rule-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.gap-top {
  margin-top: 16px;
}

.muted {
  color: #909399;
  font-size: 13px;
}
</style>
