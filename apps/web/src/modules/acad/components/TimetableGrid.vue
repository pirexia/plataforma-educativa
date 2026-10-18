<script setup lang="ts">
/**
 * `docs/modulos/REQ-ACAD/funcional.md §18.8` («Rejilla a medida»), `CA-ACAD-134`.
 * Rejilla semanal de sesiones, **sin dependencia nueva** (`CLAUDE.md §1`): CSS Grid
 * con los días en columnas y el tiempo en filas.
 *
 * - Semántica de tabla (`role="table"`, `row`, `columnheader`, `rowheader`,
 *   `cell`) y navegación por teclado entre celdas (flechas, Inicio y Fin; una sola
 *   celda en el orden de tabulación, *roving tabindex*).
 * - Las filas son los intervalos horarios distintos de las franjas, con altura
 *   proporcional a su duración; los intervalos son semiabiertos `[inicio, fin)`.
 * - Cada sesión muestra asignatura, grupo (y subgrupo), profesor y aula **como
 *   texto**; un aula dada de baja se marca con texto (`RN-ACAD-102`).
 * - Mover una sesión es «Editar» (botón con selector de franja): no hay arrastre
 *   como única vía (WCAG 2.5.7). Activar una celda vacía con una franja pide
 *   crear una sesión (solo si `canCreate`).
 * - A 320 px (por debajo de 768 px) pasa a una lista por día, sin desplazamiento
 *   horizontal (`useNarrowGrid`); es la otra representación de los mismos datos.
 *
 * Es de solo presentación: no llama a la API ni decide permisos (`canCreate`,
 * `canEdit` llegan resueltos; el servidor decide igualmente, `INV-002`).
 */
import { computed, ref } from 'vue'
import { Plus } from '@lucide/vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { useNarrowGrid } from '../composables/useNarrowGrid'
import type { TimeSlot, TimetableSession } from '../types'

const props = defineProps<{
  /** Franjas que dan forma a la rejilla (las del marco, o las de las sesiones de la vista). */
  slots: TimeSlot[]
  sessions: TimetableSession[]
  canCreate: boolean
  canEdit: boolean
  caption: string
}>()

const emit = defineEmits<{ create: [slot: TimeSlot]; edit: [session: TimetableSession] }>()

const t = useT()
const narrow = useNarrowGrid()

/** Altura mínima de una fila de 55 minutos, en rem; las demás, proporcionales. */
const REM_PER_55_MINUTES = 4.5
const MIN_ROW_REM = 3.5

interface Interval {
  key: string
  starts_at: string
  ends_at: string
  minutes: number
}

function minutesOf(time: string): number {
  const [hours, minutes] = time.split(':')

  return Number(hours) * 60 + Number(minutes)
}

const weekdays = computed(() => {
  const present = new Set<number>()

  for (const slot of props.slots) present.add(slot.weekday)
  for (const session of props.sessions) present.add(session.slot.weekday)

  return [...present].sort((a, b) => a - b)
})

const intervals = computed<Interval[]>(() => {
  const found = new Map<string, Interval>()

  for (const slot of [...props.slots, ...props.sessions.map((session) => session.slot)]) {
    const key = `${slot.starts_at}-${slot.ends_at}`

    if (!found.has(key)) {
      found.set(key, {
        key,
        starts_at: slot.starts_at,
        ends_at: slot.ends_at,
        minutes: minutesOf(slot.ends_at) - minutesOf(slot.starts_at),
      })
    }
  }

  return [...found.values()].sort(
    (a, b) => a.starts_at.localeCompare(b.starts_at) || a.ends_at.localeCompare(b.ends_at),
  )
})

interface Cell {
  /** Franja del marco que corresponde a este día e intervalo, si existe. */
  slot: TimeSlot | null
  sessions: TimetableSession[]
}

function cellFor(weekday: number, interval: Interval): Cell {
  const slot =
    props.slots.find(
      (candidate) =>
        candidate.weekday === weekday &&
        candidate.starts_at === interval.starts_at &&
        candidate.ends_at === interval.ends_at,
    ) ?? null

  return {
    slot,
    sessions: props.sessions.filter(
      (session) =>
        session.slot.weekday === weekday &&
        session.slot.starts_at === interval.starts_at &&
        session.slot.ends_at === interval.ends_at,
    ),
  }
}

const matrix = computed(() =>
  intervals.value.map((interval) => weekdays.value.map((weekday) => cellFor(weekday, interval))),
)

