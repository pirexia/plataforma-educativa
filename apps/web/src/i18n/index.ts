import { createI18n, useI18n, type Composer } from 'vue-i18n'
import es from './locales/es.json'
import en from './locales/en.json'
import de from './locales/de.json'
import fr from './locales/fr.json'
import coreEs from '@/modules/core/locales/es.json'
import coreEn from '@/modules/core/locales/en.json'
import coreDe from '@/modules/core/locales/de.json'
import coreFr from '@/modules/core/locales/fr.json'
import authEs from '@/modules/auth/locales/es.json'
import authEn from '@/modules/auth/locales/en.json'
import authDe from '@/modules/auth/locales/de.json'
import authFr from '@/modules/auth/locales/fr.json'

/**
 * ADR-021/INV-009: es (por defecto), en, de, fr. `es` es el único
 * `fallbackLocale` — si falta una clave en otro idioma, se ve en español
 * antes que en blanco, nunca la clave cruda.
 */
export const SUPPORTED_LOCALES = ['es', 'en', 'de', 'fr'] as const

export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number]

export const DEFAULT_LOCALE: SupportedLocale = 'es'

/**
 * REQ-AUTH (1.2): el vocabulario de idioma del dominio —
 * `tenant_settings.default_locale`/`active_locales`, `Person.locale`
 * (ADR-021: `'es-ES' | 'en' | 'de' | 'fr'`)— no coincide con el código de
 * `vue-i18n` para el español (`'es'`, no `'es-ES'`). Único punto de
 * conversión entre ambos vocabularios; nace aquí porque es el primer
 * consumidor que necesita cruzar el idioma de un recurso de la API con el
 * catálogo de mensajes de la SPA (branding público, antes de que haya
 * sesión de la que leer `person.locale`).
 */
export type DomainLocale = 'es-ES' | 'en' | 'de' | 'fr'

export function localeFromDomain(value: DomainLocale): SupportedLocale {
  return value === 'es-ES' ? 'es' : value
}

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

/**
 * Cada módulo aporta su propio `locales/{es,en,de,fr}.json`
 * (funcional.md §1.11 de REQ-CORE); este es el único punto donde se
 * ensamblan en el catálogo global de `vue-i18n`, bajo su propio espacio
 * de nombres (`core`) para que no colisione con el de otro módulo.
 */
export const i18n = createI18n({
  legacy: false,
  locale: resolveInitialLocale(),
  fallbackLocale: DEFAULT_LOCALE,
  messages: {
    es: { ...es, ...coreEs, ...authEs },
    en: { ...en, ...coreEn, ...authEn },
    de: { ...de, ...coreDe, ...authDe },
    fr: { ...fr, ...coreFr, ...authFr },
  },
})

export function setLocale(locale: SupportedLocale): void {
  i18n.global.locale.value = locale
  persistLocale(locale)
  document.documentElement.lang = locale
}

/**
 * Único punto de contacto de un componente con `vue-i18n` (`RNF-MANT-007`,
 * `CLAUDE.md §1`: toda dependencia externa se envuelve tras una interfaz
 * propia). No reimplementa el *composable* — sería reescribir `vue-i18n`
 * entero para una envoltura sin valor — pero sí es el único sitio del que
 * un componente importa: si la librería se sustituyera, este fichero es
 * el único que cambiaría, ningún componente lo notaría.
 */
export function useT(): Composer['t'] {
  const { t } = useI18n()

  return t
}
