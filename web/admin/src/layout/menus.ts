import { Box, Goods, HomeFilled, List, Money, OfficeBuilding, Rank, Service, Shop, Tickets, User } from '@element-plus/icons-vue'
import type { MenuItem } from '@platform/shared'

export const menus: MenuItem[] = [
  { path: '/dashboard', title: '首页', icon: HomeFilled },
  {
    path: '/merchant',
    title: '商户管理',
    icon: Shop,
    children: [
      { path: '/merchants', title: '商户列表', icon: User },
      { path: '/recharge-requests', title: '充值审核', icon: Money },
      { path: '/merchant-levels', title: '商户等级', icon: Rank },
    ],
  },
  {
    path: '/goods',
    title: '商品与供应商',
    icon: Goods,
    children: [
      { path: '/products', title: '本地商品', icon: Box },
      { path: '/suppliers', title: '供应商', icon: OfficeBuilding },
    ],
  },
  {
    path: '/order',
    title: '订单管理',
    icon: List,
    children: [
      { path: '/orders', title: '订单列表', icon: Tickets },
      { path: '/disputes', title: '售后争议', icon: Service },
    ],
  },
]
