/**
 * `docs/modulos/REQ-CORE/funcional.md §12.11`, `CA-CORE-152`
 * (`ADR-053 §1`, `INV-007`). Recorre `src/layouts/**` y `src/navigation/**`
 * con `node:fs`, como `src/design-system/architecture.spec.ts` hace para
 * su propia frontera — misma técnica, otro perímetro.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const srcDir = resolve(process.cwd(), 'src')

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

const IMPORT_RE = /import\s+(?:[^'"]+\s+from\s+)?['"]([^'"]+)['"]/g

function importsOf(source: string): string[] {
  const found: string[] = []
  let match: RegExpExecArray | null

  IMPORT_RE.lastIndex = 0
  while ((match = IMPORT_RE.exec(source)) !== null) {
    found.push(match[1]!)
  }

  return found
}

const MODULE_PUBLIC_SURFACE = /^@\/modules\/[^/]+\/(api|types|shell)(\/index)?$/

const layoutsAndNavFiles = [
  ...listFiles(join(srcDir, 'layouts')),
  ...listFiles(join(srcDir, 'navigation')),
]

describe('CA-CORE-152: frontera de src/layouts y src/navigation (ADR-053 §1, INV-007)', () => {
  it('solo importan la superficie pública de un módulo (api/, types/, shell.ts)', () => {
    const offenders: string[] = []

    for (const file of layoutsAndNavFiles) {
      const source = stripComments(readFileSync(file, 'utf-8'))

      for (const specifier of importsOf(source)) {
        if (!specifier.startsWith('@/modules/')) continue

        if (!MODULE_PUBLIC_SURFACE.test(specifier)) {
          offenders.push(`${relative(srcDir, file)} -> ${specifier}`)
        }
      }
    }

    expect(offenders).toEqual([])
  })

  it('(caso fijo) un import de una vista interna de un módulo se detecta', () => {
    const offending = "import LoginView from '@/modules/auth/views/LoginView.vue'"
    const specifier = importsOf(offending)[0]!

    expect(MODULE_PUBLIC_SURFACE.test(specifier)).toBe(false)
  })

  it('(caso fijo) un import de la superficie pública no se marca', () => {
    for (const specifier of [
      '@/modules/auth/shell',
      '@/modules/core/api',
      '@/modules/auth/types',
    ]) {
      expect(MODULE_PUBLIC_SURFACE.test(specifier)).toBe(true)
    }
  })
})

describe('CA-CORE-152: ningún módulo importa el shell.ts de otro', () => {
  it('src/modules/**/shell.ts no importa @/modules/<otro>/shell', () => {
    const modulesDir = join(srcDir, 'modules')
    const shellFiles = readdirSync(modulesDir)
      .map((name) => join(modulesDir, name, 'shell.ts'))
      .filter((path) => {
        try {
          return statSync(path).isFile()
        } catch {
          return false
        }
      })

    const offenders: string[] = []

    for (const file of shellFiles) {
      const ownModule = file.split('/').slice(-2, -1)[0]
      const source = stripComments(readFileSync(file, 'utf-8'))

      for (const specifier of importsOf(source)) {
        const match = /^@\/modules\/([^/]+)\/shell$/.exec(specifier)

        if (match && match[1] !== ownModule) {
          offenders.push(`${relative(srcDir, file)} -> ${specifier}`)
        }
      }
    }

    expect(offenders).toEqual([])
  })
})
