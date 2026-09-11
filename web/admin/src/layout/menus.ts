import { HomeFilled, Setting, User } from '@element-plus/icons-vue'
import type { MenuItem } from '@platform/shared'

export const menus: MenuItem[] = [
  { path: '/dashboard', title: '首页', icon: HomeFilled },
  {
    path: '/system',
    title: '系统管理',
    icon: Setting,
    children: [{ path: '/system/users', title: '用户管理', icon: User }],
  },
]
