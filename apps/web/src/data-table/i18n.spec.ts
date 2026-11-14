/**
 * `docs/modulos/REQ-CORE/funcional.md §13.12`, `CA-CORE-196`, `INV-009`.
 * Toda clave del espacio `dataTable.*` existe en `es`, `en`, `de` y `fr`,
 * sin valores vacíos ni copiados literalmente del español, y toda clave
 * que el código de `src/data-table` usa como literal existe. Mismo
 * patrón que `src/shell.i18n.spec.ts` (`CA-CORE-150`).
 */
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import es from '../i18n/locales/es.json'
import en from '../i18n/locales/en.json'
import de from '../i18n/locales/de.json'
import fr from '../i18n/locales/fr.json'

type Json = Record<string, unknown>

function flatten(node: unknown, prefix = ''): Map<string, unknown> {
  const out = new Map<string, unknown>()

  if (node !== null && typeof node === 'object' && !Array.isArray(node)) {
    for (const [key, value] of Object.entries(node as Json)) {
      for (const [k, v] of flatten(value, prefix ? `${prefix}.${key}` : key)) {
        out.set(k, v)
      }
    }
  } else {
    out.set(prefix, node)
  }

  return out
}

const locales = { es, en, de, fr } as Record<string, Json>

// Iguales en los cuatro idiomas a propósito: la marca gráfica y dos plantillas solo de parámetros.
const EXPECTED_IDENTICAL = new Set([
  'emptyMark',
  'filters.triggerWithCount',
  'filters.booleanTrigger',
])

const reference = flatten(locales.es!.dataTable)

describe('CA-CORE-196 (INV-009): dataTable.* completo en los cuatro idiomas', () => {
  it('(control) es tiene claves de dataTable', () => {
    expect(reference.size).toBeGreaterThan(40)
  })

  it.each(['en', 'de', 'fr'])('%s tiene exactamente las mismas claves que es', (locale) => {
    const candidate = flatten(locales[locale]!.dataTable)

    expect([...reference.keys()].filter((key) => !candidate.has(key))).toEqual([])
    expect([...candidate.keys()].filter((key) => !reference.has(key))).toEqual([])
  })

  it.each(['es', 'en', 'de', 'fr'])('%s no tiene ningún valor vacío', (locale) => {
    const empty = [...flatten(locales[locale]!.dataTable).entries()].filter(
      ([, value]) => value === '',
    )

    expect(empty.map(([key]) => key)).toEqual([])
  })

  it.each(['en', 'de', 'fr'])('%s no es una copia literal del español', (locale) => {
    const candidate = flatten(locales[locale]!.dataTable)
    const untranslated = [...reference.entries()].filter(
      ([key, value]) =>
        !EXPECTED_IDENTICAL.has(key) &&
        typeof value === 'string' &&
        value.trim().length >= 4 &&
        candidate.get(key) === value,
    )

    expect(untranslated.map(([key]) => key)).toEqual([])
  })

  it('las claves con plural usan las tres formas (cero | uno | otros) en los cuatro idiomas', () => {
    const pluralKeys = [
      'pagination.total',
      'announce.results',
      'announce.loaded',
      'announce.more',
      'filters.entityResults',
    ]

    for (const locale of ['es', 'en', 'de', 'fr']) {
      const messages = flatten(locales[locale]!.dataTable)

      for (const key of pluralKeys) {
        expect(String(messages.get(key)).split('|'), `${locale}: ${key}`).toHaveLength(3)
      }
    }
  })

  it('toda clave dataTable.* que el código de src/data-table usa como literal existe', () => {
    const missing: string[] = []

    function walk(dir: string): void {
      for (const entry of readdirSync(dir)) {
        const abs = resolve(dir, entry)

        if (statSync(abs).isDirectory()) {
          walk(abs)
        } else if (/\.(vue|ts)$/.test(entry) && !/\.spec\.ts$/.test(entry)) {
          const source = readFileSync(abs, 'utf-8')

          for (const match of source.matchAll(/['"`](dataTable\.[A-Za-z0-9_.]+)['"`]/g)) {
            const key = match[1]!.slice('dataTable.'.length)

            if (!reference.has(key)) {
              missing.push(`${entry}: ${match[1]}`)
            }
          }
        }
      }
    }

    walk(resolve(process.cwd(), 'src/data-table'))

    expect(missing).toEqual([])
  })
})