function rowStyle(interval: Interval): Record<string, string> {
  return {
    minHeight: `${Math.max(MIN_ROW_REM, (interval.minutes / 55) * REM_PER_55_MINUTES)}rem`,
  }
}

function weekdayLabel(weekday: number): string {
  const key = `acad.weekday.${weekday}`
  const text = t(key)

  return text === key ? String(weekday) : text
}

function range(interval: { starts_at: string; ends_at: string }): string {
  return t('acad.timetable.grid.range', { start: interval.starts_at, end: interval.ends_at })
}

function sessionLabel(session: TimetableSession): string {
  return [session.subject.name, groupText(session)].join(' · ')
}

function groupText(session: TimetableSession): string {
  return session.subgroup ? `${session.group.code} / ${session.subgroup.code}` : session.group.code
}

// --- Teclado: flechas, Inicio y Fin entre celdas ---------------------------------------

const table = ref<HTMLElement | null>(null)
const active = ref({ row: 0, col: 0 })

function focusCell(row: number, col: number): void {
  const maxRow = intervals.value.length - 1
  const maxCol = weekdays.value.length - 1
  const next = { row: Math.min(Math.max(row, 0), maxRow), col: Math.min(Math.max(col, 0), maxCol) }

  active.value = next
  table.value?.querySelector<HTMLElement>(`[data-cell="${next.row}-${next.col}"]`)?.focus()
}

function onKeydown(event: KeyboardEvent, row: number, col: number, cell: Cell): void {
  // Las acciones de dentro de la celda (botones) conservan sus propias teclas.
  if (event.target !== event.currentTarget) return

  switch (event.key) {
    case 'ArrowRight':
      focusCell(row, col + 1)
      break
    case 'ArrowLeft':
      focusCell(row, col - 1)
      break
    case 'ArrowDown':
      focusCell(row + 1, col)
      break
    case 'ArrowUp':
      focusCell(row - 1, col)
      break
    case 'Home':
      focusCell(row, 0)
      break
    case 'End':
      focusCell(row, weekdays.value.length - 1)
      break
    case 'Enter':
    case ' ':
      if (props.canCreate && cell.slot !== null && cell.sessions.length === 0) {
        emit('create', cell.slot)
        break
      }

      return
    default:
      return
  }

  event.preventDefault()
}

// --- Lista por día (pantalla estrecha) ---------------------------------------------------

const days = computed(() =>
  weekdays.value.map((weekday) => ({
    weekday,
    entries: intervals.value
      .map((interval) => ({ interval, cell: cellFor(weekday, interval) }))
      .filter(({ cell }) => cell.slot !== null || cell.sessions.length > 0),
  })),
)
</script>

