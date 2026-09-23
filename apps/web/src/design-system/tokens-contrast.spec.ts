/**
 * `docs/design-system.md` §11, §18 (`CA-DS-001`, `CA-DS-002`, `CA-DS-003`,
 * `CA-DS-004`, `CA-DS-005`, `CA-DS-006`, `CA-DS-007`, `CA-DS-010`).
 * Lee `tokens.css` como texto y comprueba el contraste de todos los
 * pares semánticos estáticos (§11) en ambos modos — el caso con marca lo
 * cubre `deriveOnBackground.spec.ts` (§6).
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import type { Oklch, Rgb } from './color/color'
import {
  compositeOver,
  linearToRgb,
  oklabToLinearRgb,
  oklchToOklab,
  rgbToLinear,
} from './color/color'
import { contrastFromLuminance, relativeLuminanceOfOklch } from './color/contrast'

const realTokensCss = readFileSync(resolve(process.cwd(), 'src/design-system/tokens.css'), 'utf-8')
const realStyleCss = readFileSync(resolve(process.cwd(), 'src/style.css'), 'utf-8')

function stripComments(css: string): string {
  return css.replace(/\/\*[\s\S]*?\*\//g, '')
}

function extractTopBlock(css: string, selector: string): string {
  const marker = `${selector} {`
  const start = css.indexOf(marker)

  if (start === -1) {
    throw new Error(`No se encontró el bloque "${selector}"`)
  }

  const bodyStart = start + marker.length
  const end = css.indexOf('\n}', bodyStart)

  return css.slice(bodyStart, end)
}

/** `--nombre: valor;` → Map. Ignora líneas vacías/sin `:`. */
function parseDeclarations(block: string): Map<string, string> {
  const map = new Map<string, string>()

  for (const rawLine of block.split(';')) {
    const line = rawLine.trim()

    if (!line.startsWith('--')) {
      continue
    }

    const colonIndex = line.indexOf(':')

    if (colonIndex === -1) {
      continue
    }

    const name = line.slice(0, colonIndex).trim()
    const value = line.slice(colonIndex + 1).trim()

    map.set(name, value)
  }

  return map
}

interface ResolvedColor {
  oklch: Oklch
  /** `1` si es opaco. */
  alpha: number
}

const VAR_WITH_FALLBACK_RE = /^var\(\s*(--[\w-]+)\s*,\s*(.+)\)$/
const VAR_RE = /^var\(\s*(--[\w-]+)\s*\)$/
const OKLCH_RE = /^oklch\(\s*([0-9.]+)\s+([0-9.]+)\s+([0-9.]+)\s*(?:\/\s*([0-9.]+)%\s*)?\)$/

/**
 * Resuelve el valor crudo de un semántico a un color final. Cualquier
 * formato que no sea `var(--brand-…, X)`, `var(--otro)` u
 * `oklch(L C H[ / A%])` hace fallar el test (`CA-DS-006`): la hoja tiene
 * que seguir siendo analizable con este resolutor, cerrado a propósito.
 */
function resolveValue(
  rawValue: string,
  declarations: Map<string, string>,
  seen: Set<string>,
): ResolvedColor {
  const withFallback = rawValue.match(VAR_WITH_FALLBACK_RE)

  if (withFallback) {
    // El caso estático (sin marca aplicada) es el valor de respaldo.
    return resolveValue(withFallback[2].trim(), declarations, seen)
  }

  const varRef = rawValue.match(VAR_RE)

  if (varRef) {
    const name = varRef[1]

    if (seen.has(name)) {
      throw new Error(`Referencia circular resolviendo ${name}`)
    }

    const referenced = declarations.get(name)

    if (referenced === undefined) {
      throw new Error(`"${name}" no está declarado en este bloque`)
    }

    return resolveValue(referenced, declarations, new Set(seen).add(name))
  }

  const oklchMatch = rawValue.match(OKLCH_RE)

  if (oklchMatch) {
    const [, L, C, h, alphaPercent] = oklchMatch

    return {
      oklch: { L: Number(L), C: Number(C), h: Number(h) },
      alpha: alphaPercent === undefined ? 1 : Number(alphaPercent) / 100,
    }
  }

  throw new Error(`Formato de token no admitido: "${rawValue}"`)
}

function resolveSemantic(name: string, declarations: Map<string, string>): ResolvedColor {
  const raw = declarations.get(`--${name}`)

  if (raw === undefined) {
    throw new Error(`El semántico --${name} no está declarado`)
  }

  return resolveValue(raw, declarations, new Set([`--${name}`]))
}

