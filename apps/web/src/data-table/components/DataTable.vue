<script setup lang="ts" generic="Row">
/**
 * `docs/modulos/REQ-CORE/funcional.md §13`, `docs/adr/ADR-054`. Componente
 * de tabla de datos reutilizable: paginación por página o por cursor,
 * orden y filtros **en servidor**, columnas configurables (visibilidad y
 * «restablecer»), vista de tarjetas por debajo de 768 px, estado de la
 * consulta opcionalmente en la URL (sin `q` ni `cursor`), estados de
 * carga/vacío/error de `§12.6` y disparador de exportación asíncrona.
 *
 * El módulo consumidor declara columnas y filtros con los tipos propios de
 * `src/data-table` y aporta la función de petición (`RN-CORE-38`): este
 * componente no construye URLs de *endpoints*, no genera ficheros
 * (`RN-CORE-46`) y no ve los tipos de TanStack (`RN-CORE-37`).
 */
import { computed, nextTick, ref, watch } from 'vue'
import { SearchX } from '@lucide/vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import EmptyState from '@/layouts/components/EmptyState.vue'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import { clearHiddenColumns, readHiddenColumns, writeHiddenColumns } from '../columnPreferences'
import { MAX_CURSOR_ROWS } from '../constants'
import { useDataTableFormatters } from '../formatters'
import { useDataTableController } from '../useDataTableController'
import { useExportFlow } from '../useExportFlow'
import { useNarrowViewport } from '../useNarrowViewport'
import { useTableModel } from '../useTableModel'
import type {
  DataTableCellValue,
  DataTableColumn,
  DataTableExportConfig,
  DataTableFetcher,
  DataTableFilter,
  DataTableMode,
} from '../types'
import DataTableExportStatus from './DataTableExportStatus.vue'
import DataTableGrid from './DataTableGrid.vue'
import DataTablePagination from './DataTablePagination.vue'
import DataTableToolbar from './DataTableToolbar.vue'

const props = withDefaults(
  defineProps<{
    /** Literal `<modulo>.<nombre>`, único en toda la SPA (`RN-CORE-43`): clave de las preferencias de columnas. */
    tableId: string
    /** Nombre traducido de la tabla (`caption`, `RN-CORE-44`). */
    caption: string
    columns: readonly DataTableColumn<Row>[]
    mode: DataTableMode
    fetcher: DataTableFetcher<Row>
    /** Identidad de la fila: por `public_id` (`ADR-029`) salvo que el consumidor declare otra clave. */
    rowKey?: (row: Row) => string
    filters?: readonly DataTableFilter[]
    /** Campo de búsqueda `q` (`RN-CORE-40`). */
    searchable?: boolean
    /** `RN-CORE-54`: opcional; como máximo una tabla por ruta. */
    urlState?: boolean
    /** `RN-CORE-55`: por defecto, tarjetas por debajo de 768 px; `scroll` = desplazamiento horizontal interno. */
    mobile?: 'cards' | 'scroll'
    /** Estado vacío sin filtros activos, con el texto del consumidor (`§13.10`). */
    emptyTitle: string
    emptyText?: string
    emptyActionLabel?: string
    exportConfig?: DataTableExportConfig
    /** Nivel del encabezado de cada tarjeta, según la jerarquía de la vista del consumidor. */
    cardHeadingLevel?: 2 | 3 | 4 | 5
    /** Issue #326: con una sola página no se pinta el pie (total, «Página 1 de 1» y botones inertes). */
    hideSinglePageFooter?: boolean
  }>(),
  {
    rowKey: (row: Row) => String((row as { public_id?: string }).public_id),
    filters: () => [],
    searchable: false,
    urlState: false,
    mobile: 'cards',
    emptyText: '',
    emptyActionLabel: undefined,
    exportConfig: undefined,
    cardHeadingLevel: 3,
    hideSinglePageFooter: false,
  },
)

const emit = defineEmits<{ 'empty-action': [] }>()

defineSlots<{
  [name: string]: (props: { row: Row; value: DataTableCellValue }) => unknown
}>()

const t = useT()
const { formatNumber } = useDataTableFormatters()
const narrow = useNarrowViewport()

// --- Columnas configurables (`RN-CORE-43`) ---------------------------------

function hideableIds(): string[] {
  return props.columns
    .filter((column) => column.hideable !== false && !column.rowHeader)
    .map((column) => column.id)
}

function defaultHidden(): string[] {
  const hideable = new Set(hideableIds())

  return props.columns
    .filter((column) => column.defaultHidden === true && hideable.has(column.id))
    .map((column) => column.id)
}

