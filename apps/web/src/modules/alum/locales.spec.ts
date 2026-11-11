/**
 * `INV-009`, `CA-ALUM-064`: las cuatro lenguas de `alum` tienen las mismas claves y ninguna vacía, y toda clave
 * `alum.*` usada como literal en el código de producción del módulo existe. (`npm run lint:i18n` cubre los literales
 * visibles en plantillas; esto cubre las claves.) Las traducciones de en/de/fr las completa la sesión orquestadora
 * después de la implementación: este test no exige que difieran del español. `REQ-ALUM-001`.
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

describe('CA-ALUM-064 / INV-009: las cuatro lenguas de alum tienen las mismas claves', () => {
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

  it('toda clave alum.* usada en el código del módulo existe', () => {
    const leaves = Object.keys(flatten(LOCALES.es!))
    // Los prefijos de grupo (`alum.students`) no son claves de texto pero pueden ser identificadores de navegación.
    const known = new Set(leaves)

    for (const key of leaves) {
      const parts = key.split('.')

      for (let index = 1; index < parts.length; index++) {
        known.add(parts.slice(0, index).join('.'))
      }
    }

    const used = new Set<string>()

    for (const file of sourceFiles(resolve(process.cwd(), 'src/modules/alum'))) {
      for (const match of readFileSync(file, 'utf-8').matchAll(
        /['"`](alum\.[A-Za-z0-9_.]+)['"`]/g,
      )) {
        used.add(match[1]!)
      }
    }

    expect([...used].filter((key) => !known.has(key))).toEqual([])
  })
})
