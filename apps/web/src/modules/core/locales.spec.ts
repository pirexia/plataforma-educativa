/**
 * `docs/modulos/REQ-CORE/funcional.md §14.18`, `CA-CORE-261` (`INV-009`):
 * toda clave de `core` existe en `es`, `en`, `de` y `fr`, sin valores vacíos,
 * y toda clave `core.*` / `shell.confirm.*` usada como literal en el código
 * de producción del módulo existe. (`npm run lint:i18n` cubre los literales
 * visibles en plantillas; esto cubre las claves.)
 */
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import es from './locales/es.json'
import en from './locales/en.json'
import de from './locales/de.json'
import fr from './locales/fr.json'
import globalEs from '@/i18n/locales/es.json'
import globalEn from '@/i18n/locales/en.json'
import globalDe from '@/i18n/locales/de.json'
import globalFr from '@/i18n/locales/fr.json'

type Tree = { [key: string]: string | Tree }

function flatten(tree: Tree, prefix = ''): Record<string, string> {
  const flat: Record<string, string> = {}

  for (const [key, value] of Object.entries(tree)) {
    const path = prefix ? `${prefix}.${key}` : key

    if (typeof value === 'string') {
      flat[path] = value
    } else {
      Object.assign(flat, flatten(value, path))
    }
  }

  return flat
}

const LOCALES = { es, en, de, fr } as unknown as Record<string, Tree>
const GLOBALS = { es: globalEs, en: globalEn, de: globalDe, fr: globalFr } as unknown as Record<
  string,
  Tree
>

describe('CA-CORE-261 (INV-009): las cuatro lenguas de core tienen las mismas claves', () => {
  const reference = Object.keys(flatten(LOCALES.es!)).sort()

  it.each(['en', 'de', 'fr'])('%s tiene exactamente las claves de es', (locale) => {
    expect(Object.keys(flatten(LOCALES[locale]!)).sort()).toEqual(reference)
  })

  it.each(['es', 'en', 'de', 'fr'])('%s no tiene valores vacíos', (locale) => {
    const empty = Object.entries(flatten(LOCALES[locale]!)).filter(
      ([, value]) => value.trim() === '',
    )

    expect(empty).toEqual([])
  })

  it('shell.confirm.cancel existe en los cuatro idiomas', () => {
    for (const locale of ['es', 'en', 'de', 'fr']) {
      expect(flatten(GLOBALS[locale]!)['shell.confirm.cancel'], locale).toBeTruthy()
    }
  })

  it('las claves nuevas de 1.9b, 1.9d y 1.9e no se copian del español en los demás idiomas', () => {
    const spanish = flatten(LOCALES.es!)

    // Cognados legítimos (p. ej. «Roles» en español e inglés); el resto no debe coincidir.
    const allowed = new Set([
      'core.nav.invitations',
      'core.invitations.title',
      'core.invitations.filters.status',
      'core.users.columns.roles',
      'core.users.detail.roles',
      'core.users.form.roles',
      // 1.9d: cognados, siglas y plantillas solo de parámetros.
      'core.nav.roles',
      'core.roles.title',
      'core.roles.no',
      'core.audit.columns.ipAddress',
      'core.audit.changes.arrow',
      'core.audit.changes.description',
      // 1.9e: nombres propios (comunidades autónomas) y términos idénticos en español e inglés.
      'core.settings.regional.title',
      'core.settings.autonomousCommunity.CB',
      'core.settings.autonomousCommunity.CM',
      'core.settings.autonomousCommunity.EX',
      'core.settings.autonomousCommunity.GA',
      'core.settings.autonomousCommunity.RI',
      'core.settings.autonomousCommunity.CE',
      'core.settings.autonomousCommunity.ML',
      'core.branding.kinds.favicon.name',
    ])

    for (const locale of ['en', 'de']) {
      const copied = Object.entries(flatten(LOCALES[locale]!))
        .filter(([key]) =>
          [
            'core.users.',
            'core.invitations.',
            'core.audit.',
            'core.roles.',
            'core.nav.',
            'core.settings.',
            'core.branding.',
            'core.modules.',
            'core.profile.',
          ].some((prefix) => key.startsWith(prefix)),
        )
        .filter(([key, value]) => value === spanish[key] && !allowed.has(key))
        .map(([key]) => key)

      expect(copied, locale).toEqual([])
    }
  })
})

describe('CA-CORE-261: toda clave literal usada en el código de core existe', () => {
  const root = resolve(process.cwd(), 'src/modules/core')
  const known = new Set([
    ...Object.keys(flatten(LOCALES.es!)),
    ...Object.keys(flatten(GLOBALS.es!)),
  ])

  function files(dir: string): string[] {
    return readdirSync(dir).flatMap((entry) => {
      const abs = resolve(dir, entry)

      if (statSync(abs).isDirectory()) {
        return entry === 'locales' ? [] : files(abs)
      }

      return /\.(vue|ts)$/.test(entry) && !/\.spec\.ts$/.test(entry) ? [abs] : []
    })
  }

  it('cada literal `core.…` o `shell.confirm.…` entre comillas sin interpolación es una clave real', () => {
    const missing: string[] = []

    for (const file of files(root)) {
      const source = readFileSync(file, 'utf-8')

      for (const match of source.matchAll(
        /['"`]((?:core\.[A-Za-z0-9_]+|shell\.confirm)\.[A-Za-z0-9_.]+)['"`]/g,
      )) {
        const key = match[1]!

        // Prefijos que se completan con una variable (`core.user.status.${…}` se escribe con plantilla).
        if (!known.has(key) && !key.endsWith('.')) {
          missing.push(`${file.replace(`${root}/`, '')}: ${key}`)
        }
      }
    }

    // Rutas de la API y nombres de rol no son claves: se descartan los que no cuelgan de `core.<área>.`.
    // `core.authorization.*` y `core.validation.*` son **códigos de error** del servidor
    // (`errors.<clave>[].code`, `ADR-038 §6.3`, `REQ-PERM/api.md §9.2.1`), que la vista compara
    // y nunca traduce (el mensaje ya llega traducido).
    expect(
      missing.filter((entry) => !/: core\.(?:export|validation|authorization)\./.test(entry)),
    ).toEqual([])
  })
})
