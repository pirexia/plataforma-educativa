/**
 * `docs/design-system.md` §6.1. Conversión de color pura: hex ↔ sRGB ↔
 * lineal ↔ OKLab ↔ OKLCH, con las matrices de Björn Ottosson de
 * CSS Color 4 (https://bottosson.github.io/posts/oklab/), más la
 * comprobación de gama sRGB. Sin DOM, sin estado, sin E/S — la usa
 * `deriveOnBackground.ts` (`@vitest-environment node`).
 *
 * `ADR-052 §3.3`: se acepta duplicar aquí la fórmula de contraste/color
 * que ya existe en el servidor (`INV-010` sigue en servidor: esto es
 * cálculo de presentación, nunca validación de negocio).
 */

export interface Rgb {
  r: number
  g: number
  b: number
}

export interface Oklab {
  L: number
  a: number
  b: number
}

export interface Oklch {
  L: number
  C: number
  /** Grados, en `[0, 360)`. */
  h: number
}

const HEX_RE = /^#[0-9A-Fa-f]{6}$/

export function isValidHex(value: string): boolean {
  return HEX_RE.test(value)
}

function assertValidHex(value: string): void {
  if (!isValidHex(value)) {
    throw new TypeError(`Color hexadecimal inválido: ${JSON.stringify(value)}`)
  }
}

export function hexToRgb(hex: string): Rgb {
  assertValidHex(hex)

  const value = hex.slice(1)

  return {
    r: parseInt(value.slice(0, 2), 16) / 255,
    g: parseInt(value.slice(2, 4), 16) / 255,
    b: parseInt(value.slice(4, 6), 16) / 255,
  }
}

function clamp01(value: number): number {
  return Math.min(1, Math.max(0, value))
}

export function rgbToHex({ r, g, b }: Rgb): string {
  const toHexPair = (channel: number): string =>
    Math.round(clamp01(channel) * 255)
      .toString(16)
      .padStart(2, '0')
      .toUpperCase()

  return `#${toHexPair(r)}${toHexPair(g)}${toHexPair(b)}`
}

/**
 * Umbral de la función de transferencia sRGB. WCAG 2.2 publica `0.04045`
 * como umbral de linealización, pero `ContrastRatioCalculator` del
 * servidor (`apps/api/app/Modules/Core/Application/ContrastRatioCalculator.php`)
 * usa `0.03928` (el valor de la especificación sRGB/WCAG 2.0 original).
 * Se reproduce aquí el mismo umbral que el servidor para que cliente y
 * servidor calculen idéntico contraste sobre el mismo color
 * (`docs/design-system.md` §6.1).
 */
const SRGB_LINEARIZE_THRESHOLD = 0.03928

export function srgbChannelToLinear(channel: number): number {
  return channel <= SRGB_LINEARIZE_THRESHOLD ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4
}

export function linearChannelToSrgb(channel: number): number {
  const clamped = clamp01(channel)
  const inverseThreshold = SRGB_LINEARIZE_THRESHOLD / 12.92

  return clamped <= inverseThreshold ? clamped * 12.92 : 1.055 * clamped ** (1 / 2.4) - 0.055
}

export function rgbToLinear({ r, g, b }: Rgb): Rgb {
  return { r: srgbChannelToLinear(r), g: srgbChannelToLinear(g), b: srgbChannelToLinear(b) }
}

export function linearToRgb({ r, g, b }: Rgb): Rgb {
  return { r: linearChannelToSrgb(r), g: linearChannelToSrgb(g), b: linearChannelToSrgb(b) }
}

/** sRGB lineal (D65) → LMS → OKLab. Matrices de Ottosson, CSS Color 4. */
export function linearRgbToOklab({ r, g, b }: Rgb): Oklab {
  const l = 0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b
  const m = 0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b
  const s = 0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b

  const l_ = Math.cbrt(l)
  const m_ = Math.cbrt(m)
  const s_ = Math.cbrt(s)

  return {
    L: 0.2104542553 * l_ + 0.793617785 * m_ - 0.0040720468 * s_,
    a: 1.9779984951 * l_ - 2.428592205 * m_ + 0.4505937099 * s_,
    b: 0.0259040371 * l_ + 0.7827717662 * m_ - 0.808675766 * s_,
  }
}

/** OKLab → LMS → sRGB lineal (D65). Inversa de `linearRgbToOklab`. */
export function oklabToLinearRgb({ L, a, b }: Oklab): Rgb {
  const l_ = L + 0.3963377774 * a + 0.2158037573 * b
  const m_ = L - 0.1055613458 * a - 0.0638541728 * b
  const s_ = L - 0.0894841775 * a - 1.291485548 * b

  const l = l_ ** 3
  const m = m_ ** 3
  const s = s_ ** 3

  return {
    r: 4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
    g: -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
    b: -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
  }
}

export function hexToOklab(hex: string): Oklab {
  return linearRgbToOklab(rgbToLinear(hexToRgb(hex)))
}

export function oklabToHex(oklab: Oklab): string {
  return rgbToHex(linearToRgb(oklabToLinearRgb(oklab)))
}

export function oklabToOklch({ L, a, b }: Oklab): Oklch {
  const C = Math.hypot(a, b)
  const hDegrees = (Math.atan2(b, a) * 180) / Math.PI

  return { L, C, h: hDegrees < 0 ? hDegrees + 360 : hDegrees }
}

export function oklchToOklab({ L, C, h }: Oklch): Oklab {
  const hRadians = (h * Math.PI) / 180

  return { L, a: C * Math.cos(hRadians), b: C * Math.sin(hRadians) }
}

export function hexToOklch(hex: string): Oklch {
  return oklabToOklch(hexToOklab(hex))
}

export function oklchToHex(oklch: Oklch): string {
  return oklabToHex(oklchToOklab(oklch))
}

/**
 * Tolerancia de coma flotante para la comprobación de gama: los redondeos
 * de las matrices dejan valores como `-1e-16` o `1.0000000004` para
 * colores que en la práctica están justo en el borde de la gama.
 */
const GAMUT_EPSILON = 1e-4

function isInUnitRange(value: number): boolean {
  return value >= -GAMUT_EPSILON && value <= 1 + GAMUT_EPSILON
}

/** §6.3 paso 4: ¿el color OKLCH cae dentro de la gama sRGB (sin recorte)? */
export function oklchInSrgbGamut(oklch: Oklch): boolean {
  const { r, g, b } = oklabToLinearRgb(oklchToOklab(oklch))

  return isInUnitRange(r) && isInUnitRange(g) && isInUnitRange(b)
}

/**
 * Composición alfa "over" en sRGB no lineal (gamma), tal como componen
 * los navegadores un color con opacidad sobre un fondo opaco para
 * pintarlo en pantalla. La usa `tokens-contrast.spec.ts` (§11) para los
 * pares con opacidad (`bg-destructive/10`, …).
 */
export function compositeOver(foreground: Rgb, alpha: number, background: Rgb): Rgb {
  const mix = (fg: number, bg: number): number => fg * alpha + bg * (1 - alpha)

  return {
    r: mix(foreground.r, background.r),
    g: mix(foreground.g, background.g),
    b: mix(foreground.b, background.b),
  }
}
