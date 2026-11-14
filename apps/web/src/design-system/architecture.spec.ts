/**
 * `docs/design-system.md` §10, §18 (`CA-DS-029`, `CA-DS-037` a
 * `CA-DS-043`). Reglas de arquitectura del *design system*, exigibles
 * por test y no por revisión (`ADR-052 §3.3`). Toda la lógica de
 * comprobación vive **en este fichero** (`*.spec.ts`, excluido del
 * propio análisis, §10.1): así el patrón que busca una regla no se
 * detecta a sí mismo al escanear `src/`.
 *
 * §10.6: cada regla tiene casos fijos, en el propio test, que deben
 * detectarse y casos que no.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const SRC_ROOT = resolve(process.cwd(), 'src')

// --- Utilidades comunes -------------------------------------------------
//
// Lectura por `node:fs` y no por `import.meta.glob`/`?raw` de Vite:
// `@tailwindcss/vite` intercepta la carga de `.css` (incluso con `?raw`)
// y la deja vacía para este proyecto — comprobado al escribir este test:
// `tokens.css`/`style.css` volvían con longitud `0`, lo que habría hecho
// que las reglas sobre esos dos ficheros "pasaran" sin comprobar nada de
// verdad. `node:fs` no pasa por el pipeline de Vite y lee el fichero tal
// cual está en disco.

/**
 * §10.1: quita `//…`, `/* … *‍/` y `<!-- … -->`.
 *
 * Las cadenas con comilla simple o acento grave (las que usan las listas
 * de clases y los literales de este proyecto) se tratan como opacas, para
 * que un `//` dentro de ellas no se confunda con un comentario. Las
 * comillas dobles **no** se tratan como opacas a propósito: en una SFC de
 * Vue, `:class="cn(\n  // comentario\n  '…')"` envuelve JS con comillas
 * dobles de atributo HTML, y ese comentario de línea debe seguir
 * detectándose. Como contrapartida, un `//` inmediatamente precedido de
 * `:` (`http://`, `https://`) se trata como parte de una URL, no como
 * comentario — es la única ambigüedad real que introduce no tratar `"`
 * como opaca (p. ej. `xmlns="http://www.w3.org/2000/svg"`).
 */
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

    const ch = source[i]

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
  /** Ruta relativa a `src/`, con `/` (p.ej. `design-system/tokens.css`). */
  path: string
  raw: string
  cleaned: string
}

/** Directorio (relativo a `src/`) de una ruta relativa a `src/`; `''` para la raíz. */
function dirnameOf(srcRelativePath: string): string {
  const lastSlash = srcRelativePath.lastIndexOf('/')

  return lastSlash === -1 ? '' : srcRelativePath.slice(0, lastSlash)
}

/** Resuelve un especificador relativo (`./x`, `../../y`) contra un directorio, en POSIX, sin tocar disco. */
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

function listAllSourceFiles(): SourceFile[] {
  const files: SourceFile[] = []

  function walk(dir: string): void {
    for (const entry of readdirSync(dir)) {
      const abs = resolve(dir, entry)
      const stats = statSync(abs)

      if (stats.isDirectory()) {
        walk(abs)
        continue
      }

      if (!/\.(vue|ts|css)$/.test(entry) || /\.(spec|test)\.ts$/.test(entry)) {
        continue
      }

      const path = abs
        .slice(SRC_ROOT.length + 1)
        .split('\\')
        .join('/')
      const raw = readFileSync(abs, 'utf-8')

      files.push({ path, raw, cleaned: stripComments(raw) })
    }
  }

  walk(SRC_ROOT)

  return files
}

const ALL_FILES = listAllSourceFiles()

// =========================================================================
// RN-DS-16/17/18 (`CA-DS-037`, `CA-DS-038`): uso del color de marca
// =========================================================================

const PRIMARY_PROPERTY =
  '(?:border(?:-[trblxyse])?|text|outline|ring-offset|ring|decoration|divide|fill|stroke|caret|accent)'
