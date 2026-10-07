/**
 * `docs/adr/ADR-056-estandarizacion-de-modulos-reglas-comprobadas-y-generador.md`
 * `AR-11`, `CA-056-12` (`INV-007`, `INV-009`, `ADR-053 §1`, `RNF-MANT-003`).
 * Recorre `src/modules/**` con `node:fs`, como `src/navigation/architecture.spec.ts`
 * y `src/design-system/architecture.spec.ts` hacen para su propia frontera —
 * misma técnica, otro perímetro. Un módulo nuevo queda vigilado sin tocar
 * este test: se enumeran los directorios de `src/modules/`.
 *
 * Cuatro comprobaciones por módulo:
 *  1. tiene `shell.ts`;
 *  2. tiene `locales/{es,en,de,fr}.json`;
 *  3. está registrado en `src/navigation/modules.ts` (su `shell`) y en
 *     `src/i18n/index.ts` (sus cuatro `locales`);
 *  4. de otro módulo solo importa su superficie pública (`api/`, `types/`,
 *     `shell`), por alias `@/modules/...` o por ruta relativa.
 *
 * Los `*.spec.ts` no se escanean (los tests pueden mirar dentro de un
 * módulo), igual que en los demás tests de arquitectura del frontend.
 */
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { dirname, join, relative, resolve, sep } from 'node:path'
import { describe, expect, it } from 'vitest'

const srcDir = resolve(process.cwd(), 'src')
const modulesDir = join(srcDir, 'modules')
const LOCALES = ['es', 'en', 'de', 'fr'] as const

function listFiles(dir: string): string[] {
  const files: string[] = []

  for (const entry of readdirSync(dir)) {
    if (entry.endsWith('.spec.ts')) continue

    const fullPath = join(dir, entry)
    const stats = statSync(fullPath)

    if (stats.isDirectory()) {
      files.push(...listFiles(fullPath))
    } else if (entry.endsWith('.ts') || entry.endsWith('.vue')) {
      files.push(fullPath)
    }
  }

  return files
}

function stripComments(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1')
}

/**
 * Especificadores de import: estáticos (`import x from '…'`, `import '…'`),
 * reexportaciones (`export … from '…'`) y dinámicos (`import('…')`).
 */
