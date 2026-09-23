/**
 * `docs/design-system.md` §6.1. Luminancia relativa y razón de contraste
 * de WCAG 2.x, `(L1 + 0.05) / (L2 + 0.05)`. Función pura, sin DOM.
 */

import type { Oklch, Rgb } from './color'
import { hexToRgb, oklabToLinearRgb, oklchToOklab, rgbToLinear } from './color'

function linearLuminance({ r, g, b }: Rgb): number {
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

export function relativeLuminance(hex: string): number {
  return linearLuminance(rgbToLinear(hexToRgb(hex)))
}

export function relativeLuminanceOfOklch(oklch: Oklch): number {
  return linearLuminance(oklabToLinearRgb(oklchToOklab(oklch)))
}

export function contrastFromLuminance(luminanceA: number, luminanceB: number): number {
  const lighter = Math.max(luminanceA, luminanceB)
  const darker = Math.min(luminanceA, luminanceB)

  return (lighter + 0.05) / (darker + 0.05)
}

export function contrastRatio(hexA: string, hexB: string): number {
  return contrastFromLuminance(relativeLuminance(hexA), relativeLuminance(hexB))
}

/** Contraste de un color hex contra una superficie neutra OKLCH (§6.2). */
export function contrastAgainstOklch(hex: string, surface: Oklch): number {
  return contrastFromLuminance(relativeLuminance(hex), relativeLuminanceOfOklch(surface))
}

export function meetsContrast(hexA: string, hexB: string, threshold: number): boolean {
  return contrastRatio(hexA, hexB) >= threshold
}
