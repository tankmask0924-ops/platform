import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

/**
 * 创建登录态 store。
 * 两个后台部署在同一域名下时共用 localStorage，所以用 id 区分存储 key，避免互相覆盖登录态。
 */
export function defineAuthStore(id: string) {
  const tokenKey = `${id}:token`
  const usernameKey = `${id}:username`

  return defineStore(id, () => {
    const token = ref<string | null>(localStorage.getItem(tokenKey))
    const username = ref(localStorage.getItem(usernameKey) ?? '')
    const isLoggedIn = computed(() => token.value !== null)

    function setSession(newToken: string, name: string) {
      token.value = newToken
      username.value = name
      localStorage.setItem(tokenKey, newToken)
      localStorage.setItem(usernameKey, name)
    }

    function clear() {
      token.value = null
      username.value = ''
      localStorage.removeItem(tokenKey)
      localStorage.removeItem(usernameKey)
    }

    return { token, username, isLoggedIn, setSession, clear }
  })
}
