<script setup lang="ts">
/**
 * `/academico/espacios` (`docs/modulos/REQ-ACAD/funcional.md §18.8`, `§18.4.3`,
 * `CA-ACAD-115`, `CA-ACAD-116`). Espacios del centro (aulas y recursos) con el
 * componente de tablas de 1.9: modo `page`, estado en la URL, orden por defecto
 * del servidor (`code`), filtro múltiple por tipo. Los espacios **no tienen curso
 * académico** (`OPEN-ACAD-33`): ni selector ni aviso de solo lectura, y se
 * pueden editar con el curso cerrado. «Nuevo espacio», «Editar» y «Borrar» solo
 * con `espacios.crear` / `actualizar` / `eliminar`; el servidor decide
 * igualmente (`INV-002`). Un `409 has_dependents` (aula con sesiones en un curso
 * en planificación o activo) se muestra junto a la acción.
 */
import { computed, ref } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import {
  DataTable,
  type DataTableColumn,
  type DataTableFetcher,
  type DataTableFilter,
} from '@/data-table'
import FacilityFormDialog from '../components/FacilityFormDialog.vue'
import StructureNotices from '../components/StructureNotices.vue'
import { createFacility, deleteFacility, listFacilities, updateFacility } from '../api'
import { useLabels } from '../composables/useLabels'
import { usePermissions } from '../composables/usePermissions'
import { useStructureActions } from '../composables/useStructureActions'
import type { Facility } from '../types'

const FACILITY_TYPES = ['aula', 'recurso']

const t = useT()
const { can } = usePermissions()
const { facilityTypeLabel } = useLabels()
const actions = useStructureActions()

const canCreate = computed(() => can('espacios.crear'))
const canUpdate = computed(() => can('espacios.actualizar'))
const canDelete = computed(() => can('espacios.eliminar'))
const hasRowActions = computed(() => canUpdate.value || canDelete.value)

const table = ref<{ refresh: () => Promise<void> } | null>(null)
const editing = ref<Facility | null>(null)
const formOpen = ref(false)

const filters: DataTableFilter[] = [
  {
    type: 'enum',
    id: 'facility_type',
    labelKey: 'acad.fields.facilityType',
    options: FACILITY_TYPES.map((value) => ({ value, labelKey: `acad.facilityType.${value}` })),
  },
]

const columns = computed<DataTableColumn<Facility>[]>(() => [
  {
    id: 'code',
    headerKey: 'acad.fields.code',
    sortable: true,
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  { id: 'name', headerKey: 'acad.fields.name', card: 'subtitle' },
  {
    id: 'facility_type',
    headerKey: 'acad.fields.facilityType',
    value: (row) => facilityTypeLabel(row.facility_type),
    card: 'field',
  },
  ...(hasRowActions.value
    ? [
        {
          id: 'actions',
          headerKey: 'acad.fields.actions',
          hideable: false,
          card: 'actions',
        } satisfies DataTableColumn<Facility>,
      ]
    : []),
])

function split(value: string | undefined): string[] | undefined {
  return value ? value.split(',').filter((item) => item !== '') : undefined
}

const fetchFacilities: DataTableFetcher<Facility> = (query) =>
  listFacilities({
    facility_type: split(query.filters.facility_type),
    sort: query.sort,
    page: query.page,
    per_page: query.per_page,
  })

function startCreate(): void {
  editing.value = null
  formOpen.value = true
}

function startEdit(facility: Facility): void {
  editing.value = facility
  formOpen.value = true
}

function save(body: Record<string, unknown>): Promise<unknown> {
  const current = editing.value

  return current === null
    ? createFacility(body as unknown as Parameters<typeof createFacility>[0])
    : updateFacility(current.public_id, body)
}

async function onSaved(): Promise<void> {
  const created = editing.value === null

  actions.clear()
  actions.message.value = t(created ? 'acad.flash.facilityCreated' : 'acad.flash.facilityUpdated')
  await table.value?.refresh()
}

async function remove(facility: Facility): Promise<void> {
  const done = await actions.confirmAndRun(
    {
      title: t('acad.confirm.delete.facility.title', { code: facility.code }),
      description: t('acad.confirm.delete.facility.description'),
      confirmLabel: t('acad.confirm.delete.confirm', { code: facility.code }),
      destructive: true,
    },
    () => deleteFacility(facility.public_id),
    t('acad.flash.facilityDeleted'),
  )

  if (done) {
    await table.value?.refresh()
  }
}
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-lg font-semibold">{{ t('acad.facilities.title') }}</h1>
        <p class="text-muted-foreground text-sm">{{ t('acad.facilities.intro') }}</p>
      </div>
      <Button v-if="canCreate" type="button" @click="startCreate">
        {{ t('acad.facilities.new') }}
      </Button>
    </div>

    <StructureNotices
      :read-only="false"
      :message="actions.message.value"
      :action-error="actions.actionError.value"
      :dependents="actions.dependents.value"
    />

    <DataTable
      ref="table"
      table-id="acad.facilities"
      :caption="t('acad.facilities.caption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchFacilities"
      :filters="filters"
      url-state
      :empty-title="t('acad.facilities.empty')"
      :card-heading-level="2"
    >
      <template #cell-actions="{ row }">
        <div class="flex flex-wrap justify-end gap-2">
          <Button
            v-if="canUpdate"
            type="button"
            variant="outline"
            size="sm"
            :aria-label="t('acad.facilities.editFor', { code: row.code })"
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
            :aria-label="t('acad.facilities.deleteFor', { code: row.code })"
            @click="remove(row)"
          >
            {{ t('acad.detail.delete') }}
          </Button>
        </div>
      </template>
    </DataTable>

    <FacilityFormDialog v-model:open="formOpen" :facility="editing" :save="save" @saved="onSaved" />

    <ConfirmDialog
      :open="actions.confirmation.open.value"
      :request="actions.confirmation.request.value"
      @confirm="actions.confirmation.confirm"
      @cancel="actions.confirmation.cancel"
    />
  </div>
</template>