function importSpecifiers(source: string): string[] {
  const found: string[] = []
  const patterns = [
    /\bimport\s+(?:[^'"()]+?\s+from\s+)?['"]([^'"]+)['"]/g,
    /\bexport\s+[^'"()]*?\s+from\s+['"]([^'"]+)['"]/g,
    /\bimport\(\s*['"]([^'"]+)['"]\s*\)/g,
  ]

  for (const pattern of patterns) {
    let match: RegExpExecArray | null

    while ((match = pattern.exec(source)) !== null) {
      found.push(match[1]!)
    }
  }

  return found
}

/**
 * Lleva un especificador relativo (`../../core/api`) al alias `@/…` que
 * apunta al mismo sitio, para decidir con una sola regla. Los alias y los
 * paquetes se devuelven tal cual.
 */
function toAlias(specifier: string, fromFile: string): string {
  if (!specifier.startsWith('.')) return specifier

  const target = resolve(dirname(fromFile), specifier)
  const relativeToSrc = relative(srcDir, target)

  if (relativeToSrc.startsWith('..')) return specifier

  return `@/${relativeToSrc.split(sep).join('/')}`
}

const MODULE_SPECIFIER = /^@\/modules\/([^/]+)(?:\/(.*))?$/
const PUBLIC_SURFACE = /^(api|types|shell)(\/index)?$/

/**
 * Un import es una violación si apunta al interior de OTRO módulo: solo
 * `api/`, `types/` y `shell` son superficie pública. `api` y `types`
 * admiten el fichero `index` explícito; `api/users` (un fichero interno
 * del cliente) NO es superficie pública — se importa por `@/modules/<m>/api`.
 */
function isForbiddenCrossModuleImport(alias: string, ownModule: string): boolean {
  const match = MODULE_SPECIFIER.exec(alias)

  if (!match) return false
  if (match[1] === ownModule) return false

  return !PUBLIC_SURFACE.test(match[2] ?? '')
}

const moduleNames = readdirSync(modulesDir)
  .filter((name) => statSync(join(modulesDir, name)).isDirectory())
  .sort()

const modulesRegistry = readFileSync(join(srcDir, 'navigation', 'modules.ts'), 'utf-8')
const i18nIndex = readFileSync(join(srcDir, 'i18n', 'index.ts'), 'utf-8')

describe('AR-11, CA-056-12 (INV-007): módulos de src/modules/', () => {
  it('la enumeración no es vacía y cubre auth y core', () => {
    expect(moduleNames.length).toBeGreaterThan(0)
    expect(moduleNames).toEqual(expect.arrayContaining(['auth', 'core']))
  })

  for (const name of moduleNames) {
    describe(`módulo ${name}`, () => {
      it('tiene shell.ts', () => {
        expect(existsSync(join(modulesDir, name, 'shell.ts'))).toBe(true)
      })

      it('tiene locales/{es,en,de,fr}.json', () => {
        const missing = LOCALES.filter(
          (locale) => !existsSync(join(modulesDir, name, 'locales', `${locale}.json`)),
        )

        expect(missing).toEqual([])
      })

      it('está registrado en src/navigation/modules.ts y en src/i18n/index.ts', () => {
        expect(modulesRegistry).toContain(`'@/modules/${name}/shell'`)

        const missing = LOCALES.filter(
          (locale) => !i18nIndex.includes(`'@/modules/${name}/locales/${locale}.json'`),
        )

        expect(missing).toEqual([])
      })

      it('de otro módulo solo importa su superficie pública (api/, types/, shell)', () => {
        const offenders: string[] = []

        for (const file of listFiles(join(modulesDir, name))) {
          const source = stripComments(readFileSync(file, 'utf-8'))

          for (const specifier of importSpecifiers(source)) {
            if (isForbiddenCrossModuleImport(toAlias(specifier, file), name)) {
              offenders.push(`${relative(srcDir, file)} -> ${specifier}`)
            }
          }
        }

        expect(offenders).toEqual([])
      })
    })
  }
})

describe('AR-11, CA-056-12: casos fijos del detector', () => {
  const own = 'auth'
  const file = join(modulesDir, own, 'views', 'LoginView.vue')

  it('detecta la vista interna de otro módulo por alias', () => {
    expect(isForbiddenCrossModuleImport('@/modules/core/views/UsersView.vue', own)).toBe(true)
    expect(isForbiddenCrossModuleImport('@/modules/core/components/UserTable.vue', own)).toBe(true)
  })

  it('detecta un fichero interno de api/ de otro módulo (solo api/index es público)', () => {
    expect(isForbiddenCrossModuleImport('@/modules/core/api/users', own)).toBe(true)
  })

  it('detecta el mismo caso por ruta relativa', () => {
    expect(toAlias('../../core/views/UsersView.vue', file)).toBe(
      '@/modules/core/views/UsersView.vue',
    )
    expect(isForbiddenCrossModuleImport(toAlias('../../core/views/UsersView.vue', file), own)).toBe(
      true,
    )
  })

  it('no marca la superficie pública (api, api/index, types, types/index, shell)', () => {
    for (const alias of [
      '@/modules/core/api',
      '@/modules/core/api/index',
      '@/modules/core/types',
      '@/modules/core/types/index',
      '@/modules/core/shell',
    ]) {
      expect(isForbiddenCrossModuleImport(alias, own)).toBe(false)
    }
  })

  it('no marca imports del propio módulo ni de fuera de src/modules', () => {
    expect(isForbiddenCrossModuleImport('@/modules/auth/views/LoginView.vue', own)).toBe(false)
    expect(isForbiddenCrossModuleImport('@/components/ui/button', own)).toBe(false)
    expect(isForbiddenCrossModuleImport('vue', own)).toBe(false)
    expect(toAlias('../api/session', file)).toBe('@/modules/auth/api/session')
  })

  it('extrae imports estáticos, de efecto, reexportaciones y dinámicos', () => {
    const source = [
      "import { a } from '@/modules/core/api'",
      "import Foo from '@/modules/core/views/Foo.vue'",
      "import '@/modules/core/side'",
      "export * from '@/modules/core/internal'",
      "export { b } from '@/modules/core/other'",
      "const lazy = () => import('@/modules/core/views/Lazy.vue')",
    ].join('\n')

    expect(importSpecifiers(source)).toEqual(
      expect.arrayContaining([
        '@/modules/core/api',
        '@/modules/core/views/Foo.vue',
        '@/modules/core/side',
        '@/modules/core/internal',
        '@/modules/core/other',
        '@/modules/core/views/Lazy.vue',
      ]),
    )
    expect(importSpecifiers(source)).toHaveLength(6)
  })

  it('no extrae imports de comentarios', () => {
    const source = stripComments(
      [
        "// import x from '@/modules/core/views/X.vue'",
        "/* import y from '@/modules/core/y' */",
      ].join('\n'),
    )

    expect(importSpecifiers(source)).toEqual([])
  })
})
