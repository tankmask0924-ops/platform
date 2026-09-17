import { computed, ref } from 'vue'
import { levelApi, type MerchantLevel } from '@/api/admin'

const levels = ref<MerchantLevel[]>([])
let pending: Promise<void> | null = null

/** 商户等级是少量配置数据，页面间共用一份；增改等级后调用 reload() */
export function useLevels() {
  function reload(): Promise<void> {
    pending = levelApi.list().then((result) => {
      levels.value = result.data
    })
    pending.catch(() => {
      pending = null
    })
    return pending
  }

  function ensure(): Promise<void> {
    return pending ?? reload()
  }

  const levelName = computed(() => (id: number | null | undefined) => {
    if (id === null || id === undefined) {
      return '-'
    }
    return levels.value.find((level) => level.id === id)?.name ?? `#${id}`
  })

  return { levels, ensure, reload, levelName }
}
