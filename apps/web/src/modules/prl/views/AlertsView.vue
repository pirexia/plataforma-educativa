<script setup lang="ts">
/**
 * `/conserjeria/alertas` (`docs/modulos/REQ-FAM-UNIT/funcional.md §23.5.4`, `REQ-PRL-004`; `1.14b-c2` T1, lectura).
 * Listado de alertas por persona no autorizada con el componente de tablas de `ADR-054`: modo `page` (25 por página,
 * `raised_at` descendente, orden fijo del servidor), estado en la URL (solo el filtro y la página: nada de personas) y
 * filtro por estado. «Abierta» (icono `TriangleAlert`) / «Cerrada» (icono `CircleCheck`): texto siempre (WCAG 1.4.1).
 * El listado no lleva teléfonos ni protocolo (`DP-5`) y no escribe `read` ni gasta cupo (`DP-11`): se carga al entrar,
 * sin sondeo. Los nombres (alumno y quien abrió la alerta) los da la API (`*_display_name`, 1.14b-c2); si faltan, el
 * alumno se busca en la memoria del módulo y, si no, «Alumno». Filtro por fecha de apertura (`date`, fecha civil del
 * centro) con un campo de fecha fuera de la tabla: al cambiarlo se reinicia la página y se recarga.
 */
import { computed, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { CircleCheck, TriangleAlert } from '@lucide/vue'
import { useT } from '@/i18n'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFetcher,
  type DataTableFilter,
} from '@/data-table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import StatusBadge from '../components/StatusBadge.vue'
import { listPickupAlerts } from '../api'
import { useDoorContext } from '../composables/useDoorContext'
import type { PickupAlert } from '../types'

const STATUSES = ['abierta', 'cerrada']

const t = useT()
const door = useDoorContext()
const { formatDateTime } = useDataTableFormatters()
const route = useRoute()
const router = useRouter()

/** `AAAA-MM-DD` o vacío (sin filtro). */
const date = ref('')
const tableKey = ref(0)

function studentName(row: PickupAlert): string {
  return (
    row.student_display_name ||
    door.find(row.student_person)?.displayName ||
    t('prl.alerts.studentUnknown')
  )
}

function raisedByName(row: PickupAlert): string {
  return row.raised_by_display_name || t('prl.alerts.userUnknown')
}

async function applyDate(value: string): Promise<void> {
  date.value = value
  tableKey.value += 1

  // Otro filtro, otra página 1: se quita `page` de la URL (el resto del estado se conserva).
  if (route.query.page !== undefined) {
    await router.replace({ query: { ...route.query, page: undefined } })
  }
}

function resolutionLabel(row: PickupAlert): string | null {
  if (row.resolution === null) return null

  const key = `prl.resolution.${row.resolution}`
  const text = t(key)

  return text === key ? row.resolution : text
}

const filters: DataTableFilter[] = [
  {
    type: 'enum',
    id: 'status',
    labelKey: 'prl.alerts.columns.status',
    options: STATUSES.map((value) => ({ value, labelKey: `prl.alert.status.${value}` })),
  },
]

const columns = computed<DataTableColumn<PickupAlert>[]>(() => [
  {
    id: 'student',
    headerKey: 'prl.alerts.columns.student',
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  { id: 'status', headerKey: 'prl.alerts.columns.status', card: 'subtitle' },
  { id: 'raised_by', headerKey: 'prl.alerts.columns.raisedBy', card: 'field' },
  {
    id: 'raised_at',
    headerKey: 'prl.alerts.columns.raisedAt',
    value: (row) => formatDateTime(row.raised_at),
    card: 'field',
  },
  {
    id: 'resolution',
    headerKey: 'prl.alerts.columns.resolution',
    value: (row) => resolutionLabel(row),
    card: 'field',
  },
])

const fetchAlerts: DataTableFetcher<PickupAlert> = (query) =>
  listPickupAlerts({
    status: query.filters.status,
    date: date.value || undefined,
    page: query.page,
    per_page: query.per_page,
  })
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-6">
    <h1 class="text-lg font-semibold">{{ t('prl.nav.alerts') }}</h1>

    <div class="flex flex-wrap items-end gap-3">
      <div class="flex flex-col gap-1">
        <Label for="prl-alerts-date">{{ t('prl.alerts.dateFilter') }}</Label>
        <Input
          id="prl-alerts-date"
          type="date"
          class="w-48"
          :model-value="date"
          @update:model-value="applyDate(String($event ?? ''))"
        />
      </div>
      <Button v-if="date" type="button" variant="outline" @click="applyDate('')">
        {{ t('prl.alerts.dateClear') }}
      </Button>
    </div>

    <DataTable
      :key="tableKey"
      table-id="prl.pickup_alerts"
      :caption="t('prl.alerts.caption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchAlerts"
      :filters="filters"
      url-state
      :empty-title="t('prl.alerts.empty')"
      empty-text=""
      :card-heading-level="2"
    >
      <template #cell-student="{ row }">
        <RouterLink
          :to="{ name: 'prl-alert-detail', params: { publicId: row.public_id } }"
          class="text-primary-on-background inline-flex min-h-11 items-center underline underline-offset-4"
        >
          {{ studentName(row) }}
        </RouterLink>
      </template>
      <template #cell-status="{ row }">
        <StatusBadge
          :text="t(`prl.alert.status.${row.status}`)"
          :icon="row.status === 'abierta' ? TriangleAlert : CircleCheck"
          :variant="row.status === 'abierta' ? 'destructive' : 'secondary'"
        />
      </template>
      <template #cell-raised_by="{ row }">
        <span>{{ raisedByName(row) }}</span>
      </template>
    </DataTable>
  </div>
</template>
