/**
 * `docs/modulos/REQ-CORE/funcional.md §13.3`, `§13.18`, `ADR-054 §1`.
 * Tests de arquitectura de `src/data-table` (mismo patrón que
 * `docs/design-system.md §10`: toda la lógica de comprobación vive **en
 * este fichero**, que los escaneos excluyen, y cada regla lleva casos
 * fijos que demuestran que el test sabe fallar):
 *
 * - `CA-CORE-176` (`RN-CORE-43`): `tableId` literal `<modulo>.<nombre>`, único.
 * - `CA-CORE-192` (`RN-CORE-46`): nada construye un fichero de exportación en el cliente.
 * - `CA-CORE-193` (`RN-CORE-37`): solo `src/data-table/**` importa `@tanstack/vue-table`.
 * - `CA-CORE-194` (`RN-CORE-38`): `src/data-table/**` no importa módulos.
 * - `CA-CORE-200` (`RN-CORE-53`): toda tabla pasa por el componente, con lista cerrada de excepciones.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const SRC_ROOT = resolve(process.cwd(), 'src')

// --- Utilidades comunes ----------------------------------------------------

function stripComments(source: string): string {
  let result = ''
  let i = 0
  const n = source.length

  while (i < n) {
    const two = source.slice(i, i + 2)

    if (two === '/*') {
      const end = source.indexOf('*/', i + 2)
      i = end === -1 ? n : end + 2
      continue
    }

    if (source.slice(i, i + 4) === '<!--') {
      const end = source.indexOf('-->', i + 4)
      i = end === -1 ? n : end + 3
      continue
    }

    if (two === '//' && source[i - 1] !== ':') {
      const end = source.indexOf('\n', i)
      i = end === -1 ? n : end
      continue
    }

    const ch = source[i]!

    if (ch === "'" || ch === '`') {
      let j = i + 1

      while (j < n && source[j] !== ch) {
        j += source[j] === '\\' ? 2 : 1
      }

      j = Math.min(j + 1, n)
      result += source.slice(i, j)
      i = j
      continue
    }

    result += ch
    i += 1
  }

  return result
}

interface SourceFile {
  /** Ruta relativa a `src/`, con `/`. */
  path: string
  raw: string
  cleaned: string
}

function listProductionFiles(): SourceFile[] {
  const files: SourceFile[] = []

  function walk(dir: string): void {
    for (const entry of readdirSync(dir)) {
      const abs = resolve(dir, entry)

      if (statSync(abs).isDirectory()) {
        walk(abs)
        continue
      }

      if (!/\.(vue|ts)$/.test(entry) || /\.(spec|test)\.ts$/.test(entry)) {
        continue
      }

      const raw = readFileSync(abs, 'utf-8')

      files.push({
        path: abs
          .slice(SRC_ROOT.length + 1)
          .split('\\')
          .join('/'),
        raw,
        cleaned: stripComments(raw),
      })
    }
  }

  walk(SRC_ROOT)

  return files
}

const FILES = listProductionFiles()

function dirnameOf(srcRelativePath: string): string {
  const lastSlash = srcRelativePath.lastIndexOf('/')

  return lastSlash === -1 ? '' : srcRelativePath.slice(0, lastSlash)
}

function resolveRelativeSpecifier(fromDir: string, specifier: string): string {
  const stack = fromDir === '' ? [] : fromDir.split('/')

  for (const part of specifier.split('/')) {
    if (part === '' || part === '.') {
      continue
    }

    if (part === '..') {
      stack.pop()
    } else {
      stack.push(part)
    }
  }

  return stack.join('/')
}

/** Especificadores de `import` estático, `import()` dinámico, `import type` y `export … from`. */
function importSpecifiers(cleaned: string): string[] {
  const specifiers: string[] = []
  const patterns = [
    /import\s+(?:type\s+)?(?:[\w*${},\s]+\s+from\s+)?['"]([^'"]+)['"]/g,
    /import\(\s*['"]([^'"]+)['"]\s*\)/g,
    /export\s+(?:\*|type\s+\*|(?:type\s+)?\{[^}]*\})\s+from\s+['"]([^'"]+)['"]/g,
  ]

  for (const pattern of patterns) {
    let match: RegExpExecArray | null

    while ((match = pattern.exec(cleaned))) {
      specifiers.push(match[1]!)
    }
  }

  return specifiers
}

