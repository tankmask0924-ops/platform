import type { Component } from 'vue'

export interface MenuItem {
  /** 路由路径；有 children 时仅作为分组标识 */
  path: string
  title: string
  icon?: Component
  children?: MenuItem[]
}

export interface LoginForm {
  username: string
  password: string
}
