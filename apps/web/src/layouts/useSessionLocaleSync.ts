/**
 * `docs/modulos/REQ-CORE/funcional.md §12.3.6` punto 1, `RN-CORE-34`,
 * `CA-CORE-122`. Con sesión: `person.locale` si pertenece a los
 * `active_locales` del centro; si no, el `default_locale` del centro. La
 * preferencia almacenada **no se modifica** — nunca llama a `PATCH /me`,
 * a diferencia del selector de idioma (`AppUserMenu.vue`).
 */
import { watch } from 'vue'
import { localeFromDomain, setLocale, type DomainLocale } from '@/i18n'
import { useSession } from '@/session/useSession'
import { useTenantBranding } from '@/tenant/useTenantBranding'

export function useSessionLocaleSync(): void {
  const { user } = useSession()
  const { branding } = useTenantBranding()

  watch(
    [user, branding],
    ([currentUser, currentBranding]) => {
      if (!currentUser || !currentBranding) {
        return
      }

      const personLocale = currentUser.person.locale as DomainLocale
      const activeLocales = currentBranding.active_locales as DomainLocale[]
      const targetDomainLocale = activeLocales.includes(personLocale)
        ? personLocale
        : (currentBranding.default_locale as DomainLocale)

      setLocale(localeFromDomain(targetDomainLocale))
    },
    { immediate: true },
  )
}