/** Especificador `@/…` o relativo → ruta relativa a `src/`; `null` si es un paquete de terceros. */
function toSrcRelative(specifier: string, fromPath: string): string | null {
  if (specifier.startsWith('@/')) {
    return specifier.slice(2)
  }

  if (specifier.startsWith('.')) {
    return resolveRelativeSpecifier(dirnameOf(fromPath), specifier)
  }

  return null
}

// =========================================================================
// CA-CORE-193 · RN-CORE-37: importación única de TanStack
// =========================================================================

const TANSTACK = '@tanstack/vue-table'

function importsTanStack(source: string): boolean {
  return importSpecifiers(stripComments(source)).includes(TANSTACK)
}

describe('CA-CORE-193 (RN-CORE-37, RNF-MANT-007): casos fijos', () => {
  it.each([
    ["import { useVueTable } from '@tanstack/vue-table'", 'estático'],
    ["const m = await import('@tanstack/vue-table')", 'dinámico'],
    ["import type { ColumnDef } from '@tanstack/vue-table'", 'de tipos'],
    ["export { flexRender } from '@tanstack/vue-table'", 're-exportado'],
  ])('detecta el import %s (%s)', (fixture) => {
    expect(importsTanStack(fixture)).toBe(true)
  })

  it('no detecta la mención en un comentario ni otros paquetes', () => {
    expect(importsTanStack("// import x from '@tanstack/vue-table'")).toBe(false)
    expect(importsTanStack("import { ref } from 'vue'")).toBe(false)
  })
})

describe('CA-CORE-193 (RN-CORE-37): barrido de src/', () => {
  it('solo los ficheros bajo src/data-table/ importan @tanstack/vue-table', () => {
    const offenders = FILES.filter(
      (file) => !file.path.startsWith('data-table/') && importsTanStack(file.raw),
    )

    expect(offenders.map((file) => file.path)).toEqual([])
  })

  it('(control) src/data-table/useTableModel.ts sí lo importa', () => {
    const file = FILES.find((candidate) => candidate.path === 'data-table/useTableModel.ts')

    expect(file).toBeDefined()
    expect(importsTanStack(file!.raw)).toBe(true)
  })
})

// =========================================================================
// CA-CORE-194 · RN-CORE-38: frontera de src/data-table
// =========================================================================

const ALLOWED_ALIAS_PREFIXES = [
  'components/ui/',
  'design-system/',
  'lib/utils',
  'layouts/components/EmptyState.vue',
  'layouts/components/ErrorState.vue',
  'layouts/components/LoadingState.vue',
  'layouts/errorState',
]
const ALLOWED_ALIAS_EXACT = ['i18n', 'api', 'api/client']

function isForbiddenDataTableImport(specifier: string, fromPath: string): boolean {
  const target = toSrcRelative(specifier, fromPath)

  if (target === null) {
    return false // paquete de terceros
  }

  if (target === 'modules' || target.startsWith('modules/')) {
    return true
  }

  if (specifier.startsWith('.')) {
    // Relativo: solo dentro del propio directorio.
    return !(target === 'data-table' || target.startsWith('data-table/'))
  }

  return !(
    ALLOWED_ALIAS_EXACT.includes(target) ||
    ALLOWED_ALIAS_PREFIXES.some((prefix) => target === prefix || target.startsWith(prefix))
  )
}

describe('CA-CORE-194 (RN-CORE-38, INV-007): casos fijos', () => {
  const from = 'data-table/components/Example.vue'

  it.each([
    "import x from '@/modules/auth/api'",
    "import x from '../../modules/core/api'",
    "import x from '@/router'",
    "import x from '@/tenant/useTenantBranding'",
    "import x from '@/session/useSession'",
  ])('detecta el import prohibido en «%s»', (fixture) => {
    const specifiers = importSpecifiers(fixture)

    expect(specifiers.some((specifier) => isForbiddenDataTableImport(specifier, from))).toBe(true)
  })

  it.each([
    "import { useT } from '@/i18n'",
    "import { ApiError } from '@/api/client'",
    "import { Button } from '@/components/ui/button'",
    "import ErrorState from '@/layouts/components/ErrorState.vue'",
    "import { resolveErrorState } from '@/layouts/errorState'",
    "import { useRoute } from 'vue-router'",
    "import x from '../useTableModel'",
  ])('no detecta nada en «%s»', (fixture) => {
    const specifiers = importSpecifiers(fixture)

    expect(specifiers.some((specifier) => isForbiddenDataTableImport(specifier, from))).toBe(false)
  })
})

