/**
 * `docs/modulos/REQ-CORE/funcional.md §13.7`, `ADR-038 §5.2`/`§13.3`. El
 * estado de los filtros vive ya **serializado** en un mapa plano
 * `parámetro → valor`: es exactamente la forma de la *query string* y de la
 * URL (`RN-CORE-54`), sin tabla de correspondencias entre `id` de columna y
 * parámetro.
 */
import type { DataTableFilter } from './types'

export type FilterValues = Record<string, string>

/** Nombres de parámetro que posee un filtro. */
export function filterParams(filter: DataTableFilter): string[] {
  return filter.type === 'dateRange' ? [`${filter.id}_from`, `${filter.id}_to`] : [filter.id]
}

export function selectedEnumValues(values: FilterValues, id: string): string[] {
  const raw = values[id]

  return raw ? raw.split(',').filter((value) => value !== '') : []
}

/** Devuelve una copia del mapa con el parámetro fijado; el valor vacío lo elimina. */
export function withParam(
  values: FilterValues,
  name: string,
  value: string | undefined,
): FilterValues {
  const next = { ...values }

  if (value === undefined || value === '') {
    delete next[name]
  } else {
    next[name] = value
  }

  return next
}

/**
 * Marca o desmarca una opción de un enumerado. La lista serializada sigue
 * el orden en que el consumidor declaró las opciones (determinista, no el
 * orden de las pulsaciones); los valores que no están declarados (p. ej.
 * venidos de la URL) van al final.
 */
export function withEnumValue(
  values: FilterValues,
  filter: Extract<DataTableFilter, { type: 'enum' }>,
  option: string,
  checked: boolean,
): FilterValues {
  const current = selectedEnumValues(values, filter.id)
  const selected = checked
    ? current.includes(option)
      ? current
      : [...current, option]
    : current.filter((value) => value !== option)
  const order = filter.options.map((candidate) => candidate.value)
  const rank = (value: string): number => {
    const index = order.indexOf(value)

    return index === -1 ? order.length : index
  }

  return withParam(values, filter.id, [...selected].sort((a, b) => rank(a) - rank(b)).join(','))
}
