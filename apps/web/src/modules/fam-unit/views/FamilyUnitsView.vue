<script setup lang="ts">
/**
 * `/familias` (`docs/modulos/REQ-FAM-UNIT/funcional.md §10`, `§4.1`, `REQ-FAM-UNIT-001`; `1.14-d`). Listado de unidades
 * familiares con el componente de tablas de `ADR-054`: modo `page`, estado en la URL, orden por defecto del servidor
 * (`code`), búsqueda por prefijo de código (`q`) y filtro «con tutor principal». Con ámbito `unidad_familiar` el
 * servidor ya acota las filas y los recuentos (`api.md §1.1`). «Nueva unidad» solo con `familia.crear`; el servidor
 * decide igualmente (`INV-002`).
 *
 * La columna «Tutor principal» dice **si** la unidad lo tiene, no quién es: la API entrega solo su `public_id`
 * (`api.md §1.1`) y el nombre está en la ficha (`D-41`).
 */
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import {
  DataTable,
  type DataTableColumn,
  type DataTableFetcher,
  type DataTableFilter,
} from '@/data-table'
import FamilyUnitFormDialog from '../components/FamilyUnitFormDialog.vue'
import { createFamilyUnit, listFamilyUnits } from '../api'
import { usePermissions } from '../composables/usePermissions'
import type { FamilyUnit } from '../types'

const t = useT()
const router = useRouter()
const { can } = usePermissions()

const canCreate = computed(() => can('familia.crear'))
const canReadConsents = computed(() => can('consentimiento_imagen.leer'))
const formOpen = ref(false)

const filters: DataTableFilter[] = [
  { type: 'boolean', id: 'has_principal', labelKey: 'famUnit.units.filters.hasPrincipal' },
]

const columns: DataTableColumn<FamilyUnit>[] = [
  {
    id: 'code',
    headerKey: 'famUnit.fields.code',
    sortable: true,
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'principal_guardian',
    headerKey: 'famUnit.fields.principalGuardian',
    value: (unit) =>
      unit.principal_guardian === null
        ? t('famUnit.units.principal.none')
        : t('famUnit.units.principal.set'),
    card: 'field',
  },
  {
    id: 'guardians_count',
    headerKey: 'famUnit.fields.guardiansCount',
    align: 'end',
    card: 'field',
  },
  {
    id: 'students_count',
    headerKey: 'famUnit.fields.studentsCount',
    align: 'end',
    card: 'field',
  },
]

const fetchUnits: DataTableFetcher<FamilyUnit> = (query) =>
  listFamilyUnits({
    q: query.q,
    has_principal: query.filters.has_principal,
    sort: query.sort,
    page: query.page,
    per_page: query.per_page,
  })

async function save(body: { code?: string }): Promise<unknown> {
  const unit = await createFamilyUnit(body)

  await router.push({ name: 'fam-unit-detail', params: { publicId: unit.public_id } })

  return unit
}
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-lg font-semibold">{{ t('famUnit.units.title') }}</h1>
        <p class="text-muted-foreground text-sm">{{ t('famUnit.units.intro') }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <Button v-if="canReadConsents" variant="outline" as-child>
          <RouterLink :to="{ name: 'fam-unit-consents' }">{{
            t('famUnit.units.consentsLink')
          }}</RouterLink>
        </Button>
        <Button v-if="canCreate" type="button" @click="formOpen = true">
          {{ t('famUnit.units.new') }}
        </Button>
      </div>
    </div>

    <DataTable
      table-id="fam_unit.units"
      :caption="t('famUnit.units.caption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchUnits"
      :filters="filters"
      searchable
      url-state
      :empty-title="t('famUnit.units.empty.title')"
      :empty-text="canCreate ? t('famUnit.units.empty.text') : ''"
      :empty-action-label="canCreate ? t('famUnit.units.new') : undefined"
      :card-heading-level="2"
      @empty-action="formOpen = true"
    >
      <template #cell-code="{ row }">
        <RouterLink
          :to="{ name: 'fam-unit-detail', params: { publicId: row.public_id } }"
          class="text-primary-on-background focus-visible:ring-ring/50 rounded-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-3"
        >
          {{ row.code }}
        </RouterLink>
      </template>
    </DataTable>

    <FamilyUnitFormDialog v-model:open="formOpen" :save="save" />
  </div>
</template>
