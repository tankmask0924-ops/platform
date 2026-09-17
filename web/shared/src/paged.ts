import { reactive, ref, type Ref } from 'vue'

/** 后台列表接口的统一分页结构：{data, total, page, per_page} */
export interface Paged<T> {
  data: T[]
  total: number
  page: number
  per_page: number
}

export interface PagedList<T, F> {
  rows: Ref<T[]>
  total: Ref<number>
  page: Ref<number>
  perPage: Ref<number>
  loading: Ref<boolean>
  filters: F
  /** 按当前页和筛选条件重新加载 */
  load: () => Promise<void>
  /** 筛选条件变了：回到第一页再加载 */
  search: () => Promise<void>
}

/**
 * 分页列表的通用状态。筛选条件里的空字符串 / null 不会发给后端
 * （后端大多把空值当"不筛选"，但有的会对非法值报 422，干脆不传）。
 */
export function usePagedList<T, F extends object>(
  fetcher: (params: Record<string, unknown>) => Promise<Paged<T>>,
  initialFilters: F,
  perPageDefault = 15,
): PagedList<T, F> {
  const rows = ref([]) as Ref<T[]>
  const total = ref(0)
  const page = ref(1)
  const perPage = ref(perPageDefault)
  const loading = ref(false)
  const filters = reactive({ ...initialFilters }) as F

  async function load() {
    loading.value = true
    try {
      const params: Record<string, unknown> = { page: page.value, per_page: perPage.value }
      for (const [key, value] of Object.entries(filters)) {
        if (value !== '' && value !== null && value !== undefined) {
          params[key] = value
        }
      }
      const result = await fetcher(params)
      rows.value = result.data
      total.value = result.total
    } finally {
      loading.value = false
    }
  }

  async function search() {
    page.value = 1
    await load()
  }

  return { rows, total, page, perPage, loading, filters, load, search }
}
