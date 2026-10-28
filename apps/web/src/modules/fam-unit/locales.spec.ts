/**
 * `INV-009`: las cuatro lenguas de `fam-unit` tienen las mismas claves y ninguna vacía, y toda clave `famUnit.*` usada
 * como literal en el código de producción del módulo existe. (`npm run lint:i18n` cubre los literales visibles en
 * plantillas; esto cubre las claves.) Las traducciones de en/de/fr las completa `traductor` después de la
 * implementación: este test no exige que difieran del español.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import es from './locales/es.json'
import en from './locales/en.json'
import de from './locales/de.json'
import fr from './locales/fr.json'

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

function sourceFiles(dir: string): string[] {
  const files: string[] = []

  for (const entry of readdirSync(dir)) {
    const full = resolve(dir, entry)

    if (statSync(full).isDirectory()) {
      files.push(...sourceFiles(full))
    } else if ((entry.endsWith('.ts') || entry.endsWith('.vue')) && !entry.endsWith('.spec.ts')) {
      files.push(full)
    }
  }

  return files
}

describe('INV-009: las cuatro lenguas de fam-unit tienen las mismas claves', () => {
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

  it('toda clave famUnit.* usada en el código del módulo existe', () => {
    const known = new Set(Object.keys(flatten(LOCALES.es!)))
    const used = new Set<string>()

    for (const file of sourceFiles(resolve(process.cwd(), 'src/modules/fam-unit'))) {
      for (const match of readFileSync(file, 'utf-8').matchAll(
        /['"`](famUnit\.[A-Za-z0-9_.]+)['"`]/g,
      )) {
        used.add(match[1]!)
      }
    }

    expect([...used].filter((key) => !known.has(key))).toEqual([])
  })
})
