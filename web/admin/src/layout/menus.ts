import {
  Bell,
  Box,
  Checked,
  Coin,
  Document,
  Finished,
  Histogram,
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
      { path: '/subscriptions', title: '开通审核', icon: Checked, permission: 'subscription.view' },
      { path: '/recharge-requests', title: '充值审核', icon: Money, permission: 'recharge.view' },
      { path: '/merchant-levels', title: '商户等级', icon: Rank, permission: 'merchant_level.view' },
      { path: '/rebates', title: '商户返佣', icon: Coin, permission: 'rebate.view' },
      { path: '/supplier-rebates', title: '供应商返佣', icon: Coin, permission: 'rebate.view' },
    ],
  },
  {
    path: '/goods',
    title: '商品与供应商',
    icon: Goods,
    children: [
      { path: '/products', title: '本地商品', icon: Box, permission: 'product.view' },
      { path: '/suppliers', title: '供应商', icon: OfficeBuilding, permission: 'supplier.view' },
      { path: '/pricing-rules', title: '加价规则', icon: Money, permission: 'pricing.view' },
    ],
  },
  {
    path: '/order',
    title: '订单管理',
    icon: List,
    children: [
      { path: '/orders', title: '订单列表', icon: Tickets, permission: 'order.view' },
      { path: '/disputes', title: '售后争议', icon: Service, permission: 'aftersale.view' },
      { path: '/express-workorders', title: '快递工单', icon: Service, permission: 'aftersale.view' },
    ],
  },
  // 财务报表跟对账、告警一样放顶层：它横跨订单、返佣、资金三块，挂在任何一个下面都不对
  { path: '/finance-report', title: '财务报表', icon: Histogram, permission: 'report.view' },
  // 对账跟告警一样放顶层：它既不属于订单模块也不属于供应商模块，
  // 对的是两边的差异，而且是财务每天固定要看的一页
  { path: '/reconciliations', title: '对账', icon: Finished, permission: 'reconciliation.view' },
  // 告警放顶层而不是塞进某个模块：7 类告警分别指向供应商、商品、商户、订单，
  // 挂在任何一个模块下都会显得它只跟那个模块有关
  { path: '/alerts', title: '告警', icon: Bell, permission: 'alert.view' },
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
