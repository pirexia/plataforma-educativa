<script setup lang="ts" generic="Row">
/**
 * `docs/modulos/REQ-CORE/funcional.md §13.9`, `§13.11`, `RN-CORE-44`,
 * `RN-CORE-55`, `CA-CORE-179`/`-183`/`-203`. Pinta las filas como **tabla
 * nativa** o como **lista de tarjetas**; en el DOM solo hay una de las dos
 * representaciones a la vez.
 *
 * Tabla: `table` con `caption`, `th scope="col"`, la columna `rowHeader`
 * como `th scope="row"`, `aria-sort` solo en la columna ordenada y **sin
 * `role="grid"`** (un listado con controles no es una rejilla de
 * navegación por celdas). Tarjetas: `ul`/`li`, `title` como encabezado,
 * `subtitle` debajo, `field` como `dl`/`dt`/`dd` y `actions` al pie.
 */
import { computed } from 'vue'
import { ArrowDown, ArrowUp, ArrowUpDown } from '@lucide/vue'
import { useT } from '@/i18n'
import {
  TableBody,
  TableCaption,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import type { ModelHeader, ModelRow } from '../useTableModel'
import type { DataTableCellValue } from '../types'
import DataTableCellContent from './DataTableCellContent.vue'

const props = withDefaults(
  defineProps<{
    headers: ModelHeader<Row>[]
    rows: ModelRow<Row>[]
    /** Nombre traducido de la tabla, que da el consumidor (`caption` y nombre del contenedor). */
    caption: string
    /** Por debajo de 768 px y sin declarar desplazamiento interno: tarjetas (`RN-CORE-55`). */
    cards: boolean
    /** El consumidor declaró desplazamiento horizontal interno para esta tabla (`RN-CORE-55`). */
    scroll: boolean
    cardHeadingLevel?: 2 | 3 | 4 | 5
  }>(),
  { cardHeadingLevel: 3 },
)

const emit = defineEmits<{ sort: [columnId: string] }>()

defineSlots<{
  [name: string]: (props: { row: Row; value: DataTableCellValue }) => unknown
}>()

const t = useT()

function ariaSort(header: ModelHeader<Row>): 'ascending' | 'descending' | undefined {
  return header.sorted === 'asc' ? 'ascending' : header.sorted === 'desc' ? 'descending' : undefined
}

/** El nombre del botón dice la columna y la acción que hará (`CA-CORE-183`). */
function sortButtonLabel(header: ModelHeader<Row>): string {
  const column = t(header.column.headerKey)
  const key =
    header.sorted === false
      ? 'dataTable.sort.ascending'
      : header.sorted === 'asc'
        ? 'dataTable.sort.descending'
        : 'dataTable.sort.remove'

  return t(key, { column })
}

const heading = computed(() => `h${props.cardHeadingLevel}` as const)

interface CardParts {
  id: string
  original: Row
  title: ModelRow<Row>['cells'][number] | null
  subtitles: ModelRow<Row>['cells']
  fields: ModelRow<Row>['cells']
  actions: ModelRow<Row>['cells']
}

/** `§13.4`, campo `card`. Sin papel declarado: la columna `rowHeader` es el título y el resto, campos. */
const cardRows = computed<CardParts[]>(() =>
  props.rows.map((row) => {
    const role = (cell: ModelRow<Row>['cells'][number]) =>
      cell.column.card ?? (cell.column.rowHeader ? 'title' : 'field')

    return {
      id: row.id,
      original: row.original,
      title: row.cells.find((cell) => role(cell) === 'title') ?? null,
      subtitles: row.cells.filter((cell) => role(cell) === 'subtitle'),
      fields: row.cells.filter((cell) => role(cell) === 'field'),
      actions: row.cells.filter((cell) => role(cell) === 'actions'),
    }
  }),
)
</script>

<template>
  <ul
    v-if="props.cards"
    data-slot="data-table-cards"
    :aria-label="props.caption"
    class="flex list-none flex-col gap-3 p-0"
  >
    <li
      v-for="card in cardRows"
      :key="card.id"
      data-slot="data-table-card"
      class="border-border bg-card text-card-foreground flex flex-col gap-2 rounded-lg border p-3"
    >
      <component :is="heading" v-if="card.title" class="text-base font-medium">
        <slot :name="`cell-${card.title.columnId}`" :row="card.original" :value="card.title.value">
          <DataTableCellContent :value="card.title.value" />
        </slot>
      </component>

      <p
        v-for="subtitle in card.subtitles"
        :key="subtitle.columnId"
        class="text-muted-foreground text-sm"
      >
        <slot :name="`cell-${subtitle.columnId}`" :row="card.original" :value="subtitle.value">
          <DataTableCellContent :value="subtitle.value" />
        </slot>
      </p>

      <dl v-if="card.fields.length > 0" class="grid gap-1.5 text-sm">
        <div v-for="field in card.fields" :key="field.columnId" class="flex flex-col">
          <dt class="text-muted-foreground text-xs">{{ t(field.column.headerKey) }}</dt>
          <dd>
            <slot :name="`cell-${field.columnId}`" :row="card.original" :value="field.value">
              <DataTableCellContent :value="field.value" />
            </slot>
          </dd>
        </div>
      </dl>

      <div v-if="card.actions.length > 0" class="flex flex-wrap gap-2 pt-1">
        <template v-for="action in card.actions" :key="action.columnId">
          <slot :name="`cell-${action.columnId}`" :row="card.original" :value="action.value">
            <DataTableCellContent :value="action.value" />
          </slot>
        </template>
      </div>
    </li>
  </ul>

  <div
    v-else
    data-slot="data-table-scroll"
    class="relative w-full overflow-x-auto"
    :role="props.scroll ? 'region' : undefined"
    :tabindex="props.scroll ? 0 : undefined"
    :aria-label="props.scroll ? t('dataTable.scrollRegion', { name: props.caption }) : undefined"
  >
    <table data-slot="table" class="w-full caption-bottom text-sm">
      <TableCaption class="sr-only">{{ props.caption }}</TableCaption>
      <TableHeader>
        <TableRow>
          <TableHead
            v-for="header in props.headers"
            :key="header.columnId"
            scope="col"
            :aria-sort="ariaSort(header)"
            :class="header.column.align === 'end' ? 'text-right' : undefined"
          >
            <button
              v-if="header.column.sortable"
              type="button"
              :aria-label="sortButtonLabel(header)"
              class="focus-visible:border-ring focus-visible:ring-ring/50 -mx-1.5 inline-flex min-h-8 items-center gap-1 rounded-md px-1.5 font-medium outline-none focus-visible:ring-3 [@media(any-pointer:coarse)]:min-h-11"
              @click="emit('sort', header.columnId)"
            >
              {{ t(header.column.headerKey) }}
              <ArrowUp v-if="header.sorted === 'asc'" class="size-4" aria-hidden="true" />
              <ArrowDown v-else-if="header.sorted === 'desc'" class="size-4" aria-hidden="true" />
              <ArrowUpDown v-else class="text-muted-foreground size-4" aria-hidden="true" />
            </button>
            <template v-else>{{ t(header.column.headerKey) }}</template>
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableRow v-for="row in props.rows" :key="row.id">
          <template v-for="cell in row.cells" :key="cell.columnId">
            <TableHead
              v-if="cell.column.rowHeader"
              scope="row"
              class="h-auto p-2 whitespace-normal"
            >
              <slot :name="`cell-${cell.columnId}`" :row="row.original" :value="cell.value">
                <DataTableCellContent :value="cell.value" />
              </slot>
            </TableHead>
            <TableCell
              v-else
              class="whitespace-normal"
              :class="cell.column.align === 'end' ? 'text-right' : undefined"
            >
              <slot :name="`cell-${cell.columnId}`" :row="row.original" :value="cell.value">
                <DataTableCellContent :value="cell.value" />
              </slot>
            </TableCell>
          </template>
        </TableRow>
      </TableBody>
    </table>
  </div>
</template>
