/**
 * `docs/modulos/REQ-CORE/funcional.md §13.3`, `RN-CORE-37`,
 * `ADR-054 §1`. **Único punto de este módulo que importa
 * `@tanstack/vue-table`** (junto con el resto de `src/data-table/**`):
 * ningún consumidor ve sus tipos ni sus funciones. Aporta el modelo
 * headless de columnas, cabeceras y filas; paginación, orden y filtrado
 * son del servidor (`manualPagination`, `manualSorting`,
 * `manualFiltering`, `enableMultiSort: false`, `ADR-038 §13.3`).
 */
import { computed, type Ref } from 'vue'
import { createColumnHelper, getCoreRowModel, useVueTable } from '@tanstack/vue-table'
import type { DataTableCellValue, DataTableColumn } from './types'

export interface ModelRow<Row> {
  id: string
  original: Row
  cells: { columnId: string; column: DataTableColumn<Row>; value: DataTableCellValue }[]
}

export interface ModelHeader<Row> {
  columnId: string
  column: DataTableColumn<Row>
  /** `ascending`/`descending` solo en la columna ordenada (`RN-CORE-44`); `false` si no lo está. */
  sorted: 'asc' | 'desc' | false
}

export function cellValueOf<Row>(column: DataTableColumn<Row>, row: Row): DataTableCellValue {
  if (column.value) {
    return column.value(row)
  }

  const raw = (row as Record<string, unknown>)[column.id]

  return typeof raw === 'string' || typeof raw === 'number' ? raw : null
}

export function useTableModel<Row>(options: {
  columns: Ref<readonly DataTableColumn<Row>[]>
  rows: Ref<readonly Row[]>
  /** `id` de columna ocultos por el usuario o por defecto. */
  hidden: Ref<readonly string[]>
  /** `<id>` o `-<id>`; `null` = sin orden explícito. */
  sort: Ref<string | null>
  rowKey: (row: Row) => string
}) {
  const helper = createColumnHelper<Row>()

  const columnDefs = computed(() =>
    options.columns.value.map((column) =>
      helper.display({
        id: column.id,
        enableSorting: column.sortable === true,
        enableHiding: column.hideable !== false && column.rowHeader !== true,
      }),
    ),
  )

  const table = useVueTable<Row>({
    get data() {
      return options.rows.value as Row[]
    },
    get columns() {
      return columnDefs.value
    },
    getRowId: (row) => options.rowKey(row),
    getCoreRowModel: getCoreRowModel(),
    manualPagination: true,
    manualSorting: true,
    manualFiltering: true,
    enableMultiSort: false,
    state: {
      get columnVisibility() {
        return Object.fromEntries(options.hidden.value.map((id) => [id, false]))
      },
      get sorting() {
        const sort = options.sort.value

        return sort ? [{ id: sort.replace(/^-/, ''), desc: sort.startsWith('-') }] : []
      },
      // La paginación es del servidor: el modelo de TanStack no la conoce
      // (en modo `cursor`, `pageCount: -1`, `ADR-054 §2.2`).
      pagination: { pageIndex: 0, pageSize: Number.MAX_SAFE_INTEGER },
    },
    pageCount: -1,
    onColumnVisibilityChange: () => undefined,
    onSortingChange: () => undefined,
  })

  const byId = computed(
    () => new Map(options.columns.value.map((column) => [column.id, column] as const)),
  )

  const headers = computed<ModelHeader<Row>[]>(() =>
    table
      .getVisibleLeafColumns()
      .map((tanstackColumn) => {
        const column = byId.value.get(tanstackColumn.id)

        return column ? { columnId: column.id, column, sorted: tanstackColumn.getIsSorted() } : null
      })
      .filter((header): header is ModelHeader<Row> => header !== null),
  )

  const modelRows = computed<ModelRow<Row>[]>(() =>
    table.getRowModel().rows.map((row) => ({
      id: row.id,
      original: row.original,
      cells: row
        .getVisibleCells()
        .map((cell) => {
          const column = byId.value.get(cell.column.id)

          return column
            ? { columnId: column.id, column, value: cellValueOf(column, row.original) }
            : null
        })
        .filter((cell): cell is ModelRow<Row>['cells'][number] => cell !== null),
    })),
  )

  return { headers, rows: modelRows }
}
