import {
  Box,
  Coin,
  Document,
  Goods,
  HomeFilled,
  Key,
  List,
  Money,
  OfficeBuilding,
  Operation,
  Rank,
  Service,
  Setting,
  Shop,
  Tickets,
  User,
  UserFilled,
} from '@element-plus/icons-vue'
import type { MenuItem } from '@platform/shared'

/** permission 对应后端 #[RequiresPermission] 的查看权限，没有权限的菜单不显示 */
export const menus: MenuItem[] = [
  { path: '/dashboard', title: '首页', icon: HomeFilled },
  {
    path: '/merchant',
    title: '商户管理',
    icon: Shop,
    children: [
      { path: '/merchants', title: '商户列表', icon: User, permission: 'merchant.view' },
      { path: '/recharge-requests', title: '充值审核', icon: Money, permission: 'recharge.view' },
      { path: '/merchant-levels', title: '商户等级', icon: Rank, permission: 'merchant_level.view' },
      { path: '/rebates', title: '返佣明细', icon: Coin, permission: 'rebate.view' },
    ],
  },
  {
    path: '/goods',
    title: '商品与供应商',
    icon: Goods,
    children: [
      { path: '/products', title: '本地商品', icon: Box, permission: 'product.view' },
      { path: '/suppliers', title: '供应商', icon: OfficeBuilding, permission: 'supplier.view' },
    ],
  },
  {
    path: '/order',
    title: '订单管理',
    icon: List,
    children: [
      { path: '/orders', title: '订单列表', icon: Tickets, permission: 'order.view' },
      { path: '/disputes', title: '售后争议', icon: Service, permission: 'aftersale.view' },
    ],
  },
  {
    path: '/system',
    title: '系统设置',
    icon: Setting,
    children: [
      { path: '/system/admin-users', title: '管理员账号', icon: UserFilled, permission: 'admin_user.view' },
      { path: '/system/roles', title: '角色权限', icon: Key, permission: 'role.view' },
      { path: '/system/settings', title: '系统参数', icon: Operation, permission: 'setting.view' },
      { path: '/system/operation-logs', title: '操作日志', icon: Document, permission: 'operation_log.view' },
    ],
  },
]
