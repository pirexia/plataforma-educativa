/**
 * `docs/modulos/REQ-CORE/funcional.md §13.5`, `RN-CORE-54`, `ADR-054 §6`:
 * funciones puras del estado en la URL y del estado de filtros.
 */
import { describe, expect, it } from 'vitest'
import { selectedEnumValues, withEnumValue, withParam } from './filterState'
import {
  mergeIntoRouteQuery,
  NEVER_IN_URL,
  ownedQueryKeys,
  parseUrlState,
  serializeUrlState,
  toQueryParams,
  type UrlStateOptions,
} from './urlState'
import type { DataTableEnumFilter, DataTableFilter } from './types'

const statusFilter: DataTableEnumFilter = {
  type: 'enum',
  id: 'status',
  labelKey: 'x',
  options: [{ value: 'activo' }, { value: 'inactivo' }, { value: 'pendiente' }],
}

const filters: DataTableFilter[] = [
  statusFilter,
  { type: 'dateRange', id: 'occurred_at', labelKey: 'x' },
  { type: 'boolean', id: 'is_system', labelKey: 'x' },
]

const pageOptions: UrlStateOptions = { mode: 'page', filters, sortableIds: ['name', 'created_at'] }
const cursorOptions: UrlStateOptions = { ...pageOptions, mode: 'cursor' }

describe('RN-CORE-54: q y cursor nunca van a la URL', () => {
  it('no son claves de la tabla ni se leen de la URL ni se escriben', () => {
    expect(NEVER_IN_URL).toEqual(['q', 'cursor'])
    expect(ownedQueryKeys(pageOptions)).not.toContain('q')
    expect(ownedQueryKeys(pageOptions)).not.toContain('cursor')

    const parsed = parseUrlState({ q: 'López', cursor: 'abc', page: '2' }, pageOptions)
    expect(JSON.stringify(parsed)).not.toContain('López')
    expect(JSON.stringify(parsed)).not.toContain('abc')

    const written = toQueryParams({ ...parsed, filters: { status: 'activo' } }, pageOptions)
    expect(Object.keys(written)).not.toContain('q')
    expect(Object.keys(written)).not.toContain('cursor')
  })

  it('en modo cursor no hay page ni per_page', () => {
    expect(ownedQueryKeys(cursorOptions)).not.toContain('page')
    expect(parseUrlState({ page: '4', per_page: '50' }, cursorOptions).page).toBe(1)
  })
})

describe('parseUrlState', () => {
  it('lee página, per_page, orden, enumerados, fechas y booleanos', () => {
    const state = parseUrlState(
      {
        page: '3',
        per_page: '50',
        sort: '-created_at',
        status: 'activo,inactivo',
        occurred_at_from: '2026-01-01',
        is_system: 'true',
      },
      pageOptions,
    )

    expect(state).toEqual({
      page: 3,
      perPage: 50,
      sort: '-created_at',
      filters: {
        status: 'activo,inactivo',
        occurred_at_from: '2026-01-01',
        is_system: 'true',
      },
    })
  })

  it('descarta lo que no es válido: página no numérica, per_page fuera de 25/50/100, orden no ordenable, booleano raro', () => {
    const state = parseUrlState(
      { page: 'abc', per_page: '500', sort: '-password', is_system: 'quizas', status: '' },
      pageOptions,
    )

    expect(state).toEqual({ page: 1, perPage: 25, sort: null, filters: {} })
  })

  it('ignora parámetros ajenos a la tabla y toma el primer valor de un array', () => {
    const state = parseUrlState({ redirect: '/x', status: ['activo', 'inactivo'] }, pageOptions)

    expect(state.filters).toEqual({ status: 'activo' })
  })
})

describe('toQueryParams / mergeIntoRouteQuery / serializeUrlState', () => {
  it('no escribe los valores por defecto y conserva las claves ajenas', () => {
    const state = { page: 1, perPage: 25, sort: null, filters: {} }

    expect(toQueryParams(state, pageOptions)).toEqual({})
    expect(
      mergeIntoRouteQuery({ redirect: '/x', page: '4', status: 'activo' }, state, pageOptions),
    ).toEqual({
      redirect: '/x',
    })
  })

  it('serializeUrlState es estable respecto al orden de las claves', () => {
    const a = serializeUrlState(
      { page: 2, perPage: 50, sort: 'name', filters: { status: 'activo', is_system: 'true' } },
      pageOptions,
    )
    const b = serializeUrlState(
      { page: 2, perPage: 50, sort: 'name', filters: { is_system: 'true', status: 'activo' } },
      pageOptions,
    )

    expect(a).toBe(b)
  })
})

describe('filterState', () => {
  it('withEnumValue serializa en el orden de las opciones declaradas, no de las pulsaciones', () => {
    let values = withEnumValue({}, statusFilter, 'pendiente', true)
    values = withEnumValue(values, statusFilter, 'activo', true)

    expect(values.status).toBe('activo,pendiente')
    expect(selectedEnumValues(values, 'status')).toEqual(['activo', 'pendiente'])

    values = withEnumValue(values, statusFilter, 'activo', false)
    values = withEnumValue(values, statusFilter, 'pendiente', false)
    expect(values).toEqual({})
  })

  it('withParam elimina el parámetro con valor vacío o indefinido', () => {
    expect(withParam({ a: '1' }, 'a', '')).toEqual({})
    expect(withParam({ a: '1' }, 'a', undefined)).toEqual({})
    expect(withParam({}, 'a', '2')).toEqual({ a: '2' })
  })
})
