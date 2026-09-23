import { computed, onMounted, watch } from 'vue'
import { localeFromDomain, setLocale, type DomainLocale, type SupportedLocale } from '@/i18n'
import { useTenantBranding } from '@/tenant/useTenantBranding'
import { getCsrfCookie } from '../api'

/**
 * Común a las cinco pantallas públicas de `REQ-AUTH` (`funcional.md
 * §1.6`): resuelve el idioma de la pantalla como Accept-Language ∩
 * idiomas activos del centro, degradando al idioma por defecto del
 * centro si ninguno coincide (`CA-AUTH-063`) — no hay usuario del que
 * leer una preferencia propia en ninguna de las cinco.
 *
 * `docs/design-system.md` §13.2: el *branding* ya no lo pide este
 * *composable* — lo lee de la capa B (`useTenantBranding`, `@/tenant`),
 * que es la única llamadora de `getTenantBranding()` en la SPA
 * (`RN-DS-22`). `main.ts` ya ha lanzado `bootstrapTenantBranding()` antes
 * de montar, así que lo normal es que `status` ya esté resuelto al
 * llegar aquí; si no lo está (venció el plazo de `BRANDING_BOOT_TIMEOUT_MS`
 * antes de que respondiera la API), se vigila hasta la primera
 * transición y se desactiva la vigilancia.
 *
 * También dispara `GET /auth/csrf-cookie` (api.md §2). Decisión de dónde
 * llamarlo (candidatos: arranque global de la SPA en `main.ts`, o cada
 * pantalla pública): aquí, al entrar en cada una de estas cinco. Son
 * estas pantallas, y solo ellas, las que hacen la primera escritura
 * anónima de una sesión de navegador — el resto de la SPA hasta 1.8 no
 * escribe nada sin sesión ya iniciada (que ya trae su propia cookie CSRF
 * del login) — así que sembrarla en el arranque global pagaría el coste
 * en cada carga de la aplicación, incluida la de un usuario ya
 * autenticado que nunca la necesita.
 */
export function usePublicAuthScreen() {
  const { branding, status } = useTenantBranding()

  function applyLocale(): void {
    if (!branding.value) {
      return
    }

    setLocale(resolveTenantLocale(branding.value.default_locale, branding.value.active_locales))
  }

  onMounted(() => {
    void getCsrfCookie().catch(() => {
      // No bloquea el pintado del formulario: si de verdad falla, el
      // envío posterior fallará con 403/419 y se muestra como cualquier
      // otro error del servidor — no hace falta duplicar el manejo aquí.
    })

    if (status.value !== 'loading') {
      if (status.value === 'ready') {
        applyLocale()
      }
      return
    }

    const stopWatching = watch(status, (value) => {
      if (value === 'loading') {
        return
      }

      stopWatching()

      if (value === 'ready') {
        applyLocale()
      }
    })
  })

  const brandingFailed = computed(
    () => status.value === 'not-found' || status.value === 'unavailable',
  )

  return { branding, brandingFailed }
}

function resolveTenantLocale(
  defaultLocale: DomainLocale,
  activeLocales: readonly DomainLocale[],
): SupportedLocale {
  const active = new Set(activeLocales.map((locale) => localeFromDomain(locale)))

  for (const candidate of navigator.languages ?? [navigator.language]) {
    const primary = candidate.split('-')[0]?.toLowerCase()

    if (primary && active.has(primary as SupportedLocale)) {
      return primary as SupportedLocale
    }
  }

  return localeFromDomain(defaultLocale)
}
