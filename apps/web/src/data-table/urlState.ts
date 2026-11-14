/**
 * `docs/modulos/REQ-CORE/funcional.md §13.5`, `RN-CORE-54`,
 * `ADR-054 §6`. Estado de la consulta en la *query* de la ruta: página,
 * `per_page`, orden, enumerados, booleanos y rangos de fechas. **`q` y
 * `cursor` nunca**, ni aquí ni en `history.state`: la *query* queda en el
 * historial del navegador y, en una recarga completa, en los registros de
 * acceso del servidor (mismo motivo que `ADR-038 §6.5`).
 *
 * Funciones puras: leer y escribir la ruta lo hace `useDataTableController`.
 */
import type { LocationQuery, LocationQueryRaw } from 'vue-router'
import { DEFAULT_PER_PAGE, PER_PAGE_OPTIONS } from './constants'
import { filterParams, type FilterValues } from './filterState'
import type { AnyDataTableFilter, DataTableMode } from './types'

/** Claves que **nunca** viajan a la URL (`RN-CORE-54`). */
export const NEVER_IN_URL = ['q', 'cursor'] as const

export interface UrlState {
  page: number
  perPage: number
  sort: string | null
  filters: FilterValues
}

export interface UrlStateOptions {
  mode: DataTableMode
  filters: readonly AnyDataTableFilter[]
  /** `id` de las columnas `sortable`. */
  sortableIds: readonly string[]
}

/** Parámetros de la *query* que posee la tabla: el resto (`redirect`, etc.) no se toca. */
export function ownedQueryKeys(options: UrlStateOptions): string[] {
  const keys = ['sort']

  if (options.mode === 'page') {
    keys.push('page', 'per_page')
  }

  for (const filter of options.filters) {
    keys.push(...filterParams(filter))
  }

  return keys.filter((key) => !(NEVER_IN_URL as readonly string[]).includes(key))
}

function firstString(value: unknown): string | null {
  const candidate = Array.isArray(value) ? value[0] : value

  return typeof candidate === 'string' ? candidate : null
}

export function defaultUrlState(): UrlState {
  return { page: 1, perPage: DEFAULT_PER_PAGE, sort: null, filters: {} }
}

export function parseUrlState(query: LocationQuery, options: UrlStateOptions): UrlState {
  const state = defaultUrlState()

  if (options.mode === 'page') {
    const page = Number(firstString(query.page))
    const perPage = Number(firstString(query.per_page))

    if (Number.isInteger(page) && page >= 1) {
      state.page = page
    }

    if ((PER_PAGE_OPTIONS as readonly number[]).includes(perPage)) {
      state.perPage = perPage
    }
  }

  const sort = firstString(query.sort)

  if (sort !== null && options.sortableIds.includes(sort.replace(/^-/, ''))) {
    state.sort = sort
  }

  for (const filter of options.filters) {
    for (const param of filterParams(filter)) {
      const value = firstString(query[param])

      if (value === null || value === '') {
        continue
      }

      if (filter.type === 'boolean' && value !== 'true' && value !== 'false') {
        continue
      }

      // `entity` (1.9d): el valor es un identificador público (ULID, `ADR-029`);
      // cualquier otra cosa en la URL se ignora en vez de llegar al servidor.
      if (filter.type === 'entity' && !/^[0-9A-Za-z]{26}$/.test(value)) {
        continue
      }

      state.filters[param] = value
    }
  }

  return state
}

/** Solo las claves de la tabla; los valores por defecto no se escriben (URL corta y estable). */
export function toQueryParams(state: UrlState, options: UrlStateOptions): Record<string, string> {
  const params: Record<string, string> = {}

  if (options.mode === 'page') {
    if (state.page > 1) {
      params.page = String(state.page)
    }

    if (state.perPage !== DEFAULT_PER_PAGE) {
      params.per_page = String(state.perPage)
    }
  }

  if (state.sort) {
    params.sort = state.sort
  }

  for (const [key, value] of Object.entries(state.filters)) {
    if (value !== '') {
      params[key] = value
    }
  }

  return params
}

/** Forma canónica para comparar «lo que dice la URL» con «lo que tiene la tabla». */
export function serializeUrlState(state: UrlState, options: UrlStateOptions): string {
  const params = toQueryParams(state, options)

  return JSON.stringify(
    Object.keys(params)
      .sort()
      .map((key) => [key, params[key]]),
  )
}

/** Sustituye en la *query* de la ruta las claves de la tabla y conserva las demás. */
export function mergeIntoRouteQuery(
  current: LocationQuery,
  state: UrlState,
  options: UrlStateOptions,
): LocationQueryRaw {
  const owned = new Set(ownedQueryKeys(options))
  const next: LocationQueryRaw = {}

  for (const [key, value] of Object.entries(current)) {
    if (!owned.has(key)) {
      next[key] = value
    }
  }

  return { ...next, ...toQueryParams(state, options) }
}
