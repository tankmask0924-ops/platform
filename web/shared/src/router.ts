import type { Router } from 'vue-router'

declare module 'vue-router' {
  interface RouteMeta {
    /** 显示在顶栏和浏览器标题 */
    title?: string
    /** 无需登录即可访问 */
    public?: boolean
  }
}

export interface GuardOptions {
  appTitle: string
  isLoggedIn: () => boolean
  loginRouteName?: string
}

export function setupRouterGuards(router: Router, options: GuardOptions): void {
  const loginRouteName = options.loginRouteName ?? 'login'

  router.beforeEach((to) => {
    if (to.meta.public) {
      // 已登录时访问登录页，直接回首页
      if (to.name === loginRouteName && options.isLoggedIn()) {
        return { path: '/' }
      }
      return true
    }
    if (!options.isLoggedIn()) {
      return { name: loginRouteName, query: { redirect: to.fullPath } }
    }
    return true
  })

  router.afterEach((to) => {
    document.title = to.meta.title ? `${to.meta.title} - ${options.appTitle}` : options.appTitle
  })
}
