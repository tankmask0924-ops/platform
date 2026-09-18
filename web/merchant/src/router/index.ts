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
      path: '/register',
      name: 'register',
      component: () => import('@/views/RegisterView.vue'),
      meta: { title: '商户注册', public: true },
    },
    {
      path: '/forgot-password',
      name: 'forgot-password',
      component: () => import('@/views/ForgotPasswordView.vue'),
      meta: { title: '找回密码', public: true },
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
          path: 'orders',
          name: 'orders',
          component: () => import('@/views/order/OrderListView.vue'),
          meta: { title: '订单列表' },
        },
        {
          path: 'disputes',
          name: 'disputes',
          component: () => import('@/views/order/DisputeListView.vue'),
          meta: { title: '售后争议' },
        },
        {
          path: 'finance/recharge',
          name: 'recharge',
          component: () => import('@/views/finance/RechargeView.vue'),
          meta: { title: '充值申请' },
        },
        {
          path: 'finance/balance-logs',
          name: 'balance-logs',
          component: () => import('@/views/finance/BalanceLogView.vue'),
          meta: { title: '资金流水' },
        },
        {
          path: 'finance/rebates',
          name: 'rebates',
          component: () => import('@/views/finance/RebateView.vue'),
          meta: { title: '返佣明细' },
        },
        {
          path: 'products',
          name: 'products',
          component: () => import('@/views/ProductPriceView.vue'),
          meta: { title: '商品价格' },
        },
        {
          path: 'services',
          name: 'services',
          component: () => import('@/views/ServiceView.vue'),
          meta: { title: '服务开通' },
        },
        {
          path: 'qualification',
          name: 'qualification',
          component: () => import('@/views/QualificationView.vue'),
          meta: { title: '资质资料' },
        },
        {
          path: 'dev-settings',
          name: 'dev-settings',
          component: () => import('@/views/DevSettingsView.vue'),
          meta: { title: '开发设置' },
        },
        {
          path: 'api-docs',
          name: 'api-docs',
          component: () => import('@/views/ApiDocView.vue'),
          meta: { title: '接口文档' },
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
