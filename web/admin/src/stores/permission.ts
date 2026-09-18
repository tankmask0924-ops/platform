import { defineStore } from 'pinia'
import { ref } from 'vue'
import { fetchMe, type Me } from '@/api/auth'

/** 当前管理员的权限编码，进入后台时加载一次；换账号登录后要 reset() */
export const usePermissionStore = defineStore('admin-permission', () => {
  const me = ref<Me | null>(null)
  let pending: Promise<Me> | null = null

  async function load(): Promise<Me> {
    if (me.value) {
      return me.value
    }
    pending ??= fetchMe().finally(() => {
      pending = null
    })
    me.value = await pending
    return me.value
  }

  function can(permission: string): boolean {
    return me.value?.permissions.includes(permission) ?? false
  }

  function reset() {
    me.value = null
  }

  return { me, load, can, reset }
})
