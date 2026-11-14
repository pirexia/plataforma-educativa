/**
 * `CA-CORE-295` (`docs/modulos/REQ-CORE/funcional.md §14.13.6`, `INV-009`,
 * `CA-CORE-201`, `CA-CORE-261`, issues #90 y #259): las tres vistas de
 * `REQ-AUTH` migradas en 1.9f no escriben `'—'` como literal ni importan
 * `vue-i18n`, y toda clave nueva de 1.9f existe en es, en, de y fr.
 */
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import es from './es.json'
import en from './en.json'
import de from './de.json'
import fr from './fr.json'

type Json = Record<string, unknown>

const locales: Record<string, Json> = { es, en, de, fr }

const VIEWS = [
  '../components/admin/MfaExemptionsArea.vue',
  '../views/AdminSsoView.vue',
  '../views/SessionsView.vue',
]

/** Sin comentarios: los de cabecera usan la raya como signo de puntuación. */
function code(path: string): string {
  return readFileSync(new URL(path, import.meta.url), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/^\s*\/\/.*$/gm, '')
}

const NEW_KEYS = [
  'auth.mfaAdmin.exemptions.tableCaption',
  'auth.mfaAdmin.exemptions.revokeActionFor',
  'auth.ssoAdmin.tableCaption',
  'auth.ssoAdmin.columns.actions',
  'auth.ssoAdmin.editFor',
  'auth.ssoAdmin.deleteFor',
  'auth.sessions.empty',
  'auth.sessions.revokeFor',
]

function lookup(root: Json, path: string): unknown {
  return path.split('.').reduce<unknown>((acc, key) => (acc as Json | undefined)?.[key], root)
}

describe('CA-CORE-295 (INV-009, CA-CORE-201, #90, #259)', () => {
  it.each(VIEWS)('%s no escribe «—» como literal ni importa vue-i18n', (path) => {
    const source = code(path)

    expect(source).not.toMatch(/['"`]—['"`]/)
    expect(source).not.toMatch(/>\s*—\s*</)
    expect(source).not.toMatch(/from\s+['"]vue-i18n['"]/)
  })

  it.each(Object.keys(locales))('el idioma %s tiene todas las claves nuevas de 1.9f', (locale) => {
    for (const key of NEW_KEYS) {
      const value = lookup(locales[locale]!, key)

      expect(typeof value, `${locale}: ${key}`).toBe('string')
      expect((value as string).length, `${locale}: ${key}`).toBeGreaterThan(0)
    }
  })

  it('las claves con parámetros conservan los mismos marcadores en los cuatro idiomas', () => {
    for (const key of NEW_KEYS) {
      const markers = Object.values(locales).map((root) =>
        [...String(lookup(root, key)).matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort(),
      )

      for (const m of markers) {
        expect(m, key).toEqual(markers[0])
      }
    }
  })
})
