<script setup lang="ts">
/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §4.7`, `§10` (`REQ-FAM-UNIT-004`). Histórico inmutable de decisiones de
 * imagen de **un** alumno (`GET /image-consent-decisions`, paginación por cursor) con el componente de tablas de
 * `ADR-054`: fecha, nivel, decisión, canal y versión del texto. Solo lectura: las decisiones no se editan ni borran
 * (`RN-FAM-UNIT-42`). La API no expone ni la IP ni el agente de usuario (`api.md §1.6`). La decisión se pinta con texto
 * e icono, nunca solo con color (WCAG 1.4.1).
 */
import { Check, X } from '@lucide/vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFetcher,
} from '@/data-table'
import { listImageConsentDecisions } from '../api'
import { useLabels } from '../composables/useLabels'
import type { ImageConsentDecision } from '../types'
import StatusBadge from './StatusBadge.vue'

const props = defineProps<{
  open: boolean
  studentPerson: string | null
  studentName: string
}>()
const emit = defineEmits<{ 'update:open': [value: boolean] }>()

const t = useT()
const labels = useLabels()
const { formatDateTime } = useDataTableFormatters()

const columns: DataTableColumn<ImageConsentDecision>[] = [
  {
    id: 'occurred_at',
    headerKey: 'famUnit.fields.occurredAt',
    value: (row) => formatDateTime(row.occurred_at),
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
  { id: 'decision', headerKey: 'famUnit.fields.decision', card: 'field' },
  {
    id: 'channel',
    headerKey: 'famUnit.fields.channel',
    value: (row) => labels.channelLabel(row.channel),
    card: 'field',
  },
  {
    id: 'consent_text_version',
    headerKey: 'famUnit.fields.textVersion',
    value: (row) =>
      row.consent_text_version === null
        ? t('famUnit.common.dash')
        : t('famUnit.consents.history.version', {
            number: row.consent_text_version.version_number,
          }),
    card: 'field',
  },
]

const fetchDecisions: DataTableFetcher<ImageConsentDecision> = (query) =>
  listImageConsentDecisions({
    student_person: props.studentPerson ?? '',
    cursor: query.cursor,
  })
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent
      :close-label="t('famUnit.form.close')"
      class="max-h-[90vh] overflow-y-auto sm:max-w-3xl"
      data-slot="fam-unit-consent-history"
    >
      <DialogHeader>
        <DialogTitle>{{ t('famUnit.consents.history.title', { name: studentName }) }}</DialogTitle>
        <DialogDescription>{{ t('famUnit.consents.history.description') }}</DialogDescription>
      </DialogHeader>

      <DataTable
        v-if="open && studentPerson !== null"
        :key="studentPerson"
        table-id="fam_unit.consent_history"
        :caption="t('famUnit.consents.history.caption', { name: studentName })"
        :columns="columns"
        mode="cursor"
        :fetcher="fetchDecisions"
        :empty-title="t('famUnit.consents.history.empty')"
        :card-heading-level="3"
      >
        <template #cell-decision="{ row }">
          <StatusBadge
            :text="labels.decisionLabel(row.decision)"
            :icon="row.decision === 'autorizo' ? Check : X"
            :variant="row.decision === 'autorizo' ? 'outline' : 'destructive'"
          />
        </template>
      </DataTable>

      <DialogFooter>
        <Button type="button" @click="emit('update:open', false)">
          {{ t('famUnit.form.close') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
