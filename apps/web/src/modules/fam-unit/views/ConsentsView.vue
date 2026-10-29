<script setup lang="ts">
/**
 * `/familias/consentimientos` (`docs/modulos/REQ-FAM-UNIT/funcional.md §10`, `§4.7`, `§4.8`, `REQ-FAM-UNIT-004`; `1.14-d`).
 * Estado vigente del consentimiento de imagen **calculado** por alumno y nivel (`ADR-066 §5.2`), con el componente de
 * tablas de `ADR-054`: modo `page`, estado en la URL, filtros por nivel y estado, histórico de decisiones por alumno
 * (diálogo) y el informe CSV en cola (`consentimiento_imagen.exportar`, `INV-012`).
 *
 * `autorizo`, `no_autorizo` y `pendiente` se pintan con texto e icono propios, nunca solo con color (WCAG 1.4.1). La
 * fila no tiene `public_id` (representación calculada, `api.md §1.5`): se identifica por alumno y nivel.
 */
import { computed, ref } from 'vue'
import { CircleCheck, CircleHelp, CircleX } from '@lucide/vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { getDataExport } from '@/modules/core/api'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFetcher,
  type DataTableFilter,
} from '@/data-table'
import ConsentHistoryDialog from '../components/ConsentHistoryDialog.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { exportImageConsents, listImageConsents } from '../api'
import { useLabels } from '../composables/useLabels'
import { usePermissions } from '../composables/usePermissions'
import type { ImageConsent } from '../types'

const LEVELS = ['web', 'redes_sociales']
const STATES = ['autorizo', 'no_autorizo', 'pendiente']

const t = useT()
const { can } = usePermissions()
const { formatDateTime } = useDataTableFormatters()
const labels = useLabels()

const historyOpen = ref(false)
const historyStudent = ref<ImageConsent | null>(null)

const filters: DataTableFilter[] = [
  {
    type: 'enum',
    id: 'level',
    labelKey: 'famUnit.fields.level',
    options: LEVELS.map((value) => ({ value, labelKey: `famUnit.enums.consentLevel.${value}` })),
  },
  {
    type: 'enum',
    id: 'state',
    labelKey: 'famUnit.fields.state',
    options: STATES.map((value) => ({ value, labelKey: `famUnit.enums.consentState.${value}` })),
  },
]

const columns: DataTableColumn<ImageConsent>[] = [
  {
    id: 'display_name',
    headerKey: 'famUnit.fields.student',
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'level',
    headerKey: 'famUnit.fields.level',
    value: (row) => labels.consentLevelLabel(row.level),
    card: 'field',
  },
  { id: 'state', headerKey: 'famUnit.fields.state', sortable: true, card: 'field' },
  {
    id: 'last_decision_at',
    headerKey: 'famUnit.fields.lastDecisionAt',
    sortable: true,
    value: (row) => formatDateTime(row.last_decision_at),
    card: 'field',
  },
  { id: 'family_unit', headerKey: 'famUnit.fields.familyUnit', card: 'field' },
  { id: 'actions', headerKey: 'famUnit.fields.actions', hideable: false, card: 'actions' },
]

const fetchConsents: DataTableFetcher<ImageConsent> = (query) =>
  listImageConsents({
    level: query.filters.level,
    state: query.filters.state,
    sort: query.sort,
    page: query.page,
    per_page: query.per_page,
  })

const exportConfig = computed(() => ({
  canExport: can('consentimiento_imagen.exportar'),
  request: exportImageConsents,
  status: getDataExport,
}))

function consentIcon(state: string) {
  if (state === 'autorizo') return CircleCheck
  if (state === 'no_autorizo') return CircleX

  return CircleHelp
}

function stateVariant(state: string): 'outline' | 'destructive' | 'secondary' {
  if (state === 'autorizo') return 'outline'

  return state === 'no_autorizo' ? 'destructive' : 'secondary'
}

function openHistory(row: ImageConsent): void {
  historyStudent.value = row
  historyOpen.value = true
}

const rowKey = (row: ImageConsent): string => `${row.student_person}:${row.level}`
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-6">
    <div>
      <h1 class="text-lg font-semibold">{{ t('famUnit.consents.title') }}</h1>
      <p class="text-muted-foreground text-sm">{{ t('famUnit.consents.intro') }}</p>
    </div>

    <DataTable
      table-id="fam_unit.consents"
      :caption="t('famUnit.consents.caption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchConsents"
      :filters="filters"
      :row-key="rowKey"
      url-state
      :export-config="exportConfig"
      :empty-title="t('famUnit.consents.empty.title')"
      :empty-text="t('famUnit.consents.empty.text')"
      :card-heading-level="2"
    >
      <template #cell-state="{ row }">
        <StatusBadge
          :text="labels.consentStateLabel(row.state)"
          :icon="consentIcon(row.state)"
          :variant="stateVariant(row.state)"
        />
      </template>
      <template #cell-family_unit="{ row }">
        <RouterLink
          :to="{ name: 'fam-unit-detail', params: { publicId: row.family_unit } }"
          class="text-primary-on-background focus-visible:ring-ring/50 rounded-sm underline-offset-4 outline-none hover:underline focus-visible:ring-3"
          :aria-label="t('famUnit.consents.openUnitFor', { name: row.display_name })"
        >
          {{ t('famUnit.consents.openUnit') }}
        </RouterLink>
      </template>
      <template #cell-actions="{ row }">
        <Button
          type="button"
          variant="outline"
          size="sm"
          :aria-label="t('famUnit.consents.historyFor', { name: row.display_name })"
          @click="openHistory(row)"
        >
          {{ t('famUnit.consents.history.open') }}
        </Button>
      </template>
    </DataTable>

    <ConsentHistoryDialog
      v-model:open="historyOpen"
      :student-person="historyStudent?.student_person ?? null"
      :student-name="historyStudent?.display_name ?? ''"
    />
  </div>
</template>
