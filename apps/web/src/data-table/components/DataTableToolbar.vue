<script setup lang="ts" generic="Row">
/**
 * `docs/modulos/REQ-CORE/funcional.md §13.7`-`§13.9`, `§13.14.1`,
 * `RN-CORE-40`/`-43`/`-57`. Barra de herramientas encima de la tabla
 * (no dentro de las cabeceras: en móvil no hay cabeceras y un campo dentro
 * de un `th` complica la navegación con lector de pantalla): búsqueda,
 * filtros (enumerado múltiple, rango de fechas, booleano), menú de
 * columnas, selector de orden (solo en la vista de tarjetas) y
 * exportación.
 */
import { computed, ref, useId } from 'vue'
import { ArrowUpDown, Columns3, Download, ListFilter, Search, X } from '@lucide/vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { selectedEnumValues, withEnumValue, withParam, type FilterValues } from '../filterState'
import type {
  DataTableBooleanFilter,
  DataTableColumn,
  DataTableDateRangeFilter,
  DataTableEntityFilter,
  DataTableEnumFilter,
  DataTableFilter,
} from '../types'
import EntityFilterField from './DataTableEntityFilter.vue'

const props = defineProps<{
  columns: readonly DataTableColumn<Row>[]
  filters: readonly DataTableFilter[]
  filterValues: FilterValues
  searchable: boolean
  searchText: string
  hasActiveFilters: boolean
  hiddenColumns: readonly string[]
  /** Hay columnas que el usuario puede ocultar. */
  showColumnsMenu: boolean
  /** Vista de tarjetas: la ordenación se hace con un selector, no con cabeceras. */
  cards: boolean
  sort: string | null
  /** Exportación disponible (`canExport`, `RN-CORE-51`). */
  canExport: boolean
  exportBusy: boolean
  /** Búsqueda escrita o aplicada: la exportación queda deshabilitada (`RN-CORE-57`). */
  searchActive: boolean
}>()

const emit = defineEmits<{
  'update:searchText': [text: string]
  'update:filterValues': [values: FilterValues]
  'toggle-column': [columnId: string, visible: boolean]
  'reset-columns': []
  sort: [sort: string | null]
  clear: []
  export: []
}>()

const t = useT()
const uid = useId()
const searchInput = ref<{ $el?: HTMLInputElement } | null>(null)

defineExpose({
  /** `CA-CORE-181`: al limpiar filtros el foco pasa al campo de búsqueda. */
  focusSearch(): void {
    searchInput.value?.$el?.focus()
  },
})

/** `ADR-038 §7.3`: sin traducción, se muestra el código en crudo antes que fallar. */
function optionLabel(option: { value: string; labelKey?: string; label?: string }): string {
  if (option.label) {
    return option.label
  }

  if (!option.labelKey) {
    return option.value
  }

  const label = t(option.labelKey)

  return label === option.labelKey ? option.value : label
}

function asEnum(filter: DataTableFilter): DataTableEnumFilter | null {
  return filter.type === 'enum' ? filter : null
}

function asRange(filter: DataTableFilter): DataTableDateRangeFilter | null {
  return filter.type === 'dateRange' ? filter : null
}

function asBoolean(filter: DataTableFilter): DataTableBooleanFilter | null {
  return filter.type === 'boolean' ? filter : null
}

function asEntity(filter: DataTableFilter): DataTableEntityFilter | null {
  return filter.type === 'entity' ? filter : null
}

function setEntity(filter: DataTableEntityFilter, value: string): void {
  emit('update:filterValues', withParam(props.filterValues, filter.id, value))
}

function toggleOption(filter: DataTableEnumFilter, value: string, checked: boolean): void {
  emit('update:filterValues', withEnumValue(props.filterValues, filter, value, checked))
}

function setRange(filter: DataTableDateRangeFilter, edge: 'from' | 'to', value: string): void {
  emit('update:filterValues', withParam(props.filterValues, `${filter.id}_${edge}`, value))
}

function rangeInvalid(filter: DataTableDateRangeFilter): boolean {
  const from = props.filterValues[`${filter.id}_from`]
  const to = props.filterValues[`${filter.id}_to`]

  return Boolean(from && to && from > to)
}

function setBoolean(filter: DataTableBooleanFilter, value: string): void {
  emit('update:filterValues', withParam(props.filterValues, filter.id, value))
}

function booleanLabel(filter: DataTableBooleanFilter): string {
  const current = props.filterValues[filter.id]
  const value =
    current === 'true'
      ? t('dataTable.filters.yes')
      : current === 'false'
        ? t('dataTable.filters.no')
        : t('dataTable.filters.all')

  return t('dataTable.filters.booleanTrigger', { label: t(filter.labelKey), value })
}

const sortableColumns = computed(() => props.columns.filter((column) => column.sortable))
const sortColumn = computed(() => props.sort?.replace(/^-/, '') ?? '')
const sortDirection = computed(() => (props.sort?.startsWith('-') ? 'desc' : 'asc'))

function chooseSortColumn(value: unknown): void {
  const id = String(value)

  emit('sort', id === '' ? null : sortDirection.value === 'desc' ? `-${id}` : id)
}