/** Lee la preferencia de forma síncrona, antes del primer pintado: sin destello de columnas. */
function initialHidden(): string[] {
  const stored = readHiddenColumns(props.tableId)

  if (stored === null) {
    return defaultHidden()
  }

  const hideable = new Set(hideableIds())

  // Los `id` que ya no existen (o que dejaron de ser ocultables) se ignoran; se aplica el resto.
  return props.columns
    .map((column) => column.id)
    .filter((id) => hideable.has(id) && stored.includes(id))
}

const hiddenColumns = ref<string[]>(initialHidden())

function toggleColumn(columnId: string, visible: boolean): void {
  const hidden = new Set(hiddenColumns.value)

  if (visible) {
    hidden.delete(columnId)
  } else {
    hidden.add(columnId)
  }

  hiddenColumns.value = props.columns.map((column) => column.id).filter((id) => hidden.has(id))
  writeHiddenColumns(props.tableId, hiddenColumns.value)
}

function resetColumns(): void {
  hiddenColumns.value = defaultHidden()
  clearHiddenColumns(props.tableId)
}

// --- Consulta y datos -----------------------------------------------------

const controller = useDataTableController<Row>({
  mode: props.mode,
  fetcher: () => props.fetcher,
  filters: () => props.filters,
  sortableIds: () => props.columns.filter((column) => column.sortable).map((column) => column.id),
  urlState: props.urlState,
})

const {
  rows,
  pageMeta,
  hasMore,
  loading,
  loadingMore,
  hasLoaded,
  error,
  filterError,
  loadMoreError,
  capReached,
  announcement,
  filters: filterValues,
  searchText,
  hasActiveFilters,
  searchActive,
  sort,
  perPage,
} = controller

const { headers, rows: modelRows } = useTableModel<Row>({
  columns: computed(() => props.columns),
  rows,
  hidden: hiddenColumns,
  sort,
  rowKey: (row) => props.rowKey(row),
})

const initialLoading = computed(() => loading.value && !hasLoaded.value)
const refreshing = computed(() => loading.value && hasLoaded.value)
const showCards = computed(() => narrow.value && props.mobile !== 'scroll')

const announcementText = computed(() => {
  const current = announcement.value

  if (!current) {
    return ''
  }

  return t(
    `dataTable.announce.${current.kind}`,
    { count: formatNumber(current.count) },
    current.count,
  )
})

// --- Exportación (`RN-CORE-46`) -------------------------------------------

const exportFlow = useExportFlow(() => props.exportConfig)
const canExport = computed(() => props.exportConfig?.canExport === true)

function onExport(): void {
  // `RN-CORE-57`: con búsqueda activa no se invoca la solicitud (no solo con estilo).
  if (!canExport.value || searchActive.value || exportFlow.busy.value) {
    return
  }

  // Solo los filtros estructurados: sin `sort`, `page`, `per_page`, `cursor` ni `q` (`ADR-054 §7.3`).
  void exportFlow.start({ ...filterValues.value })
}

// --- Foco -----------------------------------------------------------------

const toolbar = ref<{ focusSearch: () => void } | null>(null)
const endNotice = ref<HTMLElement | null>(null)
let loadMoreActivated = false

function onClear(): void {
  controller.clearFilters()
  void nextTick(() => toolbar.value?.focusSearch())
}

function onLoadMore(): void {
  loadMoreActivated = true
  void controller.loadMore()
}

// El botón «Cargar más» desaparece al llegar al tope o al final: el foco no se pierde en `body`.
watch(loadingMore, async (now, before) => {
  if (!before || now || !loadMoreActivated) {
    return
  }

  loadMoreActivated = false
  await nextTick()

  if (capReached.value || !hasMore.value) {
    endNotice.value?.focus()
  }
})

const isFilteredEmpty = computed(() => hasActiveFilters.value)
const showEmpty = computed(
  () =>
    hasLoaded.value &&
    !initialLoading.value &&
    error.value === null &&
    rows.value.length === 0 &&
    !(filterError.value && !hasActiveFilters.value),
)

defineExpose({
  /** Repite la última consulta (`refresh()` de `MfaComplianceArea`, `CA-CORE-187`). */
  refresh: (): Promise<void> => controller.refresh(),
  focusSearch: (): void => toolbar.value?.focusSearch(),
})
</script>

