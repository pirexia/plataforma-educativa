import { describe, expect, it } from 'vitest'
import es from './es.json'
import en from './en.json'
import de from './de.json'
import fr from './fr.json'

/**
 * CA-AUTH-309 (`funcional.md §F.11`): "los textos de las tres pantallas
 * nuevas [...] existen en los cuatro idiomas y ninguno está escrito en
 * el código" (`INV-009`). Issue #147: ninguno de los tests de las cinco
 * pantallas de 1.4b comprobaba esto, y `fallbackLocale: 'es'`
 * (`src/i18n/index.ts`) lo esconde en producción — si falta una clave en
 * `en`/`de`/`fr`, la SPA cae al español en silencio, así que un usuario
 * viendo la pantalla en su idioma nunca lo notaría y ningún test que
 * monte el componente con `setLocale('es')` (como el resto de specs de
 * este módulo) lo detectaría tampoco.
 *
 * Cubre `auth.ssoAdmin` (usado por `AdminSsoView.vue` y
 * `AdminSsoProviderView.vue`) y las tres claves propias de 1.4b dentro
 * de `auth.oauthCallback` (usadas por `SsoCallbackResultView.vue`): el
 * resto de `auth.oauthCallback` ya es de 1.4 y queda fuera de este CA.
 */

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

const locales: Record<string, Json> = { es, en, de, fr }

const SSO_ADMIN_PATH = ['auth', 'ssoAdmin']
const SSO_CALLBACK_KEYS = ['ssoTitle', 'dominioNoPermitido', 'proveedorNoDisponible']

function subtree(root: Json, path: string[]): unknown {
  return path.reduce<unknown>((acc, key) => (acc as Json | undefined)?.[key], root)
}

const referenceSsoAdmin = flatten(subtree(es, SSO_ADMIN_PATH))
const referenceCallback = new Map(
  SSO_CALLBACK_KEYS.map((key) => [key, (es.auth as Json).oauthCallback as Json] as const).map(
    ([key, node]) => [key, (node as Json)[key]],
  ),
)

describe('i18n de 1.4b (CA-AUTH-309): auth.ssoAdmin existe completo en los cuatro idiomas', () => {
  it.each(['en', 'de', 'fr'])(
    '%s tiene exactamente las mismas claves que es para auth.ssoAdmin',
    (locale) => {
      const candidate = flatten(subtree(locales[locale]!, SSO_ADMIN_PATH))

      const missing = [...referenceSsoAdmin.keys()].filter((key) => !candidate.has(key))
      const extra = [...candidate.keys()].filter((key) => !referenceSsoAdmin.has(key))

      expect(missing, `claves ausentes en ${locale}.json`).toEqual([])
      expect(extra, `claves sobrantes en ${locale}.json (¿copiadas mal?)`).toEqual([])
    },
  )

  it.each(['en', 'de', 'fr'])('%s no tiene ningún valor vacío en auth.ssoAdmin', (locale) => {
    const candidate = flatten(subtree(locales[locale]!, SSO_ADMIN_PATH))

    const empty = [...candidate.entries()].filter(([, value]) => value === '')
    expect(
      empty.map(([key]) => key),
      `claves con texto vacío en ${locale}.json`,
    ).toEqual([])
  })

  // Términos técnicos OIDC (nombres de *claim*) y el ejemplo de dominio
  // del placeholder: coinciden a propósito entre los cuatro idiomas, no
  // son prosa sin traducir.
  const EXPECTED_IDENTICAL_ACROSS_LOCALES = new Set([
    'form.claimsSourceUserinfo',
    'form.emailClaimOptions.email',
    'form.emailClaimOptions.preferred_username',
    'form.emailClaimOptions.upn',
    'form.allowedDomainsPlaceholder',
  ])

  it.each(['en', 'de', 'fr'])(
    '%s no es una copia literal del texto en español (RNF de traducción real, no solo clave presente)',
    (locale) => {
      const candidate = flatten(subtree(locales[locale]!, SSO_ADMIN_PATH))

      const untranslated = [...referenceSsoAdmin.entries()].filter(([key, esValue]) => {
        if (EXPECTED_IDENTICAL_ACROSS_LOCALES.has(key)) {
          return false
        }
        if (typeof esValue !== 'string' || esValue.trim() === '') {
          return false
        }
        // Los valores de un único carácter o símbolo (p.ej. separadores)
        // pueden coincidir legítimamente entre idiomas.
        if (esValue.trim().length < 4) {
          return false
        }
        return candidate.get(key) === esValue
      })

      expect(
        untranslated.map(([key]) => key),
        `claves de auth.ssoAdmin idénticas al español en ${locale}.json`,
      ).toEqual([])
    },
  )
})

