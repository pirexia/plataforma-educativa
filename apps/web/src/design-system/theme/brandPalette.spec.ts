/**
 * `docs/design-system.md` §5, §18 (`CA-DS-019` a `CA-DS-022`).
 */
import { describe, expect, it } from 'vitest'
import { deriveOnBackground } from '../color/deriveOnBackground'
import { applyBrandPalette } from './brandPalette'

function readBrandProperties(el: HTMLElement): Record<string, string> {
  const result: Record<string, string> = {}

  for (let i = 0; i < el.style.length; i += 1) {
    const property = el.style.item(i)

    if (property.startsWith('--brand-')) {
      result[property] = el.style.getPropertyValue(property).trim()
    }
  }

  return result
}

describe('applyBrandPalette — CA-DS-019', () => {
  it('escribe exactamente las cuatro variables de §4.2', () => {
    const el = document.createElement('div')

    applyBrandPalette({ primary: '#1d4ed8', primaryForeground: '#ffffff' }, el)

    const props = readBrandProperties(el)

    expect(Object.keys(props).sort()).toEqual(
      [
        '--brand-primary',
        '--brand-primary-foreground',
        '--brand-primary-on-background-dark',
        '--brand-primary-on-background-light',
      ].sort(),
    )
    expect(props['--brand-primary']).toBe('#1D4ED8')
    expect(props['--brand-primary-foreground']).toBe('#FFFFFF')
    expect(props['--brand-primary-on-background-light']).toBe(
      deriveOnBackground('#1D4ED8', 'light'),
    )
    expect(props['--brand-primary-on-background-dark']).toBe(deriveOnBackground('#1D4ED8', 'dark'))
  })
})

describe('applyBrandPalette — CA-DS-020', () => {
  it('con null retira las cuatro variables', () => {
    const el = document.createElement('div')

    applyBrandPalette({ primary: '#1D4ED8', primaryForeground: '#FFFFFF' }, el)
    expect(Object.keys(readBrandProperties(el))).not.toHaveLength(0)

    applyBrandPalette(null, el)
    expect(Object.keys(readBrandProperties(el))).toHaveLength(0)
  })
})

describe('applyBrandPalette — CA-DS-021: todo o nada', () => {
  it('con un color inválido, no queda ninguna propiedad --brand-*', () => {
    const el = document.createElement('div')

    applyBrandPalette({ primary: '#1D4ED8', primaryForeground: 'blanco' }, el)

    expect(Object.keys(readBrandProperties(el))).toHaveLength(0)
  })

  it('retira cualquier paleta previa si la nueva llamada es inválida', () => {
    const el = document.createElement('div')

    applyBrandPalette({ primary: '#1D4ED8', primaryForeground: '#FFFFFF' }, el)
    applyBrandPalette({ primary: 'no-es-un-color', primaryForeground: '#FFFFFF' }, el)

    expect(Object.keys(readBrandProperties(el))).toHaveLength(0)
  })
})

describe('applyBrandPalette — CA-DS-022', () => {
  it('no crea ningún <style> y solo toca propiedades --brand-*', () => {
    const stylesBefore = document.querySelectorAll('style').length
    const el = document.createElement('div')
    document.body.appendChild(el)

    applyBrandPalette({ primary: '#1D4ED8', primaryForeground: '#FFFFFF' }, el)
    applyBrandPalette(null, el)

    expect(document.querySelectorAll('style').length).toBe(stylesBefore)
    expect(el.getAttribute('style')).toBeFalsy()

    document.body.removeChild(el)
  })

  it('es síncrona (no requiere ningún await) e idempotente', () => {
    const el = document.createElement('div')

    applyBrandPalette({ primary: '#1D4ED8', primaryForeground: '#FFFFFF' }, el)
    const once = readBrandProperties(el)
    applyBrandPalette({ primary: '#1D4ED8', primaryForeground: '#FFFFFF' }, el)
    const twice = readBrandProperties(el)

    expect(twice).toEqual(once)
  })
})
