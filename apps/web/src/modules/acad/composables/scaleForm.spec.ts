/**
 * `docs/modulos/REQ-ACAD/funcional.md §4.9`, `§10.5.2`, `api.md §11.4`:
 * `CA-ACAD-099` (reparto de los `422` de `values.<n>.<campo>` y de lista) y
 * `CA-ACAD-100` (la columna «Aprobado» de la mixta se calcula con el umbral, en
 * centésimas enteras y sin `float`; los decimales viajan como cadena).
 * `REQ-ACAD-001`.
 */
import { describe, expect, it } from 'vitest'
import type { Locale } from '@/modules/core/types'
import {
  bandIsPassing,
  buildCreateBody,
  buildUpdateBody,
  distributeErrors,
  emptyRow,
  stateFromScale,
  toHundredths,
} from './scaleForm'
import { bandScale, scale } from '../testSupport'
import type { GradingScale } from '../types'

const LOCALES: Locale[] = ['es-ES', 'en']

describe('CA-ACAD-100 (RN-ACAD-75, RN-ACAD-87): decimales como cadena, sin float', () => {
  it.each([
    ['0', 0],
    ['5', 500],
    ['5.5', 550],
    ['5.05', 505],
    ['10.00', 1000],
    ['-1.25', -125],
    ['999.99', 99999],
  ])('toHundredths(%s) = %s (entero)', (text, expected) => {
    expect(toHundredths(text)).toBe(expected)
  })

  it.each(['', 'a', '5,5', '5.555', '1000', '.5', '5.'])('%s no es un decimal válido', (text) => {
    expect(toHundredths(text)).toBeNull()
  })

  it('0.1 + 0.2 no es problema: 5.0 ≥ 5 y 4.9 < 5, comparados como enteros', () => {
    expect(bandIsPassing('5.0', '5')).toBe(true)
    expect(bandIsPassing('4.9', '5.0')).toBe(false)
    expect(bandIsPassing('0.30', '0.3')).toBe(true)
  })

  it('con un valor sin decidir no hay veredicto', () => {
    expect(bandIsPassing('', '5.0')).toBeNull()
    expect(bandIsPassing('5.0', 'x')).toBeNull()
  })
})

describe('CA-ACAD-099 (§4.9.2): cuerpo del alta', () => {
  it('numérica: cuatro campos numéricos como cadena, decimals entero y sin values', () => {
    const state = {
      ...stateFromScale(scale() as GradingScale),
      scale_type: 'numerica' as const,
      code: ' NUM10 ',
      names: { 'es-ES': ' De 0 a 10 ' },
      min_value: '0',
      max_value: '10',
      decimals: '2',
      passing_min_value: '5',
      values: [],
    }
    const body = buildCreateBody(state, 'Y2', LOCALES)

    expect(body).toEqual({
      academic_year: 'Y2',
      code: 'NUM10',
      name_translations: { 'es-ES': 'De 0 a 10' },
      scale_type: 'numerica',
      min_value: '0',
      max_value: '10',
      decimals: 2,
      passing_min_value: '5',
    })
    expect(typeof body.min_value).toBe('string')
    expect(body).not.toHaveProperty('values')
  })

  it('cualitativa: values con code, etiquetas e is_passing; sin campos numéricos ni position', () => {
    const state = stateFromScale(scale() as GradingScale)
    const body = buildCreateBody(state, 'Y2', LOCALES)

    expect(body.values).toEqual([
      { code: 'IN', label_translations: { 'es-ES': 'Insuficiente' }, is_passing: false },
      { code: 'SU', label_translations: { 'es-ES': 'Suficiente' }, is_passing: true },
    ])
    expect(body).not.toHaveProperty('min_value')
    expect(body).not.toHaveProperty('decimals')
    expect(JSON.stringify(body)).not.toContain('position')
  })

  it('mixta: tramos con range_min/range_max como cadena y sin is_passing', () => {
    const body = buildCreateBody(stateFromScale(bandScale() as GradingScale), 'Y2', LOCALES)

    expect(body.values).toEqual([
      {
        code: 'IN',
        label_translations: { 'es-ES': 'Insuficiente' },
        range_min: '0.0',
        range_max: '4.9',
      },
      {
        code: 'SU',
        label_translations: { 'es-ES': 'Suficiente' },
        range_min: '5.0',
        range_max: '10.0',
      },
    ])
    expect(body.decimals).toBe(1)
  })
})

