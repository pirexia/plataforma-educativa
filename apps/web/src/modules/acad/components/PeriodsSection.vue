<script setup lang="ts">
/**
 * `docs/modulos/REQ-ACAD/funcional.md §10.5.1`-`§10.5.4`, `§4.8.1`-`§4.8.4`,
 * `OPEN-ACAD-29` = A. Sección «Períodos lectivos» de la ficha de etapa
 * (`acad-level-detail`), visible solo con `configuracion_academica.leer`.
 *
 * - La SPA pide **siempre** `GET /academic-periods` con `academic_year` igual al
 *   curso académico de la etapa **y** `education_level` (`§4.8.1`, `CA-ACAD-103`),
 *   con `per_page=100` y sin paginar en cliente (una etapa tiene pocos períodos).
 * - «Nuevo período», «Editar» y «Borrar» solo con `configuracion_academica.crear`
 *   / `actualizar` / `eliminar` **y** si el curso admite escritura (`RN-ACAD-42`,
 *   `CA-ACAD-099`); con un curso de solo lectura, aviso persistente de texto.
 * - Un `409` (`academic-year-closed`) o cualquier error de la acción de borrado se
 *   muestra con el mensaje del servidor junto a la acción; la tabla no cambia
 *   hasta recargar (`CA-ACAD-050`).
 */
import { computed, ref } from 'vue'
import { useAcademicYear } from '@/academic-year'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import TranslatedText from '@/components/TranslatedText.vue'
import { DataTable, type DataTableColumn, type DataTableFetcher } from '@/data-table'
import {
  createAcademicPeriod,
  deleteAcademicPeriod,
  listAcademicPeriods,
  updateAcademicPeriod,
} from '../api'
import { formatCivilDate } from '../composables/civilDate'
import { useStructureActions } from '../composables/useStructureActions'
import { usePermissions } from '../composables/usePermissions'
import type { AcademicPeriod, EducationLevel } from '../types'
import PeriodFormDialog from './PeriodFormDialog.vue'
import StructureNotices from './StructureNotices.vue'

const props = defineProps<{ level: EducationLevel }>()

/** `§10.5.1`: una etapa tiene pocos períodos; la API se pide con `per_page=100`. */
const PERIODS_PER_PAGE = 100

const t = useT()
const { can } = usePermissions()
const { readOnly, writable, selected } = useAcademicYear()
const actions = useStructureActions()

const canRead = computed(() => can('configuracion_academica.leer'))
const canCreate = computed(() => can('configuracion_academica.crear') && writable.value)
const canUpdate = computed(() => can('configuracion_academica.actualizar') && writable.value)
const canDelete = computed(() => can('configuracion_academica.eliminar') && writable.value)
const hasRowActions = computed(() => canUpdate.value || canDelete.value)

const table = ref<{ refresh: () => Promise<void> } | null>(null)
/** Períodos de la última carga: nombran el solape del formulario (`§10.5.2`). */
const loaded = ref<AcademicPeriod[]>([])
const editing = ref<AcademicPeriod | null>(null)
const formOpen = ref(false)

const yearStart = computed(() =>
  selected.value ? formatCivilDate(selected.value.starts_on) : null,
)
const yearEnd = computed(() => (selected.value ? formatCivilDate(selected.value.ends_on) : null))

function typeLabel(code: string): string {
  const key = `acad.periodType.${code}`
  const text = t(key)

  return text === key ? code : text
}

const columns = computed<DataTableColumn<AcademicPeriod>[]>(() => [
  {
    id: 'name',
    headerKey: 'acad.fields.name',
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'period_type',
    headerKey: 'acad.fields.periodType',
    value: (row) => typeLabel(row.period_type),
    card: 'subtitle',
  },
  {
    id: 'starts_on',
    headerKey: 'acad.fields.startsOn',
    value: (row) => formatCivilDate(row.starts_on),
    card: 'field',
  },
  {
    id: 'ends_on',
    headerKey: 'acad.fields.endsOn',
    value: (row) => formatCivilDate(row.ends_on),
    card: 'field',
  },
  { id: 'position', headerKey: 'acad.fields.position', align: 'end', card: 'field' },
  ...(hasRowActions.value
    ? [
        {
          id: 'actions',
          headerKey: 'acad.fields.actions',
          hideable: false,
          card: 'actions',
        } satisfies DataTableColumn<AcademicPeriod>,
      ]
    : []),
])

