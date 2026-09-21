import { setupRouterGuards } from '@platform/shared'
import { createRouter, createWebHistory } from 'vue-router'
import Layout from '@/layout/index.vue'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { title: '登录', public: true },
    },
    {
      path: '/',
      component: Layout,
      redirect: '/dashboard',
      children: [
        {
          path: 'dashboard',
          name: 'dashboard',
          component: () => import('@/views/DashboardView.vue'),
          meta: { title: '首页' },
        },
        {
          path: 'merchants',
          name: 'merchants',
          component: () => import('@/views/merchant/MerchantListView.vue'),
          meta: { title: '商户列表' },
        },
        {
          path: 'merchants/:id(\\d+)',
          name: 'merchant-detail',
          component: () => import('@/views/merchant/MerchantDetailView.vue'),
          meta: { title: '商户详情' },
        },
        {
          path: 'recharge-requests',
          name: 'recharge-review',
          component: () => import('@/views/merchant/RechargeReviewView.vue'),
          meta: { title: '充值审核' },
        },
        {
          path: 'merchant-levels',
          name: 'merchant-levels',
          component: () => import('@/views/merchant/MerchantLevelView.vue'),
          meta: { title: '商户等级' },
        },
        {
          path: 'subscriptions',
          name: 'subscriptions',
          component: () => import('@/views/merchant/SubscriptionReviewView.vue'),
          meta: { title: '开通审核' },
        },
        {
          path: 'rebates',
          name: 'rebates',
          component: () => import('@/views/merchant/RebateListView.vue'),
          meta: { title: '返佣明细' },
        },
        {
          path: 'products',
          name: 'products',
          component: () => import('@/views/product/ProductListView.vue'),
          meta: { title: '本地商品' },
        },
        {
          path: 'products/:id(\\d+)',
          name: 'product-detail',
          component: () => import('@/views/product/ProductDetailView.vue'),
          meta: { title: '商品详情' },
        },
        {
          path: 'suppliers',
          name: 'suppliers',
          component: () => import('@/views/supplier/SupplierListView.vue'),
          meta: { title: '供应商' },
        },
        {
          path: 'suppliers/:id(\\d+)',
          name: 'supplier-detail',
          component: () => import('@/views/supplier/SupplierDetailView.vue'),
          meta: { title: '供应商详情' },
        },
        {
          path: 'orders',
          name: 'orders',
          component: () => import('@/views/order/OrderListView.vue'),
          meta: { title: '订单列表' },
        },
        {
          path: 'orders/:id(\\d+)',
          name: 'order-detail',
          component: () => import('@/views/order/OrderDetailView.vue'),
          meta: { title: '订单详情' },
        },
        {
          path: 'disputes',
          name: 'disputes',
          component: () => import('@/views/order/DisputeListView.vue'),
          meta: { title: '售后争议' },
        },
        {
          path: 'pricing-rules',
          name: 'pricing-rules',
          component: () => import('@/views/product/PricingRuleView.vue'),
          meta: { title: '加价规则' },
        },
        {
          path: 'finance-report',
          name: 'finance-report',
          component: () => import('@/views/FinanceReportView.vue'),
          meta: { title: '财务报表' },
        },
        {
          path: 'reconciliations',
          name: 'reconciliations',
          component: () => import('@/views/ReconciliationListView.vue'),
          meta: { title: '对账' },
        },
        {
          path: 'alerts',
          name: 'alerts',
          component: () => import('@/views/AlertListView.vue'),
          meta: { title: '告警' },
        },
        {
          path: 'system/admin-users',
          name: 'admin-users',
          component: () => import('@/views/system/AdminUserListView.vue'),
          meta: { title: '管理员账号' },
        },
        {
          path: 'system/roles',
          name: 'roles',
          component: () => import('@/views/system/RoleListView.vue'),
          meta: { title: '角色权限' },
        },
        {
          path: 'system/settings',
          name: 'settings',
          component: () => import('@/views/system/SettingListView.vue'),
          meta: { title: '系统参数' },
        },
        {
          path: 'system/operation-logs',
          name: 'operation-logs',
          component: () => import('@/views/system/OperationLogView.vue'),
          meta: { title: '操作日志' },
        },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

setupRouterGuards(router, {
  appTitle: import.meta.env.VITE_APP_TITLE,
  isLoggedIn: () => useAuthStore().isLoggedIn,
})

export default router
