/**
 * `docs/design-system.md` §6. Deriva una variante de un color de marca
 * que garantiza ≥ 4,5:1 contra **todas** las superficies neutras opacas
 * del modo (`RN-DS-06`, §6.2), conservando tono y croma y ajustando solo
 * la luminosidad (§6.3). Función pura: sin DOM, sin estado, sin E/S — su
 * test corre con `@vitest-environment node` (`CA-DS-018`).
 *
 * La capa A (`theme/brandPalette.ts`) es la única llamadora en producción
 * (calcula las dos variantes, claro y oscuro, en la misma operación).
 */

import type { Oklch } from './color'
import { hexToOklch, isValidHex, oklchInSrgbGamut, oklchToHex } from './color'
import { contrastAgainstOklch, contrastFromLuminance, relativeLuminanceOfOklch } from './contrast'
import { surfacesFor } from './surfaces'

export type ColorMode = 'light' | 'dark'

const CONTRAST_THRESHOLD = 4.5
const BISECTION_ITERATIONS = 24
const FINE_TUNE_STEP = 0.005

function clamp01(value: number): number {
  return Math.min(1, Math.max(0, value))
}

/**
 * `≥ 4,5:1` de un candidato `(L, C₀, h₀)` contra cada superficie neutra
 * del modo (§6.2). El croma se reduce a gama (paso 4) **dentro** de la
 * propia bisección de `L` (paso 3), no después: un croma alto fuerza,
 * fuera de gama, una reducción de croma que puede cambiar la luminancia
 * relativa más de lo que predice mover solo `L` (`a`/`b` de OKLab
 * influyen en la luminancia lineal aunque `L` se mantenga). Ignorar esto
 * durante la bisección encontraría un límite que ya no es el más cercano
 * a la entrada una vez aplicado el mapeo de gama, y rompería la
 * minimalidad del resultado (`CA-DS-015`) para tonos muy saturados
 * (azules, violetas) que quedan fuera de gama al oscurecer o aclarar.
 */
function meetsAllSurfacesAtL(
  L: number,
  chroma0: number,
  h: number,
  surfaces: ReturnType<typeof surfacesFor>,
): boolean {
  const chroma = reduceChromaToGamut(L, chroma0, h)
  const luminance = relativeLuminanceOfOklch({ L, C: chroma, h })

  return surfaces.every(
    (surface) =>
      contrastFromLuminance(luminance, relativeLuminanceOfOklch(surface.oklch)) >=
      CONTRAST_THRESHOLD,
  )
}

/** §6.3 paso 4: reduce solo el croma, por bisección, hasta entrar en gama sRGB. `L` y `h` no se tocan. */
function reduceChromaToGamut(L: number, chroma: number, h: number): number {
  if (chroma <= 0 || oklchInSrgbGamut({ L, C: chroma, h })) {
    return chroma
  }

  let inGamut = 0
  let outOfGamut = chroma

  for (let i = 0; i < BISECTION_ITERATIONS; i += 1) {
    const mid = (inGamut + outOfGamut) / 2

    if (oklchInSrgbGamut({ L, C: mid, h })) {
      inGamut = mid
    } else {
      outOfGamut = mid
    }
  }

  return inGamut
}

/** §6.3 paso 5: contraste real del hex redondeado (el que de verdad se pinta). */
function meetsAllSurfacesHex(
  hex: string,
  surfaces: ReturnType<typeof surfacesFor>,
  contrastAgainstHex: (hex: string, surface: Oklch) => number,
): boolean {
  return surfaces.every((surface) => contrastAgainstHex(hex, surface.oklch) >= CONTRAST_THRESHOLD)
}

export function deriveOnBackground(hex: string, mode: ColorMode): string {
  if (!isValidHex(hex)) {
    throw new TypeError(`Color hexadecimal inválido: ${JSON.stringify(hex)}`)
  }

  const surfaces = surfacesFor(mode)
  const input = hexToOklch(hex)

  // Paso 2: el propio hex de entrada ya es una sRGB válida (está en gama
  // por construcción), así que se comprueba con su luminancia real, sin
  // pasar por `reduceChromaToGamut` (no hace falta reducir nada).
  const inputLuminance = relativeLuminanceOfOklch(input)
  const inputMeetsAll = surfaces.every(
    (surface) =>
      contrastFromLuminance(inputLuminance, relativeLuminanceOfOklch(surface.oklch)) >=
      CONTRAST_THRESHOLD,
  )

  if (inputMeetsAll) {
    // Se devuelve normalizado (mayúsculas), sin volver a pasar por OKLCH
    // para no perder precisión de más.
    return hex.toUpperCase()
  }

  // Paso 3: bisección de L, conservando C₀ y h₀, en la dirección que
  // aumenta el contraste (oscurecer en claro, aclarar en oscuro).
  let lo: number
  let hi: number

  if (mode === 'light') {
    lo = 0 // L = 0 (negro) cumple siempre contra superficies claras.
    hi = input.L
  } else {
    lo = input.L
    hi = 1 // L = 1 (blanco) cumple siempre contra superficies oscuras.
  }

  for (let i = 0; i < BISECTION_ITERATIONS; i += 1) {
    const mid = (lo + hi) / 2
    const satisfied = meetsAllSurfacesAtL(mid, input.C, input.h, surfaces)

    if (mode === 'light') {
      if (satisfied) {
        lo = mid
      } else {
        hi = mid
      }
    } else if (satisfied) {
      hi = mid
    } else {
      lo = mid
    }
  }

  let L = mode === 'light' ? lo : hi
  const direction = mode === 'light' ? -1 : 1

  // Pasos 4 y 5: mapeo de gama, redondeo a hex, y verificación del
  // contraste **sobre el hex ya redondeado**. Si el redondeo (o una
  // bisección no monótona por el mapeo de gama) lo dejó por debajo del
  // umbral, se avanza en pasos de `0.005` en la misma dirección hasta
  // cumplir. Termina siempre: los extremos (L=0 o L=1) cumplen por
  // construcción.
  let resultHex = ''

  for (let guard = 0; guard < 400; guard += 1) {
    const chroma = reduceChromaToGamut(L, input.C, input.h)
    resultHex = oklchToHex({ L, C: chroma, h: input.h })

    if (meetsAllSurfacesHex(resultHex, surfaces, contrastAgainstOklch)) {
      break
    }

    L = clamp01(L + direction * FINE_TUNE_STEP)
  }

  return resultHex
}

/**
 * Utilidad de prueba (`CA-DS-015`, minimalidad): contraste real —tras el
 * mismo mapeo de gama del paso 4 y el mismo redondeo del paso 5— de un
 * candidato OKLCH arbitrario contra todas las superficies del modo. Deja
 * que el test reproduzca exactamente "acercar `L` hacia la entrada, con
 * el mapeo de gama de §6.3" sin duplicar la lógica de reducción de croma.
 */
export function worstContrastForCandidate(
  oklch: Oklch,
  mode: ColorMode,
): { hex: string; worst: number } {
  const surfaces = surfacesFor(mode)
  const chroma = reduceChromaToGamut(oklch.L, oklch.C, oklch.h)
  const hex = oklchToHex({ L: oklch.L, C: chroma, h: oklch.h })
  const worst = Math.min(...surfaces.map((surface) => contrastAgainstOklch(hex, surface.oklch)))

  return { hex, worst }
}
