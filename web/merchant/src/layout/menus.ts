import { Coin, Document, HomeFilled, Key, List, Money, Postcard, Service, Tickets, Wallet } from '@element-plus/icons-vue'
import type { MenuItem } from '@platform/shared'

export const menus: MenuItem[] = [
  { path: '/dashboard', title: '首页', icon: HomeFilled },
  {
    path: '/order',
    title: '订单管理',
    icon: List,
    children: [
      { path: '/orders', title: '订单列表', icon: Tickets },
      { path: '/disputes', title: '售后争议', icon: Service },
    ],
  },
  {
    path: '/finance',
    title: '资金',
    icon: Wallet,
    children: [
      { path: '/finance/recharge', title: '充值申请', icon: Money },
      { path: '/finance/balance-logs', title: '资金流水', icon: Document },
      { path: '/finance/rebates', title: '返佣明细', icon: Coin },
    ],
  },
  { path: '/qualification', title: '资质资料', icon: Postcard },
  { path: '/dev-settings', title: '开发设置', icon: Key },
]
