/**
 * `docs/modulos/REQ-CORE/funcional.md §12.11`, `CA-CORE-150`. Mismo
 * patrón que `src/modules/auth/locales/sso.i18n.spec.ts` (1.4b,
 * `CA-AUTH-309`): toda clave nueva de 1.8 existe en los cuatro idiomas,
 * sin valores vacíos ni copiados literalmente del español (salvo los
 * autónimos de idioma, que son iguales a propósito).
 */
import { describe, expect, it } from 'vitest'
import es from './i18n/locales/es.json'
import en from './i18n/locales/en.json'
import de from './i18n/locales/de.json'
import fr from './i18n/locales/fr.json'
import coreEs from './modules/core/locales/es.json'
import coreEn from './modules/core/locales/en.json'
import coreDe from './modules/core/locales/de.json'
import coreFr from './modules/core/locales/fr.json'
import authEs from './modules/auth/locales/es.json'
import authEn from './modules/auth/locales/en.json'
import authDe from './modules/auth/locales/de.json'
import authFr from './modules/auth/locales/fr.json'

type Json = Record<string, unknown>

function flatten(node: unknown, prefix = ''): Map<string, unknown> {
  const out = new Map<string, unknown>()

  if (node !== null && typeof node === 'object' && !Array.isArray(node)) {
    for (const [key, value] of Object.entries(node as Json)) {
      const path = prefix ? `${prefix}.${key}` : key
      for (const [k, v] of flatten(value, path)) {
        out.set(k, v)
      }
    }
  } else {
    out.set(prefix, node)
  }

  return out
}

const locales = {
  es: { ...es, ...coreEs, ...authEs } as Json,
  en: { ...en, ...coreEn, ...authEn } as Json,
  de: { ...de, ...coreDe, ...authDe } as Json,
  fr: { ...fr, ...coreFr, ...authFr } as Json,
}

const PATHS = ['shell', 'home', 'core.nav', 'auth.nav']

// Autónimos de idioma (§12.3.6 punto 3): el nombre de un idioma en su
// propia lengua es igual en los cuatro catálogos a propósito.
const EXPECTED_IDENTICAL_ACROSS_LOCALES = new Set([
  'userMenu.language.names.esES',
  'userMenu.language.names.en',
  'userMenu.language.names.de',
  'userMenu.language.names.fr',
  'documentTitleFormat',
])

function subtree(root: Json, path: string): unknown {
  return path.split('.').reduce<unknown>((acc, key) => (acc as Json | undefined)?.[key], root)
}

describe('CA-CORE-150: claves nuevas de 1.8 completas en los cuatro idiomas', () => {
  for (const path of PATHS) {
    const reference = flatten(subtree(locales.es, path))

    it.each(['en', 'de', 'fr'] as const)(
      `%s tiene exactamente las mismas claves que es para ${path}`,
      (locale) => {
        const candidate = flatten(subtree(locales[locale], path))

        const missing = [...reference.keys()].filter((key) => !candidate.has(key))
        const extra = [...candidate.keys()].filter((key) => !reference.has(key))

        expect(missing, `claves ausentes en ${locale} para ${path}`).toEqual([])
        expect(extra, `claves sobrantes en ${locale} para ${path}`).toEqual([])
      },
    )

    it.each(['en', 'de', 'fr'] as const)(`%s no tiene ningún valor vacío en ${path}`, (locale) => {
      const candidate = flatten(subtree(locales[locale], path))

      const empty = [...candidate.entries()].filter(([, value]) => value === '')
      expect(
        empty.map(([key]) => key),
        `valores vacíos en ${locale} para ${path}`,
      ).toEqual([])
    })

    it.each(['en', 'de', 'fr'] as const)(
      `%s no es una copia literal del español en ${path} (salvo autónimos y el formato de título)`,
      (locale) => {
        const candidate = flatten(subtree(locales[locale], path))

        const untranslated = [...reference.entries()].filter(([key, esValue]) => {
          if (EXPECTED_IDENTICAL_ACROSS_LOCALES.has(key)) return false
          if (typeof esValue !== 'string' || esValue.trim() === '') return false
          if (esValue.trim().length < 4) return false
          return candidate.get(key) === esValue
        })

        expect(
          untranslated.map(([key]) => key),
          `claves idénticas al español en ${locale} para ${path}`,
        ).toEqual([])
      },
    )
  }
})
