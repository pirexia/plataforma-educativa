/**
 * `docs/modulos/REQ-CORE/funcional.md §14.6.4`, `OPEN-CORE-53` = A:
 *
 * - `CA-CORE-282` (`RN-CORE-90`, `INV-009`): la constante `DOCUMENT_TYPES` del
 *   cliente contiene exactamente los mismos códigos que el enumerado PHP del
 *   servidor (`App\Modules\Core\Domain\DocumentType`), y cada código más
 *   `none` tiene etiqueta en `es`, `en`, `de` y `fr`; la pista de «texto libre»
 *   (`core.users.form.documentTypeHint`) ya no existe en ningún `locales/*.json`.
 * - La cabecera de importación del cliente coincide con
 *   `UserImportCsvReader::EXPECTED_HEADER` del servidor (`api.md §7`).
 *
 * **Lectura cruzada** (como la de `RN-CORE-82`): lee ficheros de `apps/api`, que
 * están en el monorepo pero **no** dentro del contenedor `web` (`compose.yaml`
 * solo monta `apps/web`). Si `apps/api` no es alcanzable, las dos comprobaciones
 * cruzadas se **omiten** (quedan como `skipped`, a la vista) en vez de pasar en
 * falso; en CI, con el repositorio completo, se ejecutan.
 */
import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import de from './locales/de.json'
import en from './locales/en.json'
import es from './locales/es.json'
import fr from './locales/fr.json'
import { DOCUMENT_TYPES } from './documentTypes'
import { USER_IMPORT_COLUMNS } from './userImportHeader'

// `process.cwd()` es `apps/web` (como en `architecture.spec.ts`).
const API = resolve(process.cwd(), '..', 'api', 'app', 'Modules', 'Core')
const phpEnum = resolve(API, 'Domain', 'DocumentType.php')
const phpReader = resolve(API, 'Application', 'UserImportCsvReader.php')
const crossReadable = existsSync(phpEnum) && existsSync(phpReader)

const LOCALES = { es, en, de, fr } as const

describe('CA-CORE-282 (RN-CORE-90, OPEN-CORE-53 = A): catálogo del cliente contra el enumerado PHP', () => {
  it.skipIf(!crossReadable)(
    'DOCUMENT_TYPES contiene exactamente los códigos del enumerado PHP, en el mismo orden',
    () => {
      const source = readFileSync(phpEnum, 'utf-8')
      const codes = [...source.matchAll(/^\s*case\s+\w+\s*=\s*'([^']+)'\s*;/gm)].map(
        (match) => match[1],
      )

      expect(codes.length).toBeGreaterThan(0)
      expect([...DOCUMENT_TYPES]).toEqual(codes)
    },
  )

  it.skipIf(!crossReadable)(
    'la cabecera de importación del cliente es EXPECTED_HEADER del servidor',
    () => {
      const source = readFileSync(phpReader, 'utf-8')
      const block = /EXPECTED_HEADER\s*=\s*\[([\s\S]*?)\];/.exec(source)?.[1] ?? ''
      const columns = [...block.matchAll(/'([^']+)'/g)].map((match) => match[1])

      expect(columns.length).toBeGreaterThan(0)
      expect([...USER_IMPORT_COLUMNS]).toEqual(columns)
    },
  )

  it('el catálogo no incluye «otro» (OPEN-CORE-46 = B)', () => {
    expect([...DOCUMENT_TYPES]).toEqual(['dni', 'nie', 'pasaporte'])
  })

  it.each(Object.entries(LOCALES))(
    '«%s»: cada código y «none» tiene etiqueta, y la pista de texto libre ya no existe',
    (_locale, catalog) => {
      const labels = catalog.core.person.documentType as Record<string, string>

      for (const code of [...DOCUMENT_TYPES, 'none']) {
        expect(typeof labels[code], code).toBe('string')
        expect(labels[code]!.trim().length, code).toBeGreaterThan(0)
      }

      expect(Object.keys(labels).sort()).toEqual([...DOCUMENT_TYPES, 'none'].sort())
      expect(catalog.core.users.form).not.toHaveProperty('documentTypeHint')
    },
  )
})