<template>
  <div data-slot="acad-timetable-grid">
    <p
      v-if="intervals.length === 0"
      role="status"
      class="text-muted-foreground text-sm"
      data-slot="acad-timetable-empty"
    >
      {{ t('acad.timetable.grid.noSlots') }}
    </p>

    <!-- Pantalla estrecha: lista por día, sin desplazamiento horizontal -->
    <div v-else-if="narrow" class="flex flex-col gap-4" data-slot="acad-timetable-days">
      <section v-for="day in days" :key="day.weekday" :aria-labelledby="`acad-day-${day.weekday}`">
        <h3 :id="`acad-day-${day.weekday}`" class="mb-1 text-sm font-semibold">
          {{ weekdayLabel(day.weekday) }}
        </h3>
        <ul class="divide-border border-border divide-y rounded-lg border">
          <li
            v-for="entry in day.entries"
            :key="entry.interval.key"
            class="flex flex-col gap-1 p-3 text-sm"
          >
            <span class="font-medium">{{ range(entry.interval) }}</span>

            <template v-if="entry.cell.sessions.length > 0">
              <div
                v-for="session in entry.cell.sessions"
                :key="session.public_id"
                class="flex flex-col"
              >
                <span class="font-medium">{{ session.subject.name }}</span>
                <span>{{ groupText(session) }}</span>
                <span v-if="session.teacher">{{ session.teacher.name }}</span>
                <span v-if="session.facility">
                  {{ session.facility.code }}
                  <template v-if="session.facility.deleted"
                    >({{ t('acad.timetable.grid.facilityDeleted') }})</template
                  >
                </span>
                <Button
                  v-if="canEdit"
                  type="button"
                  size="sm"
                  variant="outline"
                  class="mt-1 self-start"
                  @click="emit('edit', session)"
                >
                  {{ t('acad.timetable.grid.edit') }}
                  <span class="sr-only">{{ sessionLabel(session) }}</span>
                </Button>
              </div>
            </template>
            <span v-else class="text-muted-foreground">{{ t('acad.timetable.grid.free') }}</span>

            <Button
              v-if="canCreate && entry.cell.slot !== null && entry.cell.sessions.length === 0"
              type="button"
              size="sm"
              variant="outline"
              class="self-start"
              @click="emit('create', entry.cell.slot!)"
            >
              <Plus aria-hidden="true" />
              {{ t('acad.timetable.grid.add') }}
              <span class="sr-only"
                >{{ weekdayLabel(day.weekday) }} {{ range(entry.interval) }}</span
              >
            </Button>
          </li>
        </ul>
      </section>
    </div>

    <!-- Pantalla ancha: rejilla CSS Grid con semántica de tabla -->
    <div
      v-else
      ref="table"
      role="table"
      :aria-label="caption"
      :aria-rowcount="intervals.length + 1"
      :aria-colcount="weekdays.length + 1"
      class="border-border grid overflow-x-auto rounded-lg border text-sm"
      :style="{
        gridTemplateColumns: `minmax(6rem, auto) repeat(${weekdays.length}, minmax(9rem, 1fr))`,
      }"
    >
      <div role="row" class="contents">
        <div role="columnheader" class="bg-muted border-border border-b p-2 font-semibold">
          <span class="sr-only">{{ t('acad.timetable.grid.time') }}</span>
        </div>
        <div
          v-for="weekday in weekdays"
          :key="weekday"
          role="columnheader"
          class="bg-muted border-border border-b border-l p-2 font-semibold"
        >
          {{ weekdayLabel(weekday) }}
        </div>
      </div>

      <div v-for="(interval, row) in intervals" :key="interval.key" role="row" class="contents">
        <div
          role="rowheader"
          class="bg-muted border-border border-b p-2 font-medium"
          :style="rowStyle(interval)"
        >
          {{ range(interval) }}
        </div>
        <div
          v-for="(weekday, col) in weekdays"
          :key="weekday"
          role="cell"
          :data-cell="`${row}-${col}`"
          :tabindex="active.row === row && active.col === col ? 0 : -1"
          class="border-border focus-visible:ring-ring/50 flex flex-col gap-2 border-b border-l p-2 outline-none focus-visible:ring-3"
          :style="rowStyle(interval)"
          @focus="active = { row, col }"
          @keydown="onKeydown($event, row, col, matrix[row]![col]!)"
        >
          <div
            v-for="session in matrix[row]![col]!.sessions"
            :key="session.public_id"
            class="flex flex-col"
          >
            <span class="font-medium">{{ session.subject.name }}</span>
            <span>{{ groupText(session) }}</span>
            <span v-if="session.teacher">{{ session.teacher.name }}</span>
            <span v-if="session.facility">
              {{ session.facility.code }}
              <template v-if="session.facility.deleted"
                >({{ t('acad.timetable.grid.facilityDeleted') }})</template
              >
            </span>
            <Button
              v-if="canEdit"
              type="button"
              size="sm"
              variant="outline"
              class="mt-1 self-start"
              @click="emit('edit', session)"
            >
              {{ t('acad.timetable.grid.edit') }}
              <span class="sr-only">{{ sessionLabel(session) }}</span>
            </Button>
          </div>

          <span
            v-if="matrix[row]![col]!.slot === null && matrix[row]![col]!.sessions.length === 0"
            class="sr-only"
          >
            {{ t('acad.timetable.grid.noSlot') }}
          </span>
          <Button
            v-else-if="
              canCreate &&
              matrix[row]![col]!.slot !== null &&
              matrix[row]![col]!.sessions.length === 0
            "
            type="button"
            size="sm"
            variant="outline"
            class="self-start"
            @click="emit('create', matrix[row]![col]!.slot)"
          >
            <Plus aria-hidden="true" />
            {{ t('acad.timetable.grid.add') }}
            <span class="sr-only">{{ weekdayLabel(weekday) }} {{ range(interval) }}</span>
          </Button>
          <span v-else-if="matrix[row]![col]!.sessions.length === 0" class="sr-only">{{
            t('acad.timetable.grid.free')
          }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
