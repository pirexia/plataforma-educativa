import { createApp } from 'vue'
import './style.css'
import App from './App.vue'
import router from './router'
import { i18n } from './i18n'
import { initColorScheme } from './design-system/color-mode/useColorScheme'
import { bootstrapTenantBranding, primeBrandingFromCache } from './tenant/useTenantBranding'

/**
 * `docs/design-system.md` §8. Orden obligatorio (`RN-DS-09`):
 * 1. `initColorScheme()` — síncrono, pone `.light`/`.dark` en `<html>`.
 * 2. `primeBrandingFromCache()` — síncrono, paleta de la visita anterior,
 *    sin destello.
 * 3. `document.documentElement.lang` (como hoy).
 * 4. `bootstrapTenantBranding()` y, en su continuación, montar la SPA.
 *
 * Sin `await` de nivel superior a propósito (una promesa encadenada, no
 * depende del *target* de compilación). `bootstrapTenantBranding` nunca
 * rechaza: un fallo de la petición no puede impedir montar la SPA.
 */
initColorScheme()
primeBrandingFromCache()

document.documentElement.lang = i18n.global.locale.value

bootstrapTenantBranding().then(() => {
  createApp(App).use(router).use(i18n).mount('#app')
})
