<script setup lang="ts">
/**
 * `/academico/estructura` (`docs/modulos/REQ-ACAD/funcional.md §10.1`, `§4.1`,
 * `§4.2`, `CA-ACAD-045..049`). Etapas del curso académico seleccionado con el
 * componente de tablas de 1.9: modo `page`, estado en la URL, orden por defecto
 * del servidor (`position`), filtros múltiples de tipo y régimen. Sin búsqueda
 * `q` ni exportación (`api.md §3`, `§4`).
 *
 * El curso académico vive en la URL (`?academic_year=`, `RN-ACAD-40`); sin él,
 * el servidor usa el activo. Sin curso activo, el estado «sin curso activo» con
 * el selector a la vista, no un error (`CA-ACAD-049`). «Nueva etapa» solo con
 * `estructura_academica.crear` **y** si el curso admite escritura
 * (`CA-ACAD-046`, `RN-ACAD-42`); el servidor decide igualmente (`INV-002`).
 */
import { computed, ref } from 'vue'
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
import EntityFormDialog from '../components/EntityFormDialog.vue'
import StructureNotices from '../components/StructureNotices.vue'
import { createEducationLevel, listEducationLevels } from '../api'
import { problemErrorCode } from '../composables/problem'
import { useLabels } from '../composables/useLabels'
import { usePermissions } from '../composables/usePermissions'
import type { EducationLevel } from '../types'

const t = useT()
const { can } = usePermissions()
const { stageLabel, regimeLabel } = useLabels()
const { urlYear, selectedId, noActive, readOnly, writable } = useAcademicYear()

const STAGES = ['infantil', 'primaria', 'eso', 'bachillerato', 'fp', 'universidad']
const REGIMES = ['publico', 'privado', 'concertado']

/** `404 curso.no_active_year` del listado (`CA-ACAD-031`): también es el estado de `RN-ACAD-40`. */
const serverNoActive = ref(false)
const showNoActive = computed(() => noActive.value || serverNoActive.value)

const canCreate = computed(() => can('estructura_academica.crear') && writable.value)

const table = ref<{ refresh: () => Promise<void> } | null>(null)
const creating = ref(false)
const message = ref<string | null>(null)

const filters: DataTableFilter[] = [
  {
    type: 'enum',
    id: 'stage',
    labelKey: 'acad.structure.filters.stage',
    options: STAGES.map((value) => ({ value, labelKey: `acad.stage.${value}` })),
  },
  {
    type: 'enum',
    id: 'regime',
    labelKey: 'acad.structure.filters.regime',
    options: REGIMES.map((value) => ({ value, labelKey: `acad.regime.${value}` })),
  },
]

const columns: DataTableColumn<EducationLevel>[] = [
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
    id: 'stage',
    headerKey: 'acad.fields.stage',
    value: (level) => stageLabel(level.stage),
    card: 'field',
  },
  {
    id: 'regime',
    headerKey: 'acad.fields.regime',
    value: (level) => regimeLabel(level.regime),
    card: 'field',
  },
  {
    id: 'position',
    headerKey: 'acad.fields.position',
    sortable: true,
    align: 'end',
    card: 'field',
  },
]

function split(value: string | undefined): string[] | undefined {
  return value ? value.split(',').filter((item) => item !== '') : undefined
}

const fetchLevels: DataTableFetcher<EducationLevel> = async (query) => {
  serverNoActive.value = false

  try {
    return await listEducationLevels({
      academic_year: urlYear.value ?? undefined,
      stage: split(query.filters.stage),
      regime: split(query.filters.regime),
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

/** `RN-CURSO-22`: la etapa es la raíz y lleva el curso académico explícito. */
const createExtra = computed(() => ({ academic_year: selectedId.value }))

function saveLevel(body: Record<string, unknown>): Promise<unknown> {
  return createEducationLevel(body as unknown as Parameters<typeof createEducationLevel>[0])
}

async function onSaved(): Promise<void> {
  message.value = t('acad.flash.levelCreated')
  await table.value?.refresh()
}

function openCreate(): void {
  message.value = null
  creating.value = true
}
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-lg font-semibold">{{ t('acad.structure.title') }}</h1>
        <p class="text-muted-foreground text-sm">{{ t('acad.structure.intro') }}</p>
      </div>
      <Button v-if="canCreate" type="button" @click="openCreate">
        {{ t('acad.structure.new') }}
      </Button>
    </div>

    <StructureNotices
      :read-only="readOnly"
      :message="message"
      :action-error="null"
      :dependents="[]"
    />

    <!-- RN-ACAD-40, CA-ACAD-049: sin curso activo no es un error; el selector sigue a la vista. -->
    <EmptyState
      v-if="showNoActive"
      :icon="CalendarX"
      :title="t('acad.structure.noActive.title')"
      :text="t('acad.structure.noActive.text')"
    />

    <DataTable
      v-else
      :key="urlYear ?? 'active'"
      ref="table"
      table-id="acad.education_levels"
      :caption="t('acad.structure.caption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchLevels"
      :filters="filters"
      url-state
      :empty-title="t('acad.structure.empty.title')"
      :empty-text="canCreate ? t('acad.structure.empty.text') : ''"
      :empty-action-label="canCreate ? t('acad.structure.new') : undefined"
      :card-heading-level="2"
      @empty-action="openCreate"
    >
      <template #cell-code="{ row }">
        <RouterLink
          :to="{ name: 'acad-level-detail', params: { publicId: row.public_id } }"
          class="text-primary-on-background focus-visible:ring-ring/50 rounded-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-3"
        >
          {{ row.code }}
        </RouterLink>
      </template>
      <template #cell-name="{ row }">
        <TranslatedText :text="row.name" :translations="row.name_translations" />
      </template>
    </DataTable>

    <EntityFormDialog
      v-model:open="creating"
      kind="level"
      :entity="null"
      :extra="createExtra"
      :save="saveLevel"
      @saved="onSaved"
    />
  </div>
</template>