const fetchPeriods: DataTableFetcher<AcademicPeriod> = async () => {
  const result = await listAcademicPeriods({
    academic_year: props.level.academic_year,
    education_level: [props.level.public_id],
    per_page: PERIODS_PER_PAGE,
  })

  loaded.value = result.data

  return result
}

function startCreate(): void {
  editing.value = null
  formOpen.value = true
}

function startEdit(period: AcademicPeriod): void {
  editing.value = period
  formOpen.value = true
}

function save(body: Record<string, unknown>): Promise<unknown> {
  const current = editing.value

  return current === null
    ? createAcademicPeriod(body as unknown as Parameters<typeof createAcademicPeriod>[0])
    : updateAcademicPeriod(current.public_id, body)
}

async function onSaved(): Promise<void> {
  const created = editing.value === null

  actions.clear()
  actions.message.value = t(created ? 'acad.flash.periodCreated' : 'acad.flash.periodUpdated')
  await table.value?.refresh()
}

async function remove(period: AcademicPeriod): Promise<void> {
  const done = await actions.confirmAndRun(
    {
      title: t('acad.confirm.delete.period.title', { name: period.name }),
      description: t('acad.confirm.delete.period.description'),
      confirmLabel: t('acad.confirm.delete.confirm', { code: period.name }),
      destructive: true,
    },
    () => deleteAcademicPeriod(period.public_id),
    t('acad.flash.periodDeleted'),
  )

  if (done) {
    await table.value?.refresh()
  }
}
</script>

<template>
  <section
    v-if="canRead"
    class="flex flex-col gap-3"
    aria-labelledby="acad-level-periods"
    data-slot="acad-periods"
  >
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 id="acad-level-periods" class="text-sm font-semibold">{{ t('acad.periods.title') }}</h2>
      <Button v-if="canCreate" type="button" size="sm" @click="startCreate">
        {{ t('acad.periods.new') }}
      </Button>
    </div>

    <p
      v-if="readOnly"
      role="note"
      data-slot="acad-periods-read-only"
      class="border-border bg-muted rounded-lg border px-3 py-2 text-sm"
    >
      {{ t('acad.readOnlyConfig') }}
    </p>

    <StructureNotices
      :read-only="false"
      :message="actions.message.value"
      :action-error="actions.actionError.value"
      :dependents="actions.dependents.value"
    />

    <DataTable
      ref="table"
      table-id="acad.level_periods"
      :caption="t('acad.periods.caption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchPeriods"
      :empty-title="t('acad.periods.empty')"
      :card-heading-level="3"
      hide-single-page-footer
    >
      <template #cell-name="{ row }">
        <TranslatedText :text="row.name" :translations="row.name_translations" />
      </template>
      <template #cell-actions="{ row }">
        <div class="flex flex-wrap justify-end gap-2">
          <Button
            v-if="canUpdate"
            type="button"
            variant="outline"
            size="sm"
            :aria-label="t('acad.periods.editFor', { name: row.name })"
            @click="startEdit(row)"
          >
            {{ t('acad.detail.edit') }}
          </Button>
          <Button
            v-if="canDelete"
            type="button"
            variant="outline"
            size="sm"
            :disabled="actions.busy.value"
            :aria-label="t('acad.periods.deleteFor', { name: row.name })"
            @click="remove(row)"
          >
            {{ t('acad.detail.delete') }}
          </Button>
        </div>
      </template>
    </DataTable>

    <PeriodFormDialog
      v-model:open="formOpen"
      :period="editing"
      :education-level="level.public_id"
      :periods="loaded"
      :year-start="yearStart"
      :year-end="yearEnd"
      :save="save"
      @saved="onSaved"
    />

    <ConfirmDialog
      :open="actions.confirmation.open.value"
      :request="actions.confirmation.request.value"
      @confirm="actions.confirmation.confirm"
      @cancel="actions.confirmation.cancel"
    />
  </section>
</template>