<template>
  <section data-slot="data-table" class="flex flex-col gap-3">
    <DataTableToolbar
      ref="toolbar"
      :columns="props.columns"
      :filters="props.filters"
      :filter-values="filterValues"
      :searchable="props.searchable"
      :search-text="searchText"
      :has-active-filters="hasActiveFilters"
      :hidden-columns="hiddenColumns"
      :show-columns-menu="hideableIds().length > 0"
      :cards="showCards"
      :sort="sort"
      :can-export="canExport"
      :export-busy="exportFlow.busy.value"
      :search-active="searchActive"
      @update:search-text="controller.setSearchText"
      @update:filter-values="controller.setFilters"
      @toggle-column="toggleColumn"
      @reset-columns="resetColumns"
      @sort="controller.setSort"
      @clear="onClear"
      @export="onExport"
    />

    <DataTableExportStatus
      :state="exportFlow.state.value"
      @check-again="exportFlow.checkAgain"
      @request-again="onExport"
    />

    <p v-if="filterError" role="alert" class="text-destructive text-sm">
      <template v-for="message in filterError" :key="message">{{ message }} </template>
      {{ t('dataTable.filters.notApplied') }}
    </p>

    <p data-slot="data-table-announcer" aria-live="polite" aria-atomic="true" class="sr-only">
      {{ announcementText }}
    </p>

    <div
      data-slot="data-table-region"
      class="relative flex flex-col gap-3"
      :aria-busy="refreshing ? 'true' : undefined"
    >
      <div
        v-if="refreshing"
        data-slot="data-table-refreshing"
        class="bg-muted-foreground/40 h-0.5 w-full animate-pulse rounded"
        aria-hidden="true"
      />

      <LoadingState v-if="initialLoading" />

      <ErrorState v-else-if="error" :state="error" @retry="controller.retry" />

      <EmptyState
        v-else-if="showEmpty && isFilteredEmpty"
        :icon="SearchX"
        :title="t('dataTable.empty.filteredTitle')"
        :text="t('dataTable.empty.filteredText')"
        :action-label="t('dataTable.filters.clear')"
        @action="onClear"
      />

      <EmptyState
        v-else-if="showEmpty"
        :icon="SearchX"
        :title="props.emptyTitle"
        :text="props.emptyText"
        :action-label="props.emptyActionLabel"
        @action="emit('empty-action')"
      />

      <DataTableGrid
        v-else-if="rows.length > 0"
        :headers="headers"
        :rows="modelRows"
        :caption="props.caption"
        :cards="showCards"
        :scroll="props.mobile === 'scroll'"
        :card-heading-level="props.cardHeadingLevel"
        :class="refreshing ? 'opacity-60' : undefined"
        @sort="controller.cycleSort"
      >
        <template v-for="(_, name) in $slots" :key="name" #[name]="slotProps">
          <slot :name="name" v-bind="slotProps ?? {}" />
        </template>
      </DataTableGrid>
    </div>

    <DataTablePagination
      v-if="
        props.mode === 'page' &&
        pageMeta &&
        pageMeta.total > 0 &&
        !error &&
        !(props.hideSinglePageFooter && pageMeta.last_page <= 1)
      "
      :current="pageMeta.current_page"
      :last="Math.max(pageMeta.last_page, 1)"
      :total="pageMeta.total"
      :per-page="perPage"
      @page="controller.setPage"
      @per-page="controller.setPerPage"
    />

    <div
      v-if="props.mode === 'cursor' && rows.length > 0 && !error"
      data-slot="data-table-cursor-footer"
      class="flex flex-col items-center gap-2 text-sm"
    >
      <div v-if="capReached" ref="endNotice" tabindex="-1" class="flex flex-col items-center gap-2">
        <p>{{ t('dataTable.loadMore.cap', { max: formatNumber(MAX_CURSOR_ROWS) }) }}</p>
        <Button
          v-if="canExport"
          type="button"
          variant="outline"
          :disabled="searchActive || exportFlow.busy.value"
          @click="onExport"
        >
          {{ t('dataTable.export.button') }}
        </Button>
      </div>

      <template v-else-if="hasMore">
        <ErrorState v-if="loadMoreError" :state="loadMoreError" @retry="onLoadMore" />
        <Button
          v-else
          type="button"
          variant="outline"
          :aria-disabled="loadingMore ? 'true' : undefined"
          :aria-busy="loadingMore ? 'true' : undefined"
          @click="onLoadMore"
        >
          {{ loadingMore ? t('dataTable.loadMore.loading') : t('dataTable.loadMore.button') }}
        </Button>
      </template>

      <p v-else ref="endNotice" tabindex="-1" class="text-muted-foreground">
        {{ t('dataTable.loadMore.end') }}
      </p>
    </div>
  </section>
</template>