describe('CA-ACAD-099 (RN-ACAD-88, §4.9.3): PATCH solo con lo modificado; values = conjunto completo', () => {
  const stored = scale() as unknown as GradingScale

  it('sin cambios: nada que enviar', () => {
    expect(buildUpdateBody(stateFromScale(stored), stored, LOCALES)).toBeNull()
  })

  it('solo el código: ni values ni nombre', () => {
    const state = { ...stateFromScale(stored), code: 'CUAL2' }

    expect(buildUpdateBody(state, stored, LOCALES)).toEqual({ code: 'CUAL2' })
  })

  it('un cambio de etiqueta envía la lista entera, con public_id y solo las claves cambiadas', () => {
    const state = stateFromScale(stored)

    state.values[1] = { ...state.values[1]!, labels: { 'es-ES': 'Suficiente', en: 'Pass' } }

    expect(buildUpdateBody(state, stored, LOCALES)).toEqual({
      values: [{ public_id: 'V1' }, { public_id: 'V2', label_translations: { en: 'Pass' } }],
    })
  })

  it('reordenar envía la lista en el nuevo orden (el orden es el position)', () => {
    const state = stateFromScale(stored)

    state.values = [state.values[1]!, state.values[0]!]

    expect(buildUpdateBody(state, stored, LOCALES)).toEqual({
      values: [{ public_id: 'V2' }, { public_id: 'V1' }],
    })
  })

  it('un valor quitado desaparece de la lista; uno nuevo va completo y sin public_id', () => {
    const state = stateFromScale(stored)
    const added = { ...emptyRow(), code: 'MH', labels: { 'es-ES': 'Matrícula' }, is_passing: true }

    state.values = [state.values[1]!, added]

    expect(buildUpdateBody(state, stored, LOCALES)).toEqual({
      values: [
        { public_id: 'V2' },
        { code: 'MH', label_translations: { 'es-ES': 'Matrícula' }, is_passing: true },
      ],
    })
  })

  it('mixta: cambiar el máximo sin tocar los tramos no envía values (el servidor valida los guardados)', () => {
    const band = bandScale() as unknown as GradingScale
    const state = { ...stateFromScale(band), max_value: '9.9' }

    expect(buildUpdateBody(state, band, LOCALES)).toEqual({ max_value: '9.9' })
  })

  it('scale_type no viaja nunca (RN-ACAD-70)', () => {
    const state = { ...stateFromScale(stored), code: 'X' }

    expect(JSON.stringify(buildUpdateBody(state, stored, LOCALES))).not.toContain('scale_type')
  })
})

describe('CA-ACAD-099 (docblock de GradingScaleValidator): reparto de errors.<campo>', () => {
  it('campo de escala, lista, fila y resto', () => {
    const result = distributeErrors({
      code: ['c'],
      max_value: ['m'],
      'name_translations.en': ['n'],
      values: ['lista'],
      'values.2.code': ['f2c'],
      'values.2.range_min': ['f2r'],
      'values.0.public_id': ['f0p'],
      academic_year: ['y'],
    })

    expect(result.fields).toEqual({ code: ['c'], max_value: ['m'], 'name_translations.en': ['n'] })
    expect(result.list).toEqual(['lista'])
    expect(result.rows[2]).toEqual({ code: ['f2c'], range_min: ['f2r'] })
    expect(result.rows[0]).toEqual({ public_id: ['f0p'] })
    expect(result.general).toEqual(['y'])
  })
})