/** Luminancia relativa de WCAG 2.x de un color sRGB ya compuesto (0-1, no lineal). */
function relativeLuminanceOfSrgb(rgb: Rgb): number {
  const { r, g, b } = rgbToLinear(rgb)

  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

function toSrgb(oklch: Oklch): Rgb {
  return linearToRgb(oklabToLinearRgb(oklchToOklab(oklch)))
}

/** "Como hace el navegador" (§11): compone en sRGB no lineal (gamma). */
function compositeOverSurface(fgOklch: Oklch, alpha: number, surface: Oklch): Rgb {
  return compositeOver(toSrgb(fgOklch), alpha, toSrgb(surface))
}

function luminanceOf(color: ResolvedColor): number {
  return relativeLuminanceOfOklch(color.oklch)
}

function contrast(a: number, b: number): number {
  return contrastFromLuminance(a, b)
}

interface Mode {
  name: 'light' | 'dark'
  declarations: Map<string, string>
}

function buildModes(tokensCss: string): Mode[] {
  const cleaned = stripComments(tokensCss)

  return [
    { name: 'light', declarations: parseDeclarations(extractTopBlock(cleaned, ':root')) },
    { name: 'dark', declarations: parseDeclarations(extractTopBlock(cleaned, '.dark')) },
  ]
}

// --- CA-DS-001/002/003/007: forma general de la hoja -----------------

describe('tokens.css — CA-DS-001: cada semántico definido en :root y .dark', () => {
  const REQUIRED_SEMANTICS = [
    'background',
    'foreground',
    'card',
    'card-foreground',
    'popover',
    'popover-foreground',
    'primary',
    'primary-foreground',
    'primary-on-background',
    'secondary',
    'secondary-foreground',
    'muted',
    'muted-foreground',
    'accent',
    'accent-foreground',
    'destructive',
    'success',
    'success-foreground',
    'warning',
    'warning-foreground',
    'info',
    'info-foreground',
    'border',
    'input',
    'ring',
    'sidebar',
    'sidebar-foreground',
    'sidebar-primary',
    'sidebar-primary-foreground',
    'sidebar-accent',
    'sidebar-accent-foreground',
    'sidebar-border',
    'sidebar-ring',
  ]

  const modes = buildModes(realTokensCss)

  it.each(REQUIRED_SEMANTICS)('--%s está declarado en :root y en .dark', (name) => {
    for (const mode of modes) {
      expect(mode.declarations.has(`--${name}`)).toBe(true)
    }
  })

  it('style.css no define ningún semántico de color (solo importa tokens.css y mapea)', () => {
    for (const name of REQUIRED_SEMANTICS) {
      expect(realStyleCss).not.toMatch(new RegExp(`--${name}:\\s*oklch`))
    }
    expect(realStyleCss).toContain("@import './design-system/tokens.css'")
  })
})

describe('tokens.css — CA-DS-002: mapeo a utilidades en @theme inline', () => {
  const EXPECTED = [
    'primary-on-background',
    'success',
    'success-foreground',
    'warning',
    'warning-foreground',
    'info',
    'info-foreground',
  ]

  it.each(EXPECTED)('--color-%s: var(--%s) existe en style.css', (name) => {
    expect(realStyleCss).toContain(`--color-${name}: var(--${name});`)
  })

  it('--default-transition-duration usa --motion-duration-normal', () => {
    expect(realStyleCss).toContain('--default-transition-duration: var(--motion-duration-normal);')
  })
})

describe('tokens.css — CA-DS-003: solo los semánticos de "primario" dependen de la marca', () => {
  const modes = buildModes(realTokensCss)
  const BRAND_DEPENDENT = [
    'primary',
    'primary-foreground',
    'sidebar-primary',
    'sidebar-primary-foreground',
  ]

  it.each(BRAND_DEPENDENT)('--%s tiene la forma var(--brand-…, <neutro>)', (name) => {
    for (const mode of modes) {
      const raw = mode.declarations.get(`--${name}`) ?? ''
      expect(raw).toMatch(/^var\(--brand-/)
    }
  })

  it('--primary-foreground usa --brand-primary-foreground (nunca --brand-primary)', () => {
    for (const mode of modes) {
      const raw = mode.declarations.get('--primary-foreground') ?? ''
      expect(raw).toMatch(/^var\(--brand-primary-foreground,/)
    }
  })

  it('--ring y --primary-on-background dependen indirectamente, y ningún otro semántico referencia --brand-', () => {
    for (const mode of modes) {
      for (const [name, value] of mode.declarations) {
        if (
          BRAND_DEPENDENT.includes(name.slice(2)) ||
          name === '--primary-on-background' ||
          name === '--ring' ||
          name === '--sidebar-ring'
        ) {
          continue
        }

        expect(value.includes('--brand-')).toBe(false)
      }
    }
  })
})

describe('tokens.css — CA-DS-007: color-scheme y el bloque previo a JavaScript', () => {
  it('color-scheme: light en :root, dark en .dark', () => {
    const modes = buildModes(realTokensCss)
    expect(modes[0].declarations.get('color-scheme')).toBeUndefined() // no es "--color-scheme"

    const cleaned = stripComments(realTokensCss)
    const rootBlock = extractTopBlock(cleaned, ':root')
    const darkBlock = extractTopBlock(cleaned, '.dark')

    expect(rootBlock).toMatch(/color-scheme:\s*light;/)
    expect(darkBlock).toMatch(/color-scheme:\s*dark;/)
  })

  it('el bloque prefers-color-scheme usa :root:not(.light):not(.dark) y copia background/foreground de .dark', () => {
    const cleaned = stripComments(realTokensCss)

    expect(cleaned).toContain(':root:not(.light):not(.dark)')

    const mediaStart = cleaned.indexOf('@media (prefers-color-scheme: dark)')
    const mediaBlock = cleaned.slice(mediaStart, mediaStart + 400)

    expect(mediaBlock).toMatch(/color-scheme:\s*dark;/)

    const modes = buildModes(realTokensCss)
    const dark = modes.find((m) => m.name === 'dark')!

    const bgMatch = mediaBlock.match(/--background:\s*(oklch\([^)]*\))/)
    const fgMatch = mediaBlock.match(/--foreground:\s*(oklch\([^)]*\))/)

    expect(bgMatch?.[1]).toBe(dark.declarations.get('--background'))
    expect(fgMatch?.[1]).toBe(dark.declarations.get('--foreground'))
  })
})

describe('tokens.css — CA-DS-008: prefers-reduced-motion', () => {
  it('pone los tres --motion-duration-* a 0ms y las cuatro declaraciones universales', () => {
    const cleaned = stripComments(realTokensCss)
    const start = cleaned.indexOf('@media (prefers-reduced-motion: reduce)')
    const block = cleaned.slice(start)

    expect(block).toMatch(/--motion-duration-fast:\s*0ms;/)
    expect(block).toMatch(/--motion-duration-normal:\s*0ms;/)
    expect(block).toMatch(/--motion-duration-slow:\s*0ms;/)
    expect(block).toMatch(/animation-duration:\s*0\.01ms\s*!important;/)
    expect(block).toMatch(/animation-iteration-count:\s*1\s*!important;/)
    expect(block).toMatch(/transition-duration:\s*0\.01ms\s*!important;/)
    expect(block).toMatch(/scroll-behavior:\s*auto\s*!important;/)
  })
})

describe('tokens.css — CA-DS-010: sin fuentes externas', () => {
  it('sin @font-face ni @import de fuentes externas en src/', () => {
    expect(realTokensCss).not.toMatch(/@font-face/)
    expect(realStyleCss).not.toMatch(/@font-face/)
    expect(realStyleCss).not.toMatch(/fonts\.googleapis|fonts\.gstatic/)
  })

  it('--font-sans es la pila del sistema de §4.7', () => {
    expect(realStyleCss).toContain("--font-sans: system-ui, 'Segoe UI', Roboto, sans-serif;")
  })
})

// --- CA-DS-004/005/006: contraste real de §11 -------------------------

interface Pair {
  fg: string
  bg: string
  threshold: number
}

const TEXT_PAIRS: Pair[] = [
  { fg: 'foreground', bg: 'background', threshold: 4.5 },
  { fg: 'foreground', bg: 'muted', threshold: 4.5 },
  { fg: 'foreground', bg: 'secondary', threshold: 4.5 },
  { fg: 'foreground', bg: 'accent', threshold: 4.5 },
  { fg: 'card-foreground', bg: 'card', threshold: 4.5 },
  { fg: 'popover-foreground', bg: 'popover', threshold: 4.5 },
  { fg: 'primary-foreground', bg: 'primary', threshold: 4.5 },
  { fg: 'secondary-foreground', bg: 'secondary', threshold: 4.5 },
  { fg: 'accent-foreground', bg: 'accent', threshold: 4.5 },
  { fg: 'muted-foreground', bg: 'background', threshold: 4.5 },
  { fg: 'muted-foreground', bg: 'card', threshold: 4.5 },
  { fg: 'muted-foreground', bg: 'popover', threshold: 4.5 },
  { fg: 'muted-foreground', bg: 'muted', threshold: 4.5 },
  { fg: 'destructive', bg: 'background', threshold: 4.5 },
  { fg: 'destructive', bg: 'card', threshold: 4.5 },
  { fg: 'destructive', bg: 'popover', threshold: 4.5 },
  { fg: 'destructive', bg: 'muted', threshold: 4.5 },
  { fg: 'success', bg: 'background', threshold: 4.5 },
  { fg: 'success', bg: 'card', threshold: 4.5 },
  { fg: 'success', bg: 'popover', threshold: 4.5 },
  { fg: 'success', bg: 'muted', threshold: 4.5 },
  { fg: 'success-foreground', bg: 'success', threshold: 4.5 },
  { fg: 'warning', bg: 'background', threshold: 4.5 },
  { fg: 'warning', bg: 'card', threshold: 4.5 },
  { fg: 'warning', bg: 'popover', threshold: 4.5 },
  { fg: 'warning', bg: 'muted', threshold: 4.5 },
  { fg: 'warning-foreground', bg: 'warning', threshold: 4.5 },
  { fg: 'info', bg: 'background', threshold: 4.5 },
  { fg: 'info', bg: 'card', threshold: 4.5 },
  { fg: 'info', bg: 'popover', threshold: 4.5 },
  { fg: 'info', bg: 'muted', threshold: 4.5 },
  { fg: 'info-foreground', bg: 'info', threshold: 4.5 },
  { fg: 'sidebar-foreground', bg: 'sidebar', threshold: 4.5 },
  { fg: 'sidebar-accent-foreground', bg: 'sidebar-accent', threshold: 4.5 },
  { fg: 'sidebar-primary-foreground', bg: 'sidebar-primary', threshold: 4.5 },
  { fg: 'primary-on-background', bg: 'background', threshold: 4.5 },
  { fg: 'primary-on-background', bg: 'card', threshold: 4.5 },
  { fg: 'primary-on-background', bg: 'popover', threshold: 4.5 },
  { fg: 'primary-on-background', bg: 'sidebar', threshold: 4.5 },
  { fg: 'primary-on-background', bg: 'muted', threshold: 4.5 },
  { fg: 'primary-on-background', bg: 'secondary', threshold: 4.5 },
  { fg: 'primary-on-background', bg: 'accent', threshold: 4.5 },
  { fg: 'primary-on-background', bg: 'sidebar-accent', threshold: 4.5 },
  { fg: 'ring', bg: 'background', threshold: 3 },
  { fg: 'ring', bg: 'card', threshold: 3 },
  { fg: 'ring', bg: 'popover', threshold: 3 },
  { fg: 'ring', bg: 'sidebar', threshold: 3 },
  { fg: 'ring', bg: 'muted', threshold: 3 },
  { fg: 'ring', bg: 'secondary', threshold: 3 },
  { fg: 'ring', bg: 'accent', threshold: 3 },
  { fg: 'ring', bg: 'sidebar-accent', threshold: 3 },
  { fg: 'input', bg: 'background', threshold: 3 },
]

describe('tokens.css — CA-DS-004: pares semánticos estáticos, ambos modos', () => {
  const modes = buildModes(realTokensCss)

  for (const mode of modes) {
    describe(`modo ${mode.name}`, () => {
      it.each(TEXT_PAIRS)('$fg vs $bg ≥ $threshold:1', ({ fg, bg, threshold }) => {
        const fgColor = resolveSemantic(fg, mode.declarations)
        const bgColor = resolveSemantic(bg, mode.declarations)

        const ratio = contrast(luminanceOf(fgColor), luminanceOf(bgColor))

        expect(ratio).toBeGreaterThanOrEqual(threshold)
      })
    })

    it(`${mode.name}: destructive (texto) vs destructive/10%(claro)-20%(oscuro) sobre background`, () => {
      const destructive = resolveSemantic('destructive', mode.declarations)
      const background = resolveSemantic('background', mode.declarations)
      const alpha = mode.name === 'light' ? 0.1 : 0.2

      const composited = compositeOverSurface(destructive.oklch, alpha, background.oklch)
      const ratio = contrast(luminanceOf(destructive), relativeLuminanceOfSrgb(composited))

      expect(ratio).toBeGreaterThanOrEqual(4.5)
    })
  }
})

describe('tokens.css — CA-DS-005: detecta un incumplimiento real', () => {
  it('con --muted-foreground: oklch(0.7 0 0) en :root, el par muted-foreground/muted incumple', () => {
    const brokenCss = realTokensCss.replace(
      '--muted-foreground: oklch(0.54 0 0);',
      '--muted-foreground: oklch(0.7 0 0);',
    )
    expect(brokenCss).not.toBe(realTokensCss)

    const modes = buildModes(brokenCss)
    const light = modes.find((m) => m.name === 'light')!

    const fg = resolveSemantic('muted-foreground', light.declarations)
    const bg = resolveSemantic('muted', light.declarations)
    const ratio = contrast(luminanceOf(fg), luminanceOf(bg))

    expect(ratio).toBeLessThan(4.5)
  })
})

describe('tokens.css — CA-DS-006: formato no admitido hace fallar el análisis', () => {
  it('un semántico en hsl(...) no resuelve (el resolutor lanza, nombrando el semántico)', () => {
    const declarations = new Map<string, string>([['--destructive', 'hsl(0 0% 50%)']])

    expect(() => resolveSemantic('destructive', declarations)).toThrow(
      /Formato de token no admitido/,
    )
  })
})
