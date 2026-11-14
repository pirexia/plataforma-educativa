// @vitest-environment node
/**
 * `docs/design-system.md` §6, §18 (`CA-DS-011` a `CA-DS-018`). Función
 * pura: se prueba en entorno `node` (sin `window`/`document`) para que
 * `CA-DS-018` sea real y no una promesa sin comprobar.
 */
import { describe, expect, it } from 'vitest'
import { hexToOklch } from './color'
import { relativeLuminanceOfOklch, contrastFromLuminance } from './contrast'
import { deriveOnBackground, worstContrastForCandidate } from './deriveOnBackground'
import { surfacesFor } from './surfaces'

const THRESHOLD = 4.5

function worstContrast(hex: string, mode: 'light' | 'dark'): number {
  const luminance = relativeLuminanceOfOklch(hexToOklch(hex))

  return Math.min(
    ...surfacesFor(mode).map((surface) =>
      contrastFromLuminance(luminance, relativeLuminanceOfOklch(surface.oklch)),
    ),
  )
}

describe('deriveOnBackground — CA-DS-018: función pura, sin DOM', () => {
  it('no usa `window` ni `document` (corre en @vitest-environment node)', () => {
    expect(typeof window).toBe('undefined')
    expect(typeof document).toBe('undefined')
    expect(deriveOnBackground('#1D4ED8', 'dark')).toBeTypeOf('string')
  })
})

describe('deriveOnBackground — CA-DS-011: contrato de entrada', () => {
  it.each(['1D4ED8', '#12345', '#GGGGGG', '', '#1D4ED', '#1D4ED8AA'])(
    'lanza TypeError con %s',
    (invalid) => {
      expect(() => deriveOnBackground(invalid, 'light')).toThrow(TypeError)
    },
  )

  it('normaliza minúsculas a mayúsculas, con el mismo resultado que en mayúsculas', () => {
    expect(deriveOnBackground('#1d4ed8', 'light')).toBe(deriveOnBackground('#1D4ED8', 'light'))
    expect(deriveOnBackground('#1d4ed8', 'dark')).toBe(deriveOnBackground('#1D4ED8', 'dark'))
  })
})

describe('deriveOnBackground — CA-DS-012: casos «sin cambios» de §6.4', () => {
  it.each([
    ['#FFFF00', 'dark'],
    ['#1E3A8A', 'light'],
    ['#000000', 'light'],
    ['#FFFFFF', 'dark'],
  ] as const)('%s en modo %s se devuelve normalizado, sin cambios', (hex, mode) => {
    expect(deriveOnBackground(hex, mode)).toBe(hex.toUpperCase())
  })
})

describe('deriveOnBackground — CA-DS-013: casos «ajustado» de §6.4', () => {
  it.each([
    ['#FFFF00', 'light'],
    ['#FFF59D', 'light'],
    ['#1E3A8A', 'dark'],
    ['#1D4ED8', 'dark'],
    ['#808080', 'light'],
    ['#808080', 'dark'],
    ['#767676', 'light'],
    ['#000000', 'dark'],
    ['#FFFFFF', 'light'],
  ] as const)('%s en modo %s se ajusta y alcanza ≥ 4,5:1 contra cada superficie', (hex, mode) => {
    const result = deriveOnBackground(hex, mode)

    expect(result).not.toBe(hex.toUpperCase())
    expect(worstContrast(result, mode)).toBeGreaterThanOrEqual(THRESHOLD)

    const outputOklch = hexToOklch(result)
    const inputOklch = hexToOklch(hex)

    if (outputOklch.C >= 0.02) {
      const diff = Math.abs(outputOklch.h - inputOklch.h)
      expect(Math.min(diff, 360 - diff)).toBeLessThanOrEqual(2)
    }
  })
})

describe('deriveOnBackground — CA-DS-014: barrido de 216 colores, en ambos modos', () => {
  const steps = [0x00, 0x33, 0x66, 0x99, 0xcc, 0xff]
  const sweep: string[] = []

  for (const r of steps) {
    for (const g of steps) {
      for (const b of steps) {
        sweep.push(
          `#${[r, g, b].map((channel) => channel.toString(16).padStart(2, '0').toUpperCase()).join('')}`,
        )
      }
    }
  }

  it('genera exactamente 216 colores', () => {
    expect(sweep).toHaveLength(216)
  })

  it.each(['light', 'dark'] as const)(
    'todos alcanzan ≥ 4,5:1 en modo %s y `L` se mueve en la dirección correcta',
    (mode) => {
      for (const hex of sweep) {
        const result = deriveOnBackground(hex, mode)

        expect(worstContrast(result, mode)).toBeGreaterThanOrEqual(THRESHOLD)

        const inputL = hexToOklch(hex).L
        const outputL = hexToOklch(result).L

        if (mode === 'light') {
          expect(outputL).toBeLessThanOrEqual(inputL + 1e-9)
        } else {
          expect(outputL).toBeGreaterThanOrEqual(inputL - 1e-9)
        }
      }
    },
  )
})

describe('deriveOnBackground — CA-DS-015: minimalidad del ajuste', () => {
  const steps = [0x00, 0x33, 0x66, 0x99, 0xcc, 0xff]

  it('acercar `L` 0,01 hacia la entrada rompe el umbral en algún caso ajustado', () => {
    for (const r of steps) {
      for (const g of steps) {
        for (const b of steps) {
          const hex = `#${[r, g, b].map((c) => c.toString(16).padStart(2, '0').toUpperCase()).join('')}`

          for (const mode of ['light', 'dark'] as const) {
            const result = deriveOnBackground(hex, mode)

            if (result === hex.toUpperCase()) {
              continue // no ajustado: no aplica.
            }

            const outputOklch = hexToOklch(result)
            const towardInput = mode === 'light' ? 1 : -1
            const { worst } = worstContrastForCandidate(
              { L: outputOklch.L + towardInput * 0.01, C: outputOklch.C, h: outputOklch.h },
              mode,
            )

            expect(worst).toBeLessThan(THRESHOLD)
          }
        }
      }
    }
  })
})

describe('deriveOnBackground — CA-DS-016: idempotencia', () => {
  it.each(['#1D4ED8', '#FFFF00', '#808080', '#000000', '#FFFFFF', '#1E3A8A'])(
    'deriveOnBackground(deriveOnBackground(%s, m), m) === deriveOnBackground(%s, m)',
    (hex) => {
      for (const mode of ['light', 'dark'] as const) {
        const once = deriveOnBackground(hex, mode)
        const twice = deriveOnBackground(once, mode)

        expect(twice).toBe(once)
      }
    },
  )
})