describe('CA-CORE-194 (RN-CORE-38): barrido de src/data-table/**', () => {
  it('ningún fichero importa @/modules/** ni destinos fuera de los permitidos de §13.3', () => {
    const offenders: string[] = []

    for (const file of FILES) {
      if (!file.path.startsWith('data-table/')) {
        continue
      }

      for (const specifier of importSpecifiers(file.cleaned)) {
        if (isForbiddenDataTableImport(specifier, file.path)) {
          offenders.push(`${file.path}: import "${specifier}"`)
        }
      }
    }

    expect(offenders).toEqual([])
  })

  it('no construye URLs de endpoints (la petición la aporta el módulo consumidor)', () => {
    const offenders = FILES.filter(
      (file) =>
        file.path.startsWith('data-table/') &&
        (/\bapiFetch\w*\s*\(/.test(file.cleaned) ||
          /['"`]\/(?:data-exports|audit-logs)\b/.test(file.cleaned)),
    )

    expect(offenders.map((file) => file.path)).toEqual([])
  })
})

// =========================================================================
// CA-CORE-200 · RN-CORE-53: toda tabla pasa por el componente
// =========================================================================

/** Las tres excepciones con las que nace la regla (`funcional.md §13.2`). Constante: añadir una cuarta a `EXCEPTIONS` hace fallar el test. */
const ORIGINAL_EXCEPTIONS = [
  'modules/auth/components/admin/MfaExemptionsArea.vue',
  'modules/auth/views/AdminSsoView.vue',
  'modules/auth/views/SessionsView.vue',
] as const

/**
 * Lista cerrada vigente. **Solo puede reducirse**: al migrar una de estas
 * pantallas al componente (`1.9b` o un paso posterior), se retira su
 * entrada de aquí (el test lo exige: una excepción que ya no incumple
 * falla).
 *   - `MfaExemptionsArea.vue` (importa `@/components/ui/table`).
 *   - `AdminSsoView.vue` (importa `@/components/ui/table`).
 *   - `SessionsView.vue` (`<table>` HTML crudo).
 * Motivo común (decisión del usuario, 2026-09-30): acotar 1.9 a un único
 * consumidor real con paridad estricta.
 */
const EXCEPTIONS: readonly string[] = [
  'modules/auth/components/admin/MfaExemptionsArea.vue',
  'modules/auth/views/AdminSsoView.vue',
  'modules/auth/views/SessionsView.vue',
]

const RAW_TABLE_RE = /<table(?=[\s>/])/

function violatesTableRule(file: { path: string; cleaned: string }): string[] {
  const reasons: string[] = []

  for (const specifier of importSpecifiers(file.cleaned)) {
    const target = toSrcRelative(specifier, file.path)

    if (
      target !== null &&
      (target === 'components/ui/table' || target.startsWith('components/ui/table/'))
    ) {
      reasons.push(`importa ${specifier}`)
    }

    if (specifier === TANSTACK) {
      reasons.push(`importa ${TANSTACK}`)
    }
  }

  if (file.path.endsWith('.vue') && RAW_TABLE_RE.test(file.cleaned)) {
    reasons.push('contiene <table')
  }

  return reasons
}

function exemptFromTableRule(path: string): boolean {
  return path.startsWith('data-table/') || path.startsWith('components/ui/')
}

/** Comprobación de la lista de excepciones, parametrizada para poder probar que sabe fallar. */
function checkExceptionList(
  exceptions: readonly string[],
  original: readonly string[],
  stillViolates: (path: string) => boolean,
): string[] {
  const problems: string[] = []

  for (const path of exceptions) {
    if (!original.includes(path)) {
      problems.push(`${path}: no es una de las tres rutas originales (la lista no puede crecer)`)
    } else if (!stillViolates(path)) {
      problems.push(`${path}: ya no incumple la regla; retírala de la lista`)
    }
  }

  return problems
}

describe('CA-CORE-200 (RN-CORE-53, OPEN-CORE-29): casos fijos', () => {
  it.each([
    ["import { Table } from '@/components/ui/table'", 'estático'],
    ["const t = await import('@/components/ui/table')", 'dinámico'],
    ["import { Table } from '../../../components/ui/table'", 'relativo'],
    ["import { Table } from '../../../components/ui/table/index'", 'relativo al índice'],
  ])('detecta el import de la tabla base «%s» (%s)', (fixture) => {
    expect(
      violatesTableRule({ path: 'modules/auth/views/X.vue', cleaned: fixture }).length,
    ).toBeGreaterThan(0)
  })

  it('detecta un import de @tanstack/vue-table y un <table crudo en un .vue', () => {
    expect(
      violatesTableRule({
        path: 'modules/x/A.vue',
        cleaned: "import { useVueTable } from '@tanstack/vue-table'",
      }).length,
    ).toBeGreaterThan(0)
    expect(
      violatesTableRule({
        path: 'modules/x/B.vue',
        cleaned: '<template><table class="a"></table></template>',
      }).length,
    ).toBeGreaterThan(0)
  })

  it('no detecta el componente <Table> ni <TableBody> de los componentes ni un <table en comentario', () => {
    expect(
      violatesTableRule({
        path: 'modules/x/C.vue',
        cleaned: '<template><Table><TableBody/></Table></template>',
      }),
    ).toEqual([])
    expect(
      violatesTableRule({ path: 'modules/x/D.vue', cleaned: stripComments('<!-- <table> -->') }),
    ).toEqual([])
  })

  it('una excepción añadida fuera de las tres rutas hace fallar la comprobación de la lista', () => {
    const problems = checkExceptionList(
      [...ORIGINAL_EXCEPTIONS, 'modules/auth/views/CuartaTabla.vue'],
      ORIGINAL_EXCEPTIONS,
      () => true,
    )

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain('CuartaTabla.vue')
  })

  it('una excepción que ya no incumple la regla hace fallar la comprobación de la lista', () => {
    const problems = checkExceptionList(
      ORIGINAL_EXCEPTIONS,
      ORIGINAL_EXCEPTIONS,
      (path) => path !== 'modules/auth/views/SessionsView.vue',
    )

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain('SessionsView.vue')
  })
})

describe('CA-CORE-200 (RN-CORE-53, OPEN-CORE-29): barrido de src/', () => {
  const fileByPath = new Map(FILES.map((file) => [file.path, file] as const))

  it('fuera de src/data-table/**, src/components/ui/** y la lista de excepciones, ninguna tabla se pinta a mano', () => {
    const offenders: string[] = []

    for (const file of FILES) {
      if (exemptFromTableRule(file.path) || EXCEPTIONS.includes(file.path)) {
        continue
      }

      const reasons = violatesTableRule(file)

      if (reasons.length > 0) {
        offenders.push(`${file.path}: ${reasons.join(', ')}`)
      }
    }

    expect(offenders).toEqual([])
  })

  it('la lista de excepciones es un subconjunto de las tres rutas originales y cada una sigue incumpliendo', () => {
    const problems = checkExceptionList(EXCEPTIONS, ORIGINAL_EXCEPTIONS, (path) => {
      const file = fileByPath.get(path)

      return file !== undefined && violatesTableRule(file).length > 0
    })

    expect(problems).toEqual([])
  })

  it('MfaComplianceArea.vue (migrada en 1.9) no está en la lista', () => {
    expect(EXCEPTIONS).not.toContain('modules/auth/components/admin/MfaComplianceArea.vue')
    expect(fileByPath.has('modules/auth/components/admin/MfaComplianceArea.vue')).toBe(true)
  })
})

// =========================================================================
// CA-CORE-192 · RN-CORE-46: la SPA nunca genera el fichero de una exportación
// =========================================================================

const CLIENT_FILE_PATTERNS: readonly { name: string; re: RegExp }[] = [
  { name: 'new Blob(', re: /new\s+Blob\s*\(/ },
  { name: 'URL.createObjectURL(', re: /URL\.createObjectURL\s*\(/ },
  { name: 'text/csv', re: /text\/csv/ },
  { name: 'application/vnd.openxmlformats', re: /application\/vnd\.openxmlformats/ },
]

/**
 * Excepciones nominales, justificadas. Solo cubren `new Blob(` y
 * `URL.createObjectURL(`: `text/csv` y `openxmlformats` no tienen ninguna.
 * Ninguna de las dos genera un listado exportado: son un documento de
 * configuración o un secreto entregado una vez, no una exportación de
 * filas.
 */
const CLIENT_FILE_EXCEPTIONS: Record<string, { tokens: readonly string[]; reason: string }> = {
  'modules/auth/views/AdminSsoProviderView.vue': {
    tokens: ['new Blob(', 'URL.createObjectURL('],
    reason:
      'Descarga el XML de metadatos SAML que el propio servidor ya devolvió (REQ-AUTH-004 1.4c); no es una exportación de listado.',
  },
  'modules/auth/components/RecoveryCodesReveal.vue': {
    tokens: ['new Blob(', 'URL.createObjectURL('],
    reason:
      'Guarda en un .txt los códigos de recuperación recién generados, mostrados una sola vez (REQ-AUTH-003 1.3); no es una exportación de listado.',
  },
}

function clientFileHits(path: string, cleaned: string): string[] {
  const allowed = new Set(CLIENT_FILE_EXCEPTIONS[path]?.tokens ?? [])

  return CLIENT_FILE_PATTERNS.filter(({ name, re }) => re.test(cleaned) && !allowed.has(name)).map(
    ({ name }) => name,
  )
}

describe('CA-CORE-192 (RN-CORE-46): casos fijos', () => {
  it.each([
    ['const b = new Blob([csv], { type: "x" })', 'new Blob('],
    ['const u = URL.createObjectURL(b)', 'URL.createObjectURL('],
    ["const t = 'text/csv;charset=utf-8'", 'text/csv'],
    [
      "const t = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'",
      'application/vnd.openxmlformats',
    ],
  ])('detecta «%s»', (fixture, expected) => {
    expect(clientFileHits('modules/x/Y.vue', fixture)).toContain(expected)
  })

  it('una excepción nominal solo exime los tokens que declara', () => {
    const path = 'modules/auth/components/RecoveryCodesReveal.vue'

    expect(clientFileHits(path, 'new Blob([a]); URL.createObjectURL(b)')).toEqual([])
    expect(clientFileHits(path, "const t = 'text/csv'")).toEqual(['text/csv'])
  })

  it('cada excepción nominal tiene su justificación', () => {
    for (const [path, exception] of Object.entries(CLIENT_FILE_EXCEPTIONS)) {
      expect(exception.reason.length, path).toBeGreaterThan(20)
    }
  })
})

describe('CA-CORE-192 (RN-CORE-46): barrido de src/data-table/** y src/modules/**', () => {
  it('ningún fichero de producción construye un fichero de exportación en el cliente', () => {
    const offenders: string[] = []

    for (const file of FILES) {
      if (!file.path.startsWith('data-table/') && !file.path.startsWith('modules/')) {
        continue
      }

      for (const hit of clientFileHits(file.path, file.cleaned)) {
        offenders.push(`${file.path}: ${hit}`)
      }
    }

    expect(offenders).toEqual([])
  })

  it('cada excepción nominal sigue haciendo falta (si el fichero ya no usa el token, se retira)', () => {
    const stale: string[] = []

    for (const [path, exception] of Object.entries(CLIENT_FILE_EXCEPTIONS)) {
      const file = FILES.find((candidate) => candidate.path === path)

      for (const token of exception.tokens) {
        const pattern = CLIENT_FILE_PATTERNS.find(({ name }) => name === token)!

        if (!file || !pattern.re.test(file.cleaned)) {
          stale.push(`${path}: ${token}`)
        }
      }
    }

    expect(stale).toEqual([])
  })
})

// =========================================================================
// CA-CORE-176 · RN-CORE-43: tableId literal, con forma y único
// =========================================================================

const TABLE_ID_FORM = /^[a-z][a-z0-9]*(?:_[a-z0-9]+)*\.[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/

interface TableIdDeclaration {
  /** `null` si no es un literal de cadena (calculado). */
  value: string | null
  source: string
}

function literalOf(expression: string): string | null {
  const match = /^\s*(?:'([^'\\]*)'|"([^"\\]*)"|`([^`$\\]*)`)\s*$/.exec(expression)

  return match ? (match[1] ?? match[2] ?? match[3] ?? null) : null
}

function extractTableIds(path: string, cleaned: string): TableIdDeclaration[] {
  const found: TableIdDeclaration[] = []

  // Plantilla: `table-id="a.b"` (literal) o `:table-id="'a.b'"` / `:table-id="expr"`.
  const attr = /(?<![\w-])(:?)(?:table-id|tableId)\s*=\s*"([^"]*)"/g
  let match: RegExpExecArray | null

  while ((match = attr.exec(cleaned))) {
    const bound = match[1] === ':'

    found.push({
      value: bound ? literalOf(match[2]!) : match[2]!,
      source: path,
    })
  }

  // Objeto en TypeScript: `tableId: 'a.b'`.
  const prop = /(?<![\w-])tableId\s*:\s*([^,}\n]+)/g

  while ((match = prop.exec(cleaned))) {
    found.push({ value: literalOf(match[1]!), source: path })
  }

  return found
}

function tableIdProblems(declarations: readonly TableIdDeclaration[]): string[] {
  const problems: string[] = []
  const seen = new Map<string, string>()

  for (const declaration of declarations) {
    if (declaration.value === null) {
      problems.push(`${declaration.source}: tableId no literal (calculado)`)
      continue
    }

    if (!TABLE_ID_FORM.test(declaration.value)) {
      problems.push(
        `${declaration.source}: «${declaration.value}» no tiene la forma <modulo>.<nombre>`,
      )
    }

    const previous = seen.get(declaration.value)

    if (previous !== undefined) {
      problems.push(`${declaration.source}: «${declaration.value}» duplicado (ya en ${previous})`)
    } else {
      seen.set(declaration.value, declaration.source)
    }
  }

  return problems
}

describe('CA-CORE-176 (RN-CORE-43, ADR-054 §5.3): casos fijos', () => {
  it('detecta un tableId duplicado', () => {
    const problems = tableIdProblems([
      ...extractTableIds('modules/a/A.vue', '<DataTable table-id="auth.tabla" />'),
      ...extractTableIds('modules/b/B.vue', '<DataTable table-id="auth.tabla" />'),
    ])

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain('duplicado')
  })

  it.each(['mfa', 'Auth.Mfa', 'auth.mfa.compliance', 'auth.', '.mfa', 'auth-x.mfa'])(
    'detecta la forma incorrecta «%s»',
    (id) => {
      const problems = tableIdProblems(
        extractTableIds('modules/a/A.vue', `<DataTable table-id="${id}" />`),
      )

      expect(problems.some((problem) => problem.includes('forma'))).toBe(true)
    },
  )

  it.each([
    '<DataTable :table-id="id" />',
    '<DataTable :table-id="`auth.${name}`" />',
    '<DataTable :table-id="\'auth.\' + name" />',
  ])('detecta el tableId no literal en «%s»', (fixture) => {
    const problems = tableIdProblems(extractTableIds('modules/a/A.vue', fixture))

    expect(problems.some((problem) => problem.includes('no literal'))).toBe(true)
  })

  it('detecta el tableId calculado en un objeto TypeScript y acepta los literales', () => {
    expect(
      tableIdProblems(extractTableIds('modules/a/a.ts', 'const o = { tableId: buildId() }')),
    ).toHaveLength(1)
    expect(
      tableIdProblems(
        extractTableIds('modules/a/a.ts', "const o = { tableId: 'auth.mfa_compliance' }"),
      ),
    ).toEqual([])
    expect(
      tableIdProblems(
        extractTableIds('modules/a/A.vue', '<DataTable :table-id="\'auth.mfa_x\'" />'),
      ),
    ).toEqual([])
  })
})

describe('CA-CORE-176 (RN-CORE-43): barrido de src/modules/**', () => {
  const declarations = FILES.filter((file) => file.path.startsWith('modules/')).flatMap((file) =>
    extractTableIds(file.path, file.cleaned),
  )

  it('(control) encuentra el tableId de la tabla migrada en 1.9', () => {
    expect(declarations.map((declaration) => declaration.value)).toContain('auth.mfa_compliance')
  })

  it('todo tableId es un literal <modulo>.<nombre> y ninguno se repite', () => {
    expect(tableIdProblems(declarations)).toEqual([])
  })
})

// =========================================================================
// INV-009 / RN-DS-24: sin literales visibles y sin vue-i18n directo
// =========================================================================

describe('INV-009 (funcional.md §13.12): src/data-table usa @/i18n y no importa vue-i18n', () => {
  it('ningún fichero de src/data-table/** importa vue-i18n', () => {
    const offenders = FILES.filter(
      (file) =>
        file.path.startsWith('data-table/') && importSpecifiers(file.cleaned).includes('vue-i18n'),
    )

    expect(offenders.map((file) => file.path)).toEqual([])
  })
})

describe('CA-CORE-201 (INV-009): la marca de valor vacío no es un literal en el código', () => {
  it("ni src/data-table/** ni MfaComplianceArea.vue contienen '—' como literal", () => {
    const paths = FILES.filter(
      (file) =>
        file.path.startsWith('data-table/') ||
        file.path === 'modules/auth/components/admin/MfaComplianceArea.vue',
    )
    const offenders = paths.filter((file) => /['"`]\u2014['"`]/.test(file.cleaned))

    expect(offenders.map((file) => file.path)).toEqual([])
  })
})
