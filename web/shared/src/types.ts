import type { Component } from 'vue'

export interface MenuItem {
  /** 路由路径；有 children 时仅作为分组标识 */
  path: string
  title: string
  icon?: Component
  /** 需要的权限编码，没有这个权限时不显示；不填表示登录即可见 */
  permission?: string
  children?: MenuItem[]
}

export interface LoginForm {
  username: string
  password: string
}
