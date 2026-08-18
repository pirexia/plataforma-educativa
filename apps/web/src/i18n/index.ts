import { createI18n } from 'vue-i18n'
import es from './locales/es.json'
import en from './locales/en.json'
import de from './locales/de.json'
import fr from './locales/fr.json'

/**
 * ADR-021/INV-009: es (por defecto), en, de, fr. `es` es el único
 * `fallbackLocale` — si falta una clave en otro idioma, se ve en español
 * antes que en blanco, nunca la clave cruda.
 */
export const SUPPORTED_LOCALES = ['es', 'en', 'de', 'fr'] as const

export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number]

export const DEFAULT_LOCALE: SupportedLocale = 'es'

const STORAGE_KEY = 'plataforma.locale'

function isSupportedLocale(value: string | null | undefined): value is SupportedLocale {
  return SUPPORTED_LOCALES.includes(value as SupportedLocale)
}

/**
 * Preferencia persistida (localStorage, hasta que exista sesión de
 * usuario con preferencia propia en REQ-AUTH/1.2) > negociación del
 * navegador > es. Nunca lanza: un valor inesperado cae al valor por
 * defecto en vez de romper el arranque de la SPA.
 */
export function resolveInitialLocale(): SupportedLocale {
  const stored = window.localStorage.getItem(STORAGE_KEY)

  if (isSupportedLocale(stored)) {
    return stored
  }

  for (const candidate of navigator.languages ?? [navigator.language]) {
    const primaryTag = candidate.split('-')[0]?.toLowerCase()

    if (isSupportedLocale(primaryTag)) {
      return primaryTag
    }
  }

  return DEFAULT_LOCALE
}

export function persistLocale(locale: SupportedLocale): void {
  window.localStorage.setItem(STORAGE_KEY, locale)
}

export const i18n = createI18n({
  legacy: false,
  locale: resolveInitialLocale(),
  fallbackLocale: DEFAULT_LOCALE,
  messages: { es, en, de, fr },
})

export function setLocale(locale: SupportedLocale): void {
  i18n.global.locale.value = locale
  persistLocale(locale)
  document.documentElement.lang = locale
}
