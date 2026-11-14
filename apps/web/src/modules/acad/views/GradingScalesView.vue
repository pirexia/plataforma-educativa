<script setup lang="ts">
/**
 * `/academico/escalas` (`docs/modulos/REQ-ACAD/funcional.md §10.5.1`, `§4.9.1`,
 * `CA-ACAD-098`, `CA-ACAD-099`). Escalas de calificación del curso académico
 * seleccionado con el componente de tablas de 1.9: modo `page`, estado en la
 * URL, orden por defecto del servidor (`code`), filtro múltiple por tipo.
 *
 * El curso académico vive en la URL (`?academic_year=`, `RN-ACAD-40`); sin él,
 * el servidor usa el activo. Sin curso activo, el estado «sin curso activo»
 * (no un error). «Nueva escala» solo con `configuracion_academica.crear` **y**
 * si el curso admite escritura (`RN-ACAD-42`); el servidor decide igualmente
 * (`INV-002`).
 */
import { computed, onMounted, ref } from 'vue'
import { CalendarX } from '@lucide/vue'
import { useT } from '@/i18n'
import { useAcademicYear } from '@/academic-year'
import { ApiError } from '@/api/client'
import { Button } from '@/components/ui/button'
import TranslatedText from '@/components/TranslatedText.vue'
import {
  DataTable,
  type DataTableColumn,
  type DataTableFetcher,
  type DataTableFilter,
} from '@/data-table'
import EmptyState from '@/layouts/components/EmptyState.vue'
import StructureNotices from '../components/StructureNotices.vue'
import { listGradingScales } from '../api'
import { takeFlash } from '../composables/flash'
import { problemErrorCode } from '../composables/problem'
import { hasNumericFields, SCALE_TYPES } from '../composables/scaleForm'
import { useLabels } from '../composables/useLabels'
import { usePermissions } from '../composables/usePermissions'
import type { GradingScale } from '../types'

const t = useT()
const { can } = usePermissions()
const { scaleTypeLabel } = useLabels()
const { urlYear, selectedId, noActive, readOnly, writable } = useAcademicYear()

/** `404 curso.no_active_year` del listado: también es el estado de `RN-ACAD-40`. */
const serverNoActive = ref(false)
const showNoActive = computed(() => noActive.value || serverNoActive.value)

const canCreate = computed(() => can('configuracion_academica.crear') && writable.value)
const message = ref<string | null>(null)

onMounted(() => {
  const key = takeFlash()

  message.value = key === null ? null : t(key)
})

const filters: DataTableFilter[] = [
  {
    type: 'enum',
    id: 'scale_type',
    labelKey: 'acad.fields.scaleType',
    options: SCALE_TYPES.map((value) => ({ value, labelKey: `acad.scaleType.${value}` })),
  },
]

function rangeOf(scale: GradingScale): string {
  return hasNumericFields(scale.scale_type)
    ? t('acad.scales.rangeValue', { min: scale.min_value ?? '', max: scale.max_value ?? '' })
    : t('acad.scales.valuesCount', { count: scale.values.length }, scale.values.length)
}

/** Umbral en la numérica y la mixta; códigos de los valores aprobados en la cualitativa. */
function passingOf(scale: GradingScale): string {
  return hasNumericFields(scale.scale_type)
    ? t('acad.scales.passingFrom', { value: scale.passing_min_value ?? '' })
    : scale.values
        .filter((value) => value.is_passing === true)
        .map((value) => value.code)
        .join(', ')
}

const columns: DataTableColumn<GradingScale>[] = [
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
    id: 'scale_type',
    headerKey: 'acad.fields.scaleType',
    value: (scale) => scaleTypeLabel(scale.scale_type),
    card: 'field',
  },
  { id: 'range', headerKey: 'acad.fields.range', value: rangeOf, card: 'field' },
  { id: 'passing', headerKey: 'acad.scales.passing', value: passingOf, card: 'field' },
]

function split(value: string | undefined): string[] | undefined {
  return value ? value.split(',').filter((item) => item !== '') : undefined
}

const fetchScales: DataTableFetcher<GradingScale> = async (query) => {
  serverNoActive.value = false

  try {
    return await listGradingScales({
      academic_year: urlYear.value ?? undefined,
      scale_type: split(query.filters.scale_type),
      sort: query.sort,
      page: query.page,
      per_page: query.per_page,
    })
  } catch (err) {
    if (
      err instanceof ApiError &&
      err.status === 404 &&
      problemErrorCode(err, 'academic_year') === 'curso.no_active_year'
    ) {
      serverNoActive.value = true

      return {
        data: [],
        meta: { current_page: 1, per_page: query.per_page ?? 25, total: 0, last_page: 1 },
      }
    }

    throw err
  }
}

const newTarget = computed(() => ({
  name: 'acad-grading-scale-new',
  query: selectedId.value ? { academic_year: selectedId.value } : {},
}))
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-lg font-semibold">{{ t('acad.scales.title') }}</h1>
        <p class="text-muted-foreground text-sm">{{ t('acad.scales.intro') }}</p>
      </div>
      <Button v-if="canCreate" as-child>
        <RouterLink :to="newTarget">{{ t('acad.scales.new') }}</RouterLink>
      </Button>
    </div>

    <StructureNotices :read-only="false" :message="message" :action-error="null" :dependents="[]" />

    <p
      v-if="readOnly"
      role="note"
      data-slot="acad-scales-read-only"
      class="border-border bg-muted rounded-lg border px-3 py-2 text-sm"
    >
      {{ t('acad.readOnlyConfig') }}
    </p>

    <!-- RN-ACAD-40: sin curso activo no es un error; el selector sigue a la vista. -->
    <EmptyState
      v-if="showNoActive"
      :icon="CalendarX"
      :title="t('acad.structure.noActive.title')"
      :text="t('acad.structure.noActive.text')"
    />

    <DataTable
      v-else
      :key="urlYear ?? 'active'"
      table-id="acad.grading_scales"
      :caption="t('acad.scales.caption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchScales"
      :filters="filters"
      url-state
      :empty-title="t('acad.scales.empty')"
      :card-heading-level="2"
    >
      <template #cell-code="{ row }">
        <RouterLink
          :to="{ name: 'acad-grading-scale-detail', params: { publicId: row.public_id } }"
          class="text-primary-on-background focus-visible:ring-ring/50 rounded-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-3"
        >
          {{ row.code }}
        </RouterLink>
      </template>
      <template #cell-name="{ row }">
        <TranslatedText :text="row.name" :translations="row.name_translations" />
      </template>
    </DataTable>
  </div>
</template>
