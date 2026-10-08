<script setup lang="ts">
/**
 * `/administracion/cursos` (`docs/modulos/REQ-CURSO/funcional.md §10.1`,
 * 1.10). Listado de cursos con el componente de tablas de 1.9: modo `page`,
 * estado en la URL (`RN-CORE-54`), orden por defecto `-starts_on` (el del
 * servidor), filtro de estado `enum` múltiple. Sin búsqueda `q` (unos pocos
 * cursos por centro, no hay búsqueda que justificar) ni exportación
 * (`permisos.md §2.1`).
 *
 * Permiso, nunca rol (`RN-CORE-61`): la ruta exige `curso_academico.leer`;
 * «Nuevo curso» solo con `curso_academico.crear`. El estado se muestra con
 * texto traducido, no solo con color (WCAG 1.4.1).
 */
import { useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import {
  DataTable,
  type DataTableColumn,
  type DataTableFetcher,
  type DataTableFilter,
} from '@/data-table'
import { listAcademicYears } from '../api'
import { formatCivilDate } from '../civilDate'
import { usePermissions } from '../composables/usePermissions'
import type { AcademicYear } from '../types'

const t = useT()
const router = useRouter()
const { can } = usePermissions()

const STATUSES = ['planificacion', 'activo', 'cerrado', 'archivado']

/** `ADR-038 §7.3`: un valor no anticipado muestra su código, no rompe. */
function statusLabel(status: string): string {
  const key = `curso.status.${status}`
  const label = t(key)

  return label === key ? status : label
}

const filters: DataTableFilter[] = [
  {
    type: 'enum',
    id: 'status',
    labelKey: 'curso.academicYears.filters.status',
    options: STATUSES.map((value) => ({ value, labelKey: `curso.status.${value}` })),
  },
]

const columns: DataTableColumn<AcademicYear>[] = [
  {
    id: 'code',
    headerKey: 'curso.academicYears.columns.code',
    sortable: true,
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'starts_on',
    headerKey: 'curso.academicYears.columns.startsOn',
    value: (year) => formatCivilDate(year.starts_on),
    sortable: true,
    card: 'field',
  },
  {
    id: 'ends_on',
    headerKey: 'curso.academicYears.columns.endsOn',
    value: (year) => formatCivilDate(year.ends_on),
    card: 'field',
  },
  {
    id: 'status',
    headerKey: 'curso.academicYears.columns.status',
    value: (year) => statusLabel(year.status),
    card: 'subtitle',
  },
]

function split(value: string | undefined): string[] | undefined {
  return value ? value.split(',').filter((item) => item !== '') : undefined
}

const fetchYears: DataTableFetcher<AcademicYear> = (query) =>
  listAcademicYears({
    status: split(query.filters.status),
    sort: query.sort,
    page: query.page,
    per_page: query.per_page,
  })

function goToNew(): void {
  void router.push({ name: 'curso-academic-year-new' })
}
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-lg font-semibold">{{ t('curso.academicYears.title') }}</h1>
        <p class="text-muted-foreground text-sm">{{ t('curso.academicYears.intro') }}</p>
      </div>
      <Button v-if="can('curso_academico.crear')" as-child>
        <RouterLink :to="{ name: 'curso-academic-year-new' }">
          {{ t('curso.academicYears.new') }}
        </RouterLink>
      </Button>
    </div>

    <DataTable
      table-id="curso.academic_years"
      :caption="t('curso.academicYears.caption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchYears"
      :filters="filters"
      url-state
      :empty-title="t('curso.academicYears.empty.title')"
      :empty-text="can('curso_academico.crear') ? t('curso.academicYears.empty.text') : ''"
      :empty-action-label="can('curso_academico.crear') ? t('curso.academicYears.new') : undefined"
      :card-heading-level="2"
      @empty-action="goToNew"
    >
      <template #cell-code="{ row }">
        <RouterLink
          :to="{ name: 'curso-academic-year-detail', params: { publicId: row.public_id } }"
          class="text-primary-on-background focus-visible:ring-ring/50 rounded-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-3"
        >
          {{ row.code }}
        </RouterLink>
      </template>
    </DataTable>
  </div>
</template>
