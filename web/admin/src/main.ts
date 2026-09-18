import ElementPlus from 'element-plus'
import 'element-plus/dist/index.css'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import { ignoreHandledHttpErrors } from '@platform/shared'
import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'
import router from './router'

const app = createApp(App).use(createPinia()).use(router).use(ElementPlus, { locale: zhCn })
ignoreHandledHttpErrors(app)
app.mount('#app')