describe('i18n de 1.4b (CA-AUTH-309): los tres textos propios de SsoCallbackResultView existen en los cuatro idiomas', () => {
  it.each(['en', 'de', 'fr'])(
    '%s tiene ssoTitle/dominioNoPermitido/proveedorNoDisponible, no vacíos y traducidos',
    (locale) => {
      const node = ((locales[locale]!.auth as Json).oauthCallback as Json) ?? {}

      for (const key of SSO_CALLBACK_KEYS) {
        const value = node[key]
        expect(typeof value, `auth.oauthCallback.${key} ausente en ${locale}.json`).toBe('string')
        expect(
          (value as string).trim().length,
          `auth.oauthCallback.${key} vacío en ${locale}.json`,
        ).toBeGreaterThan(0)
        expect(value, `auth.oauthCallback.${key} no traducido en ${locale}.json`).not.toBe(
          referenceCallback.get(key),
        )
      }
    },
  )
})

// Issue #148: los seis códigos que SsoCallbackResultView.vue comparte con
// GoogleCallbackResultView.vue no comparten claves de texto — auth.oauthCallback
// menciona "Google" literalmente. auth.ssoCallback es el juego neutro propio.
const SSO_NEUTRAL_PATH = ['auth', 'ssoCallback']
const referenceSsoCallback = flatten(subtree(es, SSO_NEUTRAL_PATH))

describe('i18n de 1.4b (issue #148): auth.ssoCallback existe completo, traducido, en los cuatro idiomas', () => {
  it.each(['en', 'de', 'fr'])(
    '%s tiene exactamente las mismas claves que es para auth.ssoCallback',
    (locale) => {
      const candidate = flatten(subtree(locales[locale]!, SSO_NEUTRAL_PATH))

      const missing = [...referenceSsoCallback.keys()].filter((key) => !candidate.has(key))
      const extra = [...candidate.keys()].filter((key) => !referenceSsoCallback.has(key))

      expect(missing, `claves ausentes en ${locale}.json`).toEqual([])
      expect(extra, `claves sobrantes en ${locale}.json`).toEqual([])
    },
  )

  it.each(['en', 'de', 'fr'])(
    '%s no tiene ningún valor vacío ni idéntico al español en auth.ssoCallback',
    (locale) => {
      const candidate = flatten(subtree(locales[locale]!, SSO_NEUTRAL_PATH))

      for (const [key, esValue] of referenceSsoCallback) {
        const value = candidate.get(key)
        expect(typeof value, `auth.ssoCallback.${key} ausente en ${locale}.json`).toBe('string')
        expect(
          (value as string).trim().length,
          `auth.ssoCallback.${key} vacío en ${locale}.json`,
        ).toBeGreaterThan(0)
        expect(value, `auth.ssoCallback.${key} no traducido en ${locale}.json`).not.toBe(esValue)
      }
    },
  )

  it('ninguna clave de auth.ssoCallback menciona "Google" en ningún idioma (motivo del issue #148)', () => {
    for (const [locale, root] of Object.entries(locales)) {
      const candidate = flatten(subtree(root, SSO_NEUTRAL_PATH))

      for (const [key, value] of candidate) {
        if (typeof value === 'string') {
          expect(
            value.toLowerCase(),
            `auth.ssoCallback.${key} menciona Google en ${locale}.json`,
          ).not.toContain('google')
        }
      }
    }
  })
})
