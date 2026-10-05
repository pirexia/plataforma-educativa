/**
 * `docs/modulos/REQ-PERM/funcional.md §20.11`, `RN-PERM-44` (E2-E5). Modelo del
 * modo `local`: filtros, búsqueda, ordenación y paginación **en cliente**
 * sobre la colección entera que el consumidor entregó una sola vez. Funciones
 * puras: no leen el idioma ni la ruta; quien las llama les pasa el idioma.
 * No se usan los modelos de cliente de TanStack (decisión de implementación,
 * `§20.11`): el contrato de comparación («vacíos al final en los dos sentidos»,
 * desempate por `rowKey`) es propio y más corto de escribir a mano.
 */
import { DEFAULT_PER_PAGE } from './constants'
import type { FilterValues } from './filterState'
import { cellValueOf } from './useTableModel'
import type {
  DataTableBooleanFilter,
  DataTableColumn,
  DataTableEnumFilter,
  DataTableFilter,
  DataTablePageMeta,
} from './types'

/** `RN-PERM-44` E5: minúsculas del idioma activo y sin diacríticos (NFD). */
export function normalizeSearchText(text: string, locale: string): string {
  return text
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLocaleLowerCase(locale)
}

/** Términos de la búsqueda (separados por espacios) ya normalizados; sin texto, lista vacía. */
export function searchTerms(query: string, locale: string): string[] {
  return normalizeSearchText(query, locale)
    .split(/\s+/)
    .filter((term) => term !== '')
}

function selected(values: FilterValues, id: string): string[] {
  const raw = values[id]

  return raw ? raw.split(',').filter((value) => value !== '') : []
}

function passesEnum<Row>(
  filter: DataTableEnumFilter<Row>,
  row: Row,
  values: FilterValues,
): boolean {
  const chosen = selected(values, filter.id)

  if (chosen.length === 0 || !filter.rowValue) {
    return true
  }

  const raw = filter.rowValue(row)
  const rowValues = typeof raw === 'string' ? [raw] : raw

  return rowValues.some((value) => chosen.includes(value))
}

function passesBoolean<Row>(
  filter: DataTableBooleanFilter<Row>,
  row: Row,
  values: FilterValues,
): boolean {
  const chosen = values[filter.id]

  if (!filter.rowValue) {
    return true
  }

  // Filtro de inclusión (`twoState`): desmarcado restringe; marcado lo incluye todo.
  if (filter.inclusion === true && filter.twoState === true) {
    return chosen === 'true' || filter.rowValue(row)
  }

  if (chosen !== 'true' && chosen !== 'false') {
    return true
  }

  return filter.rowValue(row) === (chosen === 'true')
}

export interface LocalQuery<Row> {
  filters: readonly DataTableFilter<Row>[]
  filterValues: FilterValues
  /** `searchText(row)` del consumidor; ausente ⇒ la búsqueda no filtra. */
  searchText?: (row: Row) => string
  /** Texto de búsqueda aplicado (ya con la espera de `RN-CORE-40`). */
  query: string
  locale: string
}

/** E4 y E5: `dateRange` y `entity` no se admiten en `local`; el controlador ya las descartó. */
export function filterLocalRows<Row>(rows: readonly Row[], local: LocalQuery<Row>): Row[] {
  const terms = local.searchText ? searchTerms(local.query, local.locale) : []

  return rows.filter((row) => {
    for (const filter of local.filters) {
      if (filter.type === 'enum' && !passesEnum(filter, row, local.filterValues)) {
        return false
      }

      if (filter.type === 'boolean' && !passesBoolean(filter, row, local.filterValues)) {
        return false
      }
    }

    if (terms.length > 0 && local.searchText) {
      const haystack = normalizeSearchText(local.searchText(row), local.locale)

      return terms.every((term) => haystack.includes(term))
    }

    return true
  })
}

function isEmptyValue(value: unknown): boolean {
  return value === null || value === undefined || value === ''
}

/**
 * E3: ordena por la columna `sort` (`<id>` o `-<id>`). Valores vacíos **al final
 * en los dos sentidos**; desempate estable por `rowKey`. Con `compare` declarado
 * por la columna, se usa ese (y se invierte su signo en el descendente).
 */
export function sortLocalRows<Row>(
  rows: readonly Row[],
  sort: string | null,
  columns: readonly DataTableColumn<Row>[],
  rowKey: (row: Row) => string,
  locale: string,
): Row[] {
  const copy = [...rows]

  if (!sort) {
    return copy
  }

  const descending = sort.startsWith('-')
  const column = columns.find((candidate) => candidate.id === sort.replace(/^-/, ''))

  if (!column) {
    return copy
  }

  const collator = new Intl.Collator(locale, { sensitivity: 'base', numeric: true })
  const direction = descending ? -1 : 1
  const tieBreak = (a: Row, b: Row): number => collator.compare(rowKey(a), rowKey(b))

  return copy.sort((a, b) => {
    if (column.compare) {
      return direction * column.compare(a, b) || tieBreak(a, b)
    }

    const left = cellValueOf(column, a)
    const right = cellValueOf(column, b)
    const leftEmpty = isEmptyValue(left)
    const rightEmpty = isEmptyValue(right)

    if (leftEmpty || rightEmpty) {
      return leftEmpty && rightEmpty ? tieBreak(a, b) : leftEmpty ? 1 : -1
    }

    return direction * collator.compare(String(left), String(right)) || tieBreak(a, b)
  })
}

/** E2: meta de página en cliente. Una página fuera de rango pasa a la última. */
export function pageLocalRows<Row>(
  rows: readonly Row[],
  requestedPage: number,
  perPage: number,
): { rows: Row[]; meta: DataTablePageMeta } {
  const size = perPage > 0 ? perPage : DEFAULT_PER_PAGE
  const lastPage = Math.max(Math.ceil(rows.length / size), 1)
  const page = Math.min(Math.max(requestedPage, 1), lastPage)
  const start = (page - 1) * size

  return {
    rows: rows.slice(start, start + size),
    meta: { current_page: page, per_page: size, total: rows.length, last_page: lastPage },
  }
}
