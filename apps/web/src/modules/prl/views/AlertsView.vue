<script setup lang="ts">
/**
 * `/conserjeria/alertas` (`docs/modulos/REQ-FAM-UNIT/funcional.md §23.5.4`, `REQ-PRL-004`; `1.14b-c2` T1, lectura).
 * Listado de alertas por persona no autorizada con el componente de tablas de `ADR-054`: modo `page` (25 por página,
 * `raised_at` descendente, orden fijo del servidor), estado en la URL (solo el filtro y la página: nada de personas) y
 * filtro por estado. «Abierta» (icono `TriangleAlert`) / «Cerrada» (icono `CircleCheck`): texto siempre (WCAG 1.4.1).
 * El listado no lleva teléfonos ni protocolo (`DP-5`) y no escribe `read` ni gasta cupo (`DP-11`): se carga al entrar,
 * sin sondeo. El alumno se muestra por nombre solo si se conoce en la memoria del módulo (la API da su `public_id`).
 */
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { CircleCheck, TriangleAlert } from '@lucide/vue'
import { useT } from '@/i18n'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFetcher,
  type DataTableFilter,
} from '@/data-table'
import StatusBadge from '../components/StatusBadge.vue'
import { listPickupAlerts } from '../api'
import { useDoorContext } from '../composables/useDoorContext'
import type { PickupAlert } from '../types'

const STATUSES = ['abierta', 'cerrada']

const t = useT()
const door = useDoorContext()
const { formatDateTime } = useDataTableFormatters()

function studentName(row: PickupAlert): string {
  return door.find(row.student_person)?.displayName ?? t('prl.alerts.studentUnknown')
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
  listPickupAlerts({ status: query.filters.status, page: query.page, per_page: query.per_page })
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-6">
    <h1 class="text-lg font-semibold">{{ t('prl.nav.alerts') }}</h1>

    <DataTable
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
        <span class="font-mono text-xs">{{ row.raised_by }}</span>
      </template>
    </DataTable>
  </div>
</template>
