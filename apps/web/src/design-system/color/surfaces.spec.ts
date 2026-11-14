/**
 * `docs/design-system.md` §6.2, §18 (`CA-DS-017`). `surfaces.ts` es la
 * única excepción de literales fuera de `tokens.css` (§10.3): este test
 * es lo que impide que se desincronicen — cambiar un valor en un sitio
 * sin cambiarlo también en el otro rompe CI.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { DARK_SURFACES, LIGHT_SURFACES } from './surfaces'

const tokensCss = readFileSync(resolve(process.cwd(), 'src/design-system/tokens.css'), 'utf-8')

function extractBlock(css: string, selector: string): string {
  const start = css.indexOf(`${selector} {`)

  if (start === -1) {
    throw new Error(`No se encontró el bloque "${selector}" en tokens.css`)
  }

  const end = css.indexOf('\n}', start)

  return css.slice(start, end)
}

function grayLightnessOf(block: string, name: string): number {
  const match = block.match(new RegExp(`--${name}:\\s*oklch\\(([0-9.]+)\\s+0\\s+0\\)`))

  if (!match) {
    throw new Error(`No se encontró un gris puro para --${name} en el bloque analizado`)
  }

  return Number(match[1])
}

describe('surfaces.ts — CA-DS-017: sincronizado con tokens.css', () => {
  const rootBlock = extractBlock(tokensCss, ':root')
  const darkBlock = extractBlock(tokensCss, '.dark')

  it.each(LIGHT_SURFACES)('claro: $name coincide con :root', ({ name, oklch }) => {
    expect(oklch.C).toBe(0)
    expect(oklch.h).toBe(0)
    expect(grayLightnessOf(rootBlock, name)).toBe(oklch.L)
  })

  it.each(DARK_SURFACES)('oscuro: $name coincide con .dark', ({ name, oklch }) => {
    expect(oklch.C).toBe(0)
    expect(oklch.h).toBe(0)
    expect(grayLightnessOf(darkBlock, name)).toBe(oklch.L)
  })
})
