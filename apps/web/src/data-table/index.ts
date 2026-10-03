/**
 * `docs/modulos/REQ-CORE/funcional.md §13.3`, `ADR-054 §1`. Superficie
 * pública de `src/data-table`: un módulo consumidor importa **solo de
 * aquí** el componente, sus tipos propios, las constantes y los
 * formateadores. Ningún módulo ve `@tanstack/vue-table` (`RN-CORE-37`).
 */
export { default as DataTable } from './components/DataTable.vue'
export { default as DataTableEmptyValue } from './components/DataTableEmptyValue.vue'
export {
  CARDS_BREAKPOINT_PX,
  DEFAULT_PER_PAGE,
  EXPORT_POLL_INITIAL_MS,
  EXPORT_POLL_MAX_MS,
  EXPORT_POLL_TIMEOUT_MS,
  MAX_CURSOR_ROWS,
  PER_PAGE_OPTIONS,
  SEARCH_DEBOUNCE_MS,
} from './constants'
export { useDataTableFormatters } from './formatters'
export type {
  DataTableBooleanFilter,
  DataTableCardRole,
  DataTableCellValue,
  DataTableColumn,
  DataTableCursorMeta,
  DataTableCursorResponse,
  DataTableDateRangeFilter,
  DataTableEntityFilter,
  DataTableEntityOption,
  DataTableEnumFilter,
  DataTableExportConfig,
  DataTableExportState,
  DataTableExportStatus,
  DataTableFetcher,
  DataTableFilter,
  DataTableMode,
  DataTablePageMeta,
  DataTablePageResponse,
  DataTableQuery,
} from './types'