const PRIMARY_MISUSE_RE = new RegExp(
  `(^|[\\s'"\`:])${PRIMARY_PROPERTY}-(?:sidebar-)?primary(?![\\w-])`,
)
const BG_PRIMARY_OPACITY_RE = /(^|[\s'"`:])bg-(?:sidebar-)?primary\/\d/

function findPrimaryMisuse(source: string): boolean {
  return PRIMARY_MISUSE_RE.test(source)
}

function findBgPrimaryOpacity(source: string): boolean {
  return BG_PRIMARY_OPACITY_RE.test(source)
}

/** Cadenas entrecomilladas del fichero (listas de clases de plantilla o de `cva`). */
function extractQuotedStrings(cleaned: string): string[] {
  const strings: string[] = []
  const re = /(['"`])((?:\\.|(?!\1).)*)\1/g
  let match: RegExpExecArray | null

  while ((match = re.exec(cleaned))) {
    strings.push(match[2])
  }

  return strings
}

/** RN-DS-18: toda lista de clases con `bg-(sidebar-)primary` contiene el `text-…-foreground` del mismo prefijo de variante. */
function findMissingForegroundPairing(classString: string): string[] {
  const problems: string[] = []
  const tokens = classString.split(/\s+/).filter(Boolean)

  for (const token of tokens) {
    const match = token.match(/^(.*?)bg-(sidebar-)?primary$/)

    if (!match) {
      continue
    }

    const [, prefix, sidebarPart] = match
    const expected = `${prefix}text-${sidebarPart ?? ''}primary-foreground`

    if (!tokens.includes(expected)) {
      problems.push(`"${token}" sin "${expected}" en la misma lista de clases`)
    }
  }

  return problems
}

describe('RN-DS-16/17 — CA-DS-037/038: casos fijos (§10.6)', () => {
  it.each([
    'hover:text-primary',
    'data-checked:border-primary',
    'ring-primary/50',
    'border-x-primary',
    'text-sidebar-primary',
  ])('detecta el uso prohibido en "%s"', (fixture) => {
    expect(findPrimaryMisuse(fixture)).toBe(true)
  })

  it.each([
    'text-primary-foreground',
    'text-primary-on-background',
    'border-primary-on-background',
  ])('NO detecta "%s" (sustituto válido)', (fixture) => {
    expect(findPrimaryMisuse(fixture)).toBe(false)
  })

  it.each(['bg-primary/80', '[a]:hover:bg-primary/80', 'bg-sidebar-primary/40'])(
    'detecta bg-(sidebar-)primary con opacidad en "%s"',
    (fixture) => {
      expect(findBgPrimaryOpacity(fixture)).toBe(true)
    },
  )

  it('NO detecta bg-primary sin opacidad', () => {
    expect(findBgPrimaryOpacity('bg-primary text-primary-foreground')).toBe(false)
  })

  it('detecta bg-primary sin su text-primary-foreground emparejado', () => {
    expect(findMissingForegroundPairing('bg-primary text-white')).toHaveLength(1)
  })

  it('NO detecta nada si el emparejamiento es correcto, con o sin variante', () => {
    expect(findMissingForegroundPairing('bg-primary text-primary-foreground')).toHaveLength(0)
    expect(
      findMissingForegroundPairing('data-checked:bg-primary data-checked:text-primary-foreground'),
    ).toHaveLength(0)
    expect(
      findMissingForegroundPairing('bg-sidebar-primary text-sidebar-primary-foreground'),
    ).toHaveLength(0)
  })
})

describe('RN-DS-16/17/18 — CA-DS-037/038: barrido de src/', () => {
  it('ningún fichero usa las utilidades prohibidas de RN-DS-16', () => {
    const offenders = ALL_FILES.filter((f) => findPrimaryMisuse(f.cleaned))
    expect(offenders.map((f) => f.path)).toEqual([])
  })

  it('ningún fichero usa bg-(sidebar-)primary con opacidad (RN-DS-17)', () => {
    const offenders = ALL_FILES.filter((f) => findBgPrimaryOpacity(f.cleaned))
    expect(offenders.map((f) => f.path)).toEqual([])
  })

  it('todo bg-(sidebar-)primary va con su text-…-foreground (RN-DS-18)', () => {
    const offenders: string[] = []

    for (const file of ALL_FILES) {
      for (const str of extractQuotedStrings(file.cleaned)) {
        if (!/\bbg-(?:sidebar-)?primary\b/.test(str)) {
          continue
        }

        for (const problem of findMissingForegroundPairing(str)) {
          offenders.push(`${file.path}: ${problem}`)
        }
      }
    }

    expect(offenders).toEqual([])
  })
})

// =========================================================================
// RN-DS-19 (`CA-DS-039`): colores literales
// =========================================================================

const HEX_LITERAL_RE = /[\s'"`([,]#(?:[0-9A-Fa-f]{3,4}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})\b/
const COLOR_FUNCTION_RE = /\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\(/
const PALETTE_COLORS = [
  'slate',
  'gray',
  'zinc',
  'neutral',
  'stone',
  'red',
  'orange',
  'amber',
  'yellow',
  'lime',
  'green',
  'emerald',
  'teal',
  'cyan',
  'sky',
  'blue',
  'indigo',
  'violet',
  'purple',
  'fuchsia',
  'pink',
  'rose',
]
const SHADES = ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950']
const COLOR_UTILITY_PROPS = [
  'bg',
  'text',
  'border(?:-[trblxyse])?',
  'ring-offset',
  'ring',
  'outline',
  'decoration',
  'divide',
  'fill',
  'stroke',
  'caret',
  'accent',
  'from',
  'via',
  'to',
  'shadow',
  'placeholder',
]
const PALETTE_CLASS_RE = new RegExp(
  `(^|[\\s'"\`:])(?:${COLOR_UTILITY_PROPS.join('|')})-(?:${PALETTE_COLORS.join('|')})-(?:${SHADES.join('|')})(?![\\w-])`,
)
const WHITE_BLACK_CLASS_RE = new RegExp(
  `(^|[\\s'"\`:])(?:${COLOR_UTILITY_PROPS.join('|')})-(?:white|black)(?![\\w-])`,
)

function findLiteralColor(source: string): boolean {
  return (
    HEX_LITERAL_RE.test(source) ||
    COLOR_FUNCTION_RE.test(source) ||
    PALETTE_CLASS_RE.test(source) ||
    WHITE_BLACK_CLASS_RE.test(source)
  )
}

const LITERAL_COLOR_EXCEPTIONS = new Set([
  'design-system/tokens.css',
  'design-system/color/surfaces.ts',
])

describe('RN-DS-19 — CA-DS-039: casos fijos (§10.6)', () => {
  it.each([
    "'#1d4ed8'",
    'rgb(0 0 0)',
    'oklch(0.5 0.1 200)',
    'class="bg-[#fff]"',
    'class="text-blue-600"',
    'class="bg-white"',
  ])('detecta el color literal en %s', (fixture) => {
    expect(findLiteralColor(fixture)).toBe(true)
  })

  it.each(['class="bg-transparent"', 'class="text-current"', 'id="app"'])(
    'NO detecta nada en %s',
    (fixture) => {
      expect(findLiteralColor(fixture)).toBe(false)
    },
  )

  it('un "#251" dentro de un comentario no cuenta (se limpia antes de analizar)', () => {
    const source = '// ver issue #251\nconst x = 1'
    expect(findLiteralColor(stripComments(source))).toBe(false)
  })
})

describe('RN-DS-19 — CA-DS-039: barrido de src/ (con las excepciones nominales)', () => {
  it('ningún fichero fuera de las excepciones nominales usa un color literal', () => {
    const offenders = ALL_FILES.filter(
      (f) => !LITERAL_COLOR_EXCEPTIONS.has(f.path) && findLiteralColor(f.cleaned),
    )
    expect(offenders.map((f) => f.path)).toEqual([])
  })
})

// =========================================================================
// RN-DS-20 (`CA-DS-042`): frontera del design system
// =========================================================================

const FRONTIER_DIRS = ['design-system', 'components/ui']
const FORBIDDEN_ALIAS_PREFIXES = [
  'modules/',
  'api/',
  'tenant/',
  'router/',
  'i18n/',
  'layouts/',
  'views/',
]
const FORBIDDEN_BARE_PACKAGES = new Set(['vue-router', 'vue-i18n'])

function extractImportSpecifiers(cleaned: string): string[] {
  const specifiers: string[] = []
  const patterns = [
    /import\s+(?:type\s+)?(?:[\w*${},\s]+\s+from\s+)?['"]([^'"]+)['"]/g,
    /import\(\s*['"]([^'"]+)['"]\s*\)/g,
    /export\s+(?:\*|type\s+\*|\{[^}]*\})\s+from\s+['"]([^'"]+)['"]/g,
  ]

  for (const pattern of patterns) {
    let match: RegExpExecArray | null

    while ((match = pattern.exec(cleaned))) {
      specifiers.push(match[1])
    }
  }

  return specifiers
}

function isForbiddenFrontierImport(importPath: string, fileSrcRelativePath: string): boolean {
  if (FORBIDDEN_BARE_PACKAGES.has(importPath)) {
    return true
  }

  let srcRelative: string | null = null

  if (importPath.startsWith('@/')) {
    srcRelative = importPath.slice(2)
  } else if (importPath.startsWith('.')) {
    srcRelative = resolveRelativeSpecifier(dirnameOf(fileSrcRelativePath), importPath)
  }

  if (srcRelative === null) {
    return false // paquete de terceros: permitido.
  }

  return FORBIDDEN_ALIAS_PREFIXES.some(
    (prefix) => srcRelative === prefix.slice(0, -1) || srcRelative.startsWith(prefix),
  )
}

describe('RN-DS-20 — CA-DS-042: casos fijos (§10.6)', () => {
  const fileInThemeDir = 'design-system/theme/example.ts'

  it.each([
    ["import x from '@/modules/core/api'", fileInThemeDir],
    ["import { useT } from '@/i18n'", fileInThemeDir],
    ["import { useRoute } from 'vue-router'", fileInThemeDir],
    ["import y from '../../tenant/useTenantBranding'", fileInThemeDir],
  ] as const)('detecta el import prohibido en "%s"', (fixture, filePath) => {
    const specifiers = extractImportSpecifiers(fixture)
    expect(specifiers.some((s) => isForbiddenFrontierImport(s, filePath))).toBe(true)
  })

  it.each([
    "import { cn } from '@/lib/utils'",
    "import { computed } from 'vue'",
    "import { useColorMode } from '@vueuse/core'",
  ])('NO detecta nada en "%s"', (fixture) => {
    const specifiers = extractImportSpecifiers(fixture)
    expect(specifiers.some((s) => isForbiddenFrontierImport(s, fileInThemeDir))).toBe(false)
  })
})

describe('RN-DS-20 — CA-DS-042: barrido de design-system/** y components/ui/**', () => {
  it('ningún fichero de la frontera importa lo prohibido', () => {
    const offenders: string[] = []

    for (const file of ALL_FILES) {
      if (!FRONTIER_DIRS.some((dir) => file.path.startsWith(`${dir}/`))) {
        continue
      }

      for (const specifier of extractImportSpecifiers(file.cleaned)) {
        if (isForbiddenFrontierImport(specifier, file.path)) {
          offenders.push(`${file.path}: import "${specifier}"`)
        }
      }
    }

    expect(offenders).toEqual([])
  })
})

// =========================================================================
// RN-DS-21 (`CA-DS-040`), RN-DS-22 (`CA-DS-029`), RN-DS-01 (`CA-DS-041`):
// reglas de un solo punto de entrada
// =========================================================================

function findWordOccurrences(word: string, source: string): boolean {
  return new RegExp(`\\b${word}\\b`).test(source)
}

describe('RN-DS-21 — CA-DS-040: useColorMode/usePreferredDark/usePreferredColorScheme', () => {
  const ALLOWED_FILE = 'design-system/color-mode/useColorScheme.ts'
  const IDENTIFIERS = ['useColorMode', 'usePreferredDark', 'usePreferredColorScheme']

  it('el fichero permitido sí los usa (control: el test sabe detectar)', () => {
    const file = ALL_FILES.find((f) => f.path === ALLOWED_FILE)
    expect(file).toBeDefined()
    expect(findWordOccurrences('useColorMode', file!.cleaned)).toBe(true)
  })

  it('ningún otro fichero los importa', () => {
    const offenders: string[] = []

    for (const file of ALL_FILES) {
      if (file.path === ALLOWED_FILE) {
        continue
      }

      for (const identifier of IDENTIFIERS) {
        if (findWordOccurrences(identifier, file.cleaned)) {
          offenders.push(`${file.path}: ${identifier}`)
        }
      }
    }

    expect(offenders).toEqual([])
  })
})

describe('RN-DS-22 — CA-DS-029: getTenantBranding', () => {
  const ALLOWED_PREFIXES = ['modules/core/api/', 'tenant/']

  it('solo aparece en modules/core/api/** y tenant/**', () => {
    const offenders = ALL_FILES.filter(
      (f) =>
        !ALLOWED_PREFIXES.some((p) => f.path.startsWith(p)) &&
        findWordOccurrences('getTenantBranding', f.cleaned),
    )
    expect(offenders.map((f) => f.path)).toEqual([])
  })

  it('sí aparece en tenant/useTenantBranding.ts (control)', () => {
    const file = ALL_FILES.find((f) => f.path === 'tenant/useTenantBranding.ts')
    expect(file).toBeDefined()
    expect(findWordOccurrences('getTenantBranding', file!.cleaned)).toBe(true)
  })
})

describe('RN-DS-01 — CA-DS-041: --brand-', () => {
  const ALLOWED_PREFIXES = ['design-system/theme/', 'design-system/tokens.css']

  it('la cadena --brand- solo aparece en design-system/theme/** y tokens.css', () => {
    const offenders = ALL_FILES.filter(
      (f) => !ALLOWED_PREFIXES.some((p) => f.path.startsWith(p)) && f.cleaned.includes('--brand-'),
    )
    expect(offenders.map((f) => f.path)).toEqual([])
  })
})

// =========================================================================
// RN-DS-23 (`CA-DS-043`): indicador de foco
// =========================================================================

describe('RN-DS-23 — CA-DS-043: focus-visible:border-ring', () => {
  const FILES_REQUIRING_FOCUS_RING = [
    'components/ui/button/index.ts',
    'components/ui/badge/index.ts',
    'components/ui/input/Input.vue',
    'components/ui/textarea/Textarea.vue',
    'components/ui/select/SelectTrigger.vue',
    'components/ui/radio-group/RadioGroupItem.vue',
  ]

  it.each(FILES_REQUIRING_FOCUS_RING)('%s contiene focus-visible:border-ring', (path) => {
    const file = ALL_FILES.find((f) => f.path === path)
    expect(file).toBeDefined()
    expect(file!.cleaned).toContain('focus-visible:border-ring')
  })
})
