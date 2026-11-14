/**
 * Idiomas del centro para el contenido traducible (`ADR-062 §5.2`, `§5.7`):
 * `default_locale` y `active_locales` de la marca pública del centro
 * (`useTenantBranding`, único llamador de `GET /tenant/branding`, `RN-DS-22`).
 * Mientras la marca no llega, se asume solo `es-ES` (el valor por defecto del
 * producto, `ADR-021`); el servidor valida igualmente (`INV-010`).
 */
import { computed } from 'vue'
import type { Locale } from '@/modules/core/types'
import { orderedLocales } from '@/lib/translatable'
import { useTenantBranding } from './useTenantBranding'

export function useTenantLocales() {
  const { branding } = useTenantBranding()

  const defaultLocale = computed<Locale>(() => branding.value?.default_locale ?? 'es-ES')
  const activeLocales = computed<Locale[]>(() => branding.value?.active_locales ?? ['es-ES'])
  /** El idioma por defecto primero. */
  const locales = computed<Locale[]>(() => orderedLocales(defaultLocale.value, activeLocales.value))

  return { defaultLocale, activeLocales, locales }
}
