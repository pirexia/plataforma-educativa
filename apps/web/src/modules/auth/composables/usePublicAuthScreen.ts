import { onMounted, ref } from 'vue'
import { getTenantBranding } from '@/modules/core/api'
import { localeFromDomain, setLocale, type DomainLocale, type SupportedLocale } from '@/i18n'
import { getCsrfCookie } from '../api'

type Branding = Awaited<ReturnType<typeof getTenantBranding>>

/**
 * Común a las cinco pantallas públicas de `REQ-AUTH` (`funcional.md
 * §1.6`): carga el branding del centro (nombre, colores, logo, fondo —
 * `RUX-BRAND-002`/`004`) y resuelve el idioma de la pantalla como
 * Accept-Language ∩ idiomas activos del centro, degradando al idioma por
 * defecto del centro si ninguno coincide (`CA-AUTH-063`) — no hay usuario
 * del que leer una preferencia propia en ninguna de las cinco.
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
  const branding = ref<Branding | null>(null)
  const brandingFailed = ref(false)

  onMounted(async () => {
    void getCsrfCookie().catch(() => {
      // No bloquea el pintado del formulario: si de verdad falla, el
      // envío posterior fallará con 403/419 y se muestra como cualquier
      // otro error del servidor — no hace falta duplicar el manejo aquí.
    })

    try {
      branding.value = await getTenantBranding()
      setLocale(resolveTenantLocale(branding.value.default_locale, branding.value.active_locales))
    } catch {
      brandingFailed.value = true
    }
  })

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