function chooseSortDirection(value: unknown): void {
  if (sortColumn.value === '') {
    return
  }

  emit('sort', value === 'desc' ? `-${sortColumn.value}` : sortColumn.value)
}

const exportHintId = `${uid}-export-hint`
const itemClass = 'min-h-8 [@media(any-pointer:coarse)]:min-h-11'
</script>

<template>
  <div
    data-slot="data-table-toolbar"
    class="flex flex-col gap-2 md:flex-row md:flex-wrap md:items-end"
  >
    <div v-if="props.searchable" class="flex min-w-48 flex-1 flex-col gap-1">
      <label :for="`${uid}-search`" class="sr-only">{{ t('dataTable.search.label') }}</label>
      <div class="relative">
        <Search
          class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
          aria-hidden="true"
        />
        <Input
          :id="`${uid}-search`"
          ref="searchInput"
          type="search"
          autocomplete="off"
          class="pl-8"
          :model-value="props.searchText"
          :placeholder="t('dataTable.search.placeholder')"
          @update:model-value="
            (value: string | undefined) => emit('update:searchText', value ?? '')
          "
        />
      </div>
    </div>

    <template v-for="filter in props.filters" :key="filter.id">
      <DropdownMenu v-if="asEnum(filter)">
        <DropdownMenuTrigger as-child>
          <Button type="button" variant="outline">
            <ListFilter aria-hidden="true" />
            <template v-if="selectedEnumValues(props.filterValues, filter.id).length > 0">
              {{
                t('dataTable.filters.triggerWithCount', {
                  label: t(filter.labelKey),
                  count: selectedEnumValues(props.filterValues, filter.id).length,
                })
              }}
            </template>
            <template v-else>{{ t(filter.labelKey) }}</template>
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start">
          <DropdownMenuLabel>{{ t(filter.labelKey) }}</DropdownMenuLabel>
          <DropdownMenuCheckboxItem
            v-for="option in (filter as DataTableEnumFilter).options"
            :key="option.value"
            :model-value="selectedEnumValues(props.filterValues, filter.id).includes(option.value)"
            :class="itemClass"
            @select="(event: Event) => event.preventDefault()"
            @update:model-value="
              (checked: boolean | 'indeterminate') =>
                toggleOption(filter as DataTableEnumFilter, option.value, checked === true)
            "
          >
            {{ optionLabel(option) }}
          </DropdownMenuCheckboxItem>
        </DropdownMenuContent>
      </DropdownMenu>

      <fieldset
        v-else-if="asRange(filter)"
        class="flex flex-wrap items-end gap-2"
        :aria-describedby="
          rangeInvalid(filter as DataTableDateRangeFilter)
            ? `${uid}-${filter.id}-invalid`
            : undefined
        "
      >
        <legend class="mb-1 text-xs font-medium">{{ t(filter.labelKey) }}</legend>
        <div class="flex flex-col gap-1">
          <label :for="`${uid}-${filter.id}-from`" class="text-muted-foreground text-xs">{{
            t('dataTable.filters.from')
          }}</label>
          <Input
            :id="`${uid}-${filter.id}-from`"
            type="date"
            class="w-auto"
            :model-value="props.filterValues[`${filter.id}_from`] ?? ''"
            :aria-invalid="rangeInvalid(filter as DataTableDateRangeFilter) || undefined"
            @update:model-value="
              (value: string | undefined) =>
                setRange(filter as DataTableDateRangeFilter, 'from', value ?? '')
            "
          />
        </div>
        <div class="flex flex-col gap-1">
          <label :for="`${uid}-${filter.id}-to`" class="text-muted-foreground text-xs">{{
            t('dataTable.filters.to')
          }}</label>
          <Input
            :id="`${uid}-${filter.id}-to`"
            type="date"
            class="w-auto"
            :model-value="props.filterValues[`${filter.id}_to`] ?? ''"
            :aria-invalid="rangeInvalid(filter as DataTableDateRangeFilter) || undefined"
            @update:model-value="
              (value: string | undefined) =>
                setRange(filter as DataTableDateRangeFilter, 'to', value ?? '')
            "
          />
        </div>
        <p
          v-if="rangeInvalid(filter as DataTableDateRangeFilter)"
          :id="`${uid}-${filter.id}-invalid`"
          class="text-destructive w-full text-xs"
        >
          {{ t('dataTable.filters.rangeInvalid') }}
        </p>
      </fieldset>

      <EntityFilterField
        v-else-if="asEntity(filter)"
        :filter="filter as DataTableEntityFilter"
        :value="props.filterValues[filter.id]"
        @update:value="(value: string) => setEntity(filter as DataTableEntityFilter, value)"
      />

      <label
        v-else-if="asBoolean(filter)?.twoState"
        data-slot="data-table-two-state-filter"
        class="border-border bg-background flex min-h-8 cursor-pointer items-center gap-2 rounded-lg border px-2.5 text-sm [@media(any-pointer:coarse)]:min-h-11"
      >
        <input
          type="checkbox"
          class="accent-primary-on-background size-4"
          :checked="props.filterValues[filter.id] === 'true'"
          @change="
            (event: Event) =>
              setBoolean(
                filter as DataTableBooleanFilter,
                (event.target as HTMLInputElement).checked ? 'true' : '',
              )
          "
        />
        {{ t(filter.labelKey) }}
      </label>

      <DropdownMenu v-else-if="asBoolean(filter)">
        <DropdownMenuTrigger as-child>
          <Button type="button" variant="outline">
            <ListFilter aria-hidden="true" />
            {{ booleanLabel(filter as DataTableBooleanFilter) }}
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start">
          <DropdownMenuLabel>{{ t(filter.labelKey) }}</DropdownMenuLabel>
          <DropdownMenuRadioGroup
            :model-value="props.filterValues[filter.id] ?? ''"
            @update:model-value="
              (value) => setBoolean(filter as DataTableBooleanFilter, String(value))
            "
          >
            <DropdownMenuRadioItem value="" :class="itemClass">{{
              t('dataTable.filters.all')
            }}</DropdownMenuRadioItem>
            <DropdownMenuRadioItem value="true" :class="itemClass">{{
              t('dataTable.filters.yes')
            }}</DropdownMenuRadioItem>
            <DropdownMenuRadioItem value="false" :class="itemClass">{{
              t('dataTable.filters.no')
            }}</DropdownMenuRadioItem>
          </DropdownMenuRadioGroup>
        </DropdownMenuContent>
      </DropdownMenu>
    </template>

    <DropdownMenu v-if="props.cards && sortableColumns.length > 0">
      <DropdownMenuTrigger as-child>
        <Button type="button" variant="outline">
          <ArrowUpDown aria-hidden="true" />
          {{ t('dataTable.sort.cardsTrigger') }}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start">
        <DropdownMenuLabel>{{ t('dataTable.sort.cardsColumn') }}</DropdownMenuLabel>
        <DropdownMenuRadioGroup :model-value="sortColumn" @update:model-value="chooseSortColumn">
          <DropdownMenuRadioItem value="" :class="itemClass">{{
            t('dataTable.sort.none')
          }}</DropdownMenuRadioItem>
          <DropdownMenuRadioItem
            v-for="column in sortableColumns"
            :key="column.id"
            :value="column.id"
            :class="itemClass"
          >
            {{ t(column.headerKey) }}
          </DropdownMenuRadioItem>
        </DropdownMenuRadioGroup>
        <DropdownMenuSeparator />
        <DropdownMenuLabel>{{ t('dataTable.sort.cardsDirection') }}</DropdownMenuLabel>
        <DropdownMenuRadioGroup
          :model-value="sortDirection"
          @update:model-value="chooseSortDirection"
        >
          <DropdownMenuRadioItem value="asc" :class="itemClass" :disabled="sortColumn === ''">{{
            t('dataTable.sort.directionAscending')
          }}</DropdownMenuRadioItem>
          <DropdownMenuRadioItem value="desc" :class="itemClass" :disabled="sortColumn === ''">{{
            t('dataTable.sort.directionDescending')
          }}</DropdownMenuRadioItem>
        </DropdownMenuRadioGroup>
      </DropdownMenuContent>
    </DropdownMenu>

    <DropdownMenu v-if="props.showColumnsMenu">
      <DropdownMenuTrigger as-child>
        <Button type="button" variant="outline">
          <Columns3 aria-hidden="true" />
          {{ t('dataTable.columns.menu') }}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start">
        <DropdownMenuLabel>{{ t('dataTable.columns.label') }}</DropdownMenuLabel>
        <template v-for="column in props.columns" :key="column.id">
          <DropdownMenuCheckboxItem
            v-if="column.hideable !== false && !column.rowHeader"
            :model-value="!props.hiddenColumns.includes(column.id)"
            :class="itemClass"
            @select="(event: Event) => event.preventDefault()"
            @update:model-value="
              (checked: boolean | 'indeterminate') =>
                emit('toggle-column', column.id, checked === true)
            "
          >
            {{ t(column.headerKey) }}
          </DropdownMenuCheckboxItem>
        </template>
        <DropdownMenuSeparator />
        <DropdownMenuItem :class="itemClass" @select="emit('reset-columns')">
          {{ t('dataTable.columns.reset') }}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>

    <Button v-if="props.hasActiveFilters" type="button" variant="ghost" @click="emit('clear')">
      <X aria-hidden="true" />
      {{ t('dataTable.filters.clear') }}
    </Button>

    <div v-if="props.canExport" class="flex flex-col gap-1 md:ml-auto">
      <Button
        type="button"
        variant="outline"
        :disabled="props.searchActive || props.exportBusy"
        :aria-describedby="props.searchActive ? exportHintId : undefined"
        @click="emit('export')"
      >
        <Download aria-hidden="true" />
        {{ t('dataTable.export.button') }}
      </Button>
    </div>
  </div>

  <p
    v-if="props.canExport && props.searchActive"
    :id="exportHintId"
    data-slot="data-table-export-hint"
    class="text-muted-foreground text-xs"
  >
    {{ t('dataTable.export.searchActive') }}
  </p>
</template>
