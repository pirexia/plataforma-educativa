/**
 * `docs/modulos/REQ-CORE/funcional.md §12.11`, `CA-CORE-102`, `RN-CORE-23`.
 * Ningún fichero de `src/` decide por `roles[].code` ni contiene los 16
 * códigos de rol predefinidos como literal — la visibilidad se deriva
 * siempre de `permissions` (`GET /me`), nunca del rol.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const srcDir = resolve(process.cwd(), 'src')

// `docs/modulos/REQ-CORE/permisos.md §4.1`/`funcional.md §12.2`: los 16
// roles predefinidos que siembra `tenant:provision-defaults`.
const PREDEFINED_ROLE_CODES = [
  'administrador_centro',
  'direccion',
  'secretaria',
  'administrativo',
  'docente',
  'tutor_grupo',
  'orientador',
  'coordinador_bienestar',
  'estudiante',
  'tutor_legal',
  'responsable_economico',
  'bibliotecario',
  'monitor_extraescolares',
  'personal_sanitario',
  'conserjeria_pas',
  'soporte_plataforma',
]

function listFiles(dir: string): string[] {
  const files: string[] = []

  for (const entry of readdirSync(dir)) {
    if (entry.endsWith('.spec.ts') || entry.endsWith('.test.ts')) continue

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

const ROLE_CODE_LITERAL_RE = new RegExp(`['"\`](${PREDEFINED_ROLE_CODES.join('|')})['"\`]`)

// `roles[0].code`, `role.code`, `r.code` comparado o usado para decidir:
// `.code` de acceso, seguido (con lo que haya entre medias) de una
// comparación, o al revés.
const ROLE_CODE_DECISION_RE = /\.code\s*(===|!==|==|!=)|(===|!==|==|!=)\s*[a-zA-Z0-9_.[\]]*\.code\b/

function findOffenses(source: string): string[] {
  const offenses: string[] = []
  const clean = stripComments(source)

  if (ROLE_CODE_LITERAL_RE.test(clean)) {
    offenses.push('literal de código de rol predefinido')
  }

  if (ROLE_CODE_DECISION_RE.test(clean)) {
    offenses.push('decisión por .code de un rol')
  }

  return offenses
}

const files = listFiles(srcDir)

describe('CA-CORE-102 (RN-CORE-23): ningún fichero de src/ decide por el código de un rol', () => {
  it('ningún fichero fuera de tests contiene un literal de rol predefinido ni un roles[].code', () => {
    const offenders: string[] = []

    for (const file of files) {
      const source = readFileSync(file, 'utf-8')
      const offenses = findOffenses(source)

      if (offenses.length > 0) {
        offenders.push(`${relative(srcDir, file)}: ${offenses.join(', ')}`)
      }
    }

    expect(offenders).toEqual([])
  })

  it('(caso fijo) detecta el literal "administrador_centro"', () => {
    const fixture = "if (user.roles.some((r) => r.code === 'administrador_centro')) { ... }"

    expect(findOffenses(fixture)).toContain('literal de código de rol predefinido')
  })

  it('(caso fijo) detecta roles.some(r => r.code === variable) sin literal', () => {
    const fixture = 'const isAdmin = roles.some((r) => r.code === expectedCode)'

    expect(findOffenses(fixture)).toContain('decisión por .code de un rol')
  })

  it('(caso fijo) no detecta el uso legítimo de permissions', () => {
    const fixture = "const visible = permissions.includes('modulo.leer')"

    expect(findOffenses(fixture)).toEqual([])
  })

  it('(caso fijo) no detecta la declaración de tipo `code: string` de RoleSummary', () => {
    const fixture = 'export interface RoleSummary {\n  code: string\n  name: string\n}'

    expect(findOffenses(fixture)).toEqual([])
  })
})
