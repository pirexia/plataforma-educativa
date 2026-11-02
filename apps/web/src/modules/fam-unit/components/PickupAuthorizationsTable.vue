<script setup lang="ts">
/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.4.4`. Autorizaciones de recogida **de un alumno** (`GET
 * /pickup-authorizations?student_person=`, paginado, `valid_from` desc). No escribe `read` (sin fotografía ni documento):
 * se carga al elegir alumno. Sin acciones en este tramo (`1.14b-c1` T1, solo lectura).
 *
 * Estado (WCAG 1.4.1): **«Vigente»** con `CircleCheck` o **«No vigente: {causa}»** con `CircleX`, causa traducida de
 * `ineffective_reason` (ya enmascarada por el servidor, `D-68`; UI-5: nada judicial). La columna «Motivo» solo existe si
 * alguna fila trae `reason` (ámbito `todos`, `D-53`).
 */
import { computed, ref } from 'vue'
import { CircleCheck, CircleX } from '@lucide/vue'
import { useT } from '@/i18n'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFetcher,
} from '@/data-table'
import { listPickupAuthorizations } from '../api'
import { useLabels } from '../composables/useLabels'
import type { PickupAuthorization } from '../types'

const props = defineProps<{ studentPerson: string }>()

const t = useT()
const labels = useLabels()
const { formatDate, formatDateTime } = useDataTableFormatters()

/** Se activa en cuanto una respuesta trae `reason` en alguna fila. */
const hasReason = ref(false)

function validity(row: PickupAuthorization): string {
  const from = formatDate(row.valid_from) ?? row.valid_from

  if (row.kind === 'puntual' || row.valid_until === row.valid_from) {
    return from
  }

  const until =
    row.valid_until === null
      ? t('famUnit.pickup.basis.noEnd')
      : (formatDate(row.valid_until) ?? row.valid_until)

  return `${from} – ${until}`
}

function causeLabel(row: PickupAuthorization): string {
  return labels.pickupLabel('ineffective', row.ineffective_reason ?? 'no_vigente')
}

const columns = computed<DataTableColumn<PickupAuthorization>[]>(() => [
  {
    id: 'person',
    headerKey: 'famUnit.pickup.table.columns.person',
    value: (row) => row.person.display_name,
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'relationship',
    headerKey: 'famUnit.pickup.table.columns.relationship',
    value: (row) => labels.pickupLabel('relationship', row.relationship),
    card: 'field',
  },
  {
    id: 'kind',
    headerKey: 'famUnit.pickup.table.columns.kind',
    value: (row) => labels.pickupLabel('kind', row.kind),
    card: 'field',
  },
  {
    id: 'valid_from',
    headerKey: 'famUnit.pickup.table.columns.validity',
    value: validity,
    sortable: true,
    card: 'field',
  },
  {
    id: 'status',
    headerKey: 'famUnit.pickup.table.columns.status',
    card: 'field',
  },
  {
    id: 'channel',
    headerKey: 'famUnit.pickup.table.columns.channel',
    value: (row) => labels.pickupLabel('channel', row.channel),
    card: 'field',
  },
  {
    id: 'authorized_at',
    headerKey: 'famUnit.pickup.table.columns.authorizedAt',
    value: (row) => formatDateTime(row.authorized_at),
    card: 'field',
  },
  {
    id: 'withdrawn_at',
    headerKey: 'famUnit.pickup.table.columns.withdrawnAt',
    value: (row) => formatDateTime(row.withdrawn_at),
    card: 'field',
  },
  ...(hasReason.value
    ? [
        {
          id: 'reason',
          headerKey: 'famUnit.pickup.table.columns.reason',
          value: (row: PickupAuthorization) => row.reason,
          card: 'field',
        } satisfies DataTableColumn<PickupAuthorization>,
      ]
    : []),
])

const fetchAuthorizations: DataTableFetcher<PickupAuthorization> = async (query) => {
  const result = await listPickupAuthorizations({
    student_person: props.studentPerson,
    sort: query.sort,
    page: query.page,
    per_page: query.per_page,
  })

  hasReason.value = result.data.some((row) => row.reason !== undefined && row.reason !== null)

  return result
}

const table = ref<{ refresh: () => Promise<void> } | null>(null)

defineExpose({ refresh: () => table.value?.refresh() })
</script>

<template>
  <div class="flex flex-col gap-3">
    <h3 class="text-sm font-semibold">{{ t('famUnit.pickup.table.title') }}</h3>

    <DataTable
      ref="table"
      table-id="fam_unit.pickup_authorizations"
      :caption="t('famUnit.pickup.table.caption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchAuthorizations"
      :empty-title="t('famUnit.pickup.table.empty')"
      :card-heading-level="4"
    >
      <template #cell-status="{ row }">
        <span class="inline-flex items-center gap-1">
          <template v-if="row.is_effective">
            <CircleCheck class="size-4" aria-hidden="true" />
            <span>{{ t('famUnit.pickup.status.effective') }}</span>
          </template>
          <template v-else>
            <CircleX class="size-4" aria-hidden="true" />
            <span>{{ t('famUnit.pickup.status.ineffective', { reason: causeLabel(row) }) }}</span>
          </template>
        </span>
      </template>
    </DataTable>
  </div>
</template>
