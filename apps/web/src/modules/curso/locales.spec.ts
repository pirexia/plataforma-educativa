/**
 * `docs/modulos/REQ-CURSO/funcional.md §13.5` (1.10), `CA-CURSO-085`
 * (`INV-009`): los cuatro `locales` de `curso` tienen las mismas claves, sin
 * valores vacíos, y toda clave `curso.*` usada como literal en el código de
 * producción del módulo existe. (`npm run lint:i18n` cubre los literales
 * visibles en plantillas; esto cubre las claves.)
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

describe('CA-CURSO-085 (INV-009): las cuatro lenguas de curso tienen las mismas claves', () => {
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

  it.each(['en', 'de', 'fr'])('%s no copia literalmente el español (salvo cognados)', (locale) => {
    const spanish = flatten(LOCALES.es!)
    const other = flatten(LOCALES[locale]!)
    // «Código» no; sí: «Activo» / «Actif» difieren. Solo se admite un puñado de coincidencias.
    const identical = Object.keys(spanish).filter((key) => spanish[key] === other[key])

    expect(identical.length).toBeLessThanOrEqual(2)
  })

  it('los mismos marcadores {…} en cada idioma', () => {
    const placeholders = (text: string): string[] => (text.match(/\{[a-zA-Z]+\}/g) ?? []).sort()
    const spanish = flatten(LOCALES.es!)

    for (const locale of ['en', 'de', 'fr']) {
      const other = flatten(LOCALES[locale]!)

      for (const key of Object.keys(spanish)) {
        expect(placeholders(other[key]!), `${locale}:${key}`).toEqual(placeholders(spanish[key]!))
      }
    }
  })
})

function listSources(dir: string): string[] {
  const files: string[] = []

  for (const entry of readdirSync(dir)) {
    const full = resolve(dir, entry)

    if (statSync(full).isDirectory()) {
      if (entry !== 'locales') {
        files.push(...listSources(full))
      }
    } else if (/\.(vue|ts)$/.test(entry) && !entry.endsWith('.spec.ts')) {
      files.push(full)
    }
  }

  return files
}

describe('CA-CURSO-085: toda clave curso.* usada como literal existe', () => {
  const known = new Set(
    Object.keys(flatten(LOCALES.es!)).map((key) => `curso.${key.slice('curso.'.length)}`),
  )
  const root = resolve(__dirname)

  it('no hay claves usadas sin definir', () => {
    const missing: string[] = []

    for (const file of listSources(root)) {
      const text = readFileSync(file, 'utf-8')

      for (const match of text.matchAll(/'(curso\.[A-Za-z0-9_.]+)'/g)) {
        const key = match[1]!

        // Códigos de error del servidor (`api.md §5`), no claves de traducción de la SPA.
        if (/^curso\.(conflict|validation)\./.test(key) || key === 'curso.no_active_year') {
          continue
        }

        if (!known.has(key) && ![...known].some((candidate) => candidate.startsWith(`${key}.`))) {
          missing.push(`${file.replace(root, '')}: ${key}`)
        }
      }

      // Claves compuestas: `curso.status.${…}` y `curso.confirm.…`.
      for (const match of text.matchAll(/`(curso\.[A-Za-z0-9_.]+)\.\$\{/g)) {
        const prefix = match[1]!

        if (![...known].some((candidate) => candidate.startsWith(`${prefix}.`))) {
          missing.push(`${file.replace(root, '')}: ${prefix}.*`)
        }
      }
    }

    expect(missing).toEqual([])
  })
})
