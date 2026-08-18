import { createApp } from 'vue'
import './style.css'
import App from './App.vue'
import router from './router'
import { i18n } from './i18n'

document.documentElement.lang = i18n.global.locale.value

createApp(App).use(router).use(i18n).mount('#app')
