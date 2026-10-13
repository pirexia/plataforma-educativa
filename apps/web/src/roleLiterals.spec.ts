/**
 * `docs/modulos/REQ-CORE/funcional.md §12.11`, `CA-CORE-102`, `RN-CORE-23`.
 * Cubre también `CA-PERM-131` (`RN-PERM-46`): ningún fichero nuevo de 1.5b
 * contiene un literal de código de rol.
 * Ningún fichero de `src/` decide por `roles[].code` ni contiene como
 * literal los códigos de rol predefinidos SIN homónimo de dominio — la
 * visibilidad se deriva siempre de `permissions` (`GET /me`), nunca del rol.
 *
 * Criterio de la lista de literales (#376, mismo que `AR-08` en el backend,
 * `ADR-056 §3.3`): se excluyen los cuatro códigos que son también
 * vocabulario del dominio y darían falsos positivos seguros — `direccion`
 * (dirección postal), `tutor_legal` (tipo de parentesco en `REQ-FAM-UNIT`),
 * `docente` y `estudiante` (tipos de persona). La exclusión es SOLO del
 * literal: la decisión por `.code` de un rol (`ROLE_CODE_DECISION_RE`) sigue
 * prohibida para los dieciséis, de modo que comparar el código de un rol,
 * sea cual sea, sigue fallando. La lista de literales solo puede reducirse
 * por una decisión nueva; ampliarla de nuevo (volver a vigilar uno de los
 * cuatro) exige que ese código deje de tener homónimo.
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

// Códigos con homónimo de dominio (`ADR-056 §3.3`, #376): no se vigilan como
// literal. Cada uno tiene su motivo; esta lista no admite ampliaciones sin
// decisión nueva.
const AMBIGUOUS_ROLE_CODES = ['direccion', 'tutor_legal', 'docente', 'estudiante']

const ROLE_CODE_LITERALS = PREDEFINED_ROLE_CODES.filter(
  (code) => !AMBIGUOUS_ROLE_CODES.includes(code),
)

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

const ROLE_CODE_LITERAL_RE = new RegExp(`['"\`](${ROLE_CODE_LITERALS.join('|')})['"\`]`)

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

describe('CA-CORE-102 CA-PERM-131 (RN-CORE-23, RN-PERM-46): ningún fichero de src/ decide por el código de un rol', () => {
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

  it('(caso fijo) detecta un literal sin homónimo y NO los cuatro con homónimo de dominio (#376)', () => {
    for (const code of ROLE_CODE_LITERALS) {
      expect(findOffenses(`const c = '${code}'`)).toContain('literal de código de rol predefinido')
    }

    for (const code of AMBIGUOUS_ROLE_CODES) {
      expect(findOffenses(`const kind = '${code}'`)).toEqual([])
    }

    expect(ROLE_CODE_LITERALS).toHaveLength(12)
    expect(ROLE_CODE_LITERALS).toContain('administrador_centro')
    expect(ROLE_CODE_LITERALS).toContain('soporte_plataforma')
  })

  it('(caso fijo) la decisión por .code sigue prohibida aunque el código sea ambiguo (#376)', () => {
    expect(findOffenses("if (role.code === 'direccion') {}")).toContain(
      'decisión por .code de un rol',
    )
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
