<script setup lang="ts">
/**
 * `/academico/etapas/:publicId` (`docs/modulos/REQ-ACAD/funcional.md §10.1`,
 * `§4.2`-`§4.5`). Ficha de una etapa y sus cursos de etapa. El selector de curso
 * académico queda fijo en el de la entidad (**[DERIVADA]**). «Editar», «Borrar»
 * y «Nuevo curso» solo con su permiso **y** si el curso académico admite
 * escritura (`CA-ACAD-046`); la sección «Períodos lectivos» (`1.11d-a`,
 * `PeriodsSection`, `§10.5.1`) cuelga de esta ficha; sin hijos vivos para borrar (`RN-ACAD-20`, el
 * servidor responde `409 has_dependents` y se muestra junto a la acción).
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { usePinAcademicYear, useAcademicYear } from '@/academic-year'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import TranslatedText from '@/components/TranslatedText.vue'
import { DataTable, type DataTableColumn, type DataTableFetcher } from '@/data-table'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import EntityFormDialog from '../components/EntityFormDialog.vue'
import PeriodsSection from '../components/PeriodsSection.vue'
import StructureNotices from '../components/StructureNotices.vue'
import {
  createCourse,
  deleteEducationLevel,
  getEducationLevel,
  listCourses,
  updateEducationLevel,
} from '../api'
import { useLabels } from '../composables/useLabels'
import { useLoad } from '../composables/useLoad'
import { usePermissions } from '../composables/usePermissions'
import { useStructureActions } from '../composables/useStructureActions'
import type { Course, EducationLevel } from '../types'

const t = useT()
const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const { stageLabel, regimeLabel } = useLabels()
const actions = useStructureActions()

const publicId = computed(() => String(route.params.publicId))
const {
  data: level,
  loading,
  error,
  load,
} = useLoad<EducationLevel>(() => getEducationLevel(publicId.value))

usePinAcademicYear(computed(() => level.value?.academic_year ?? null))
const { readOnly, writable } = useAcademicYear()

const canUpdate = computed(() => can('estructura_academica.actualizar') && writable.value)
const canDelete = computed(() => can('estructura_academica.eliminar') && writable.value)
const canCreate = computed(() => can('estructura_academica.crear') && writable.value)

const editing = ref(false)
const creatingCourse = ref(false)
const table = ref<{ refresh: () => Promise<void> } | null>(null)

const columns: DataTableColumn<Course>[] = [
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
    id: 'position',
    headerKey: 'acad.fields.position',
    sortable: true,
    align: 'end',
    card: 'field',
  },
]

const fetchCourses: DataTableFetcher<Course> = (query) =>
  listCourses({
    academic_year: level.value?.academic_year,
    education_level: [publicId.value],
    sort: query.sort,
    page: query.page,
    per_page: query.per_page,
  })

onMounted(load)

function saveLevel(body: Record<string, unknown>): Promise<unknown> {
  return updateEducationLevel(publicId.value, body)
}

function saveCourse(body: Record<string, unknown>): Promise<unknown> {
  return createCourse(body as unknown as Parameters<typeof createCourse>[0])
}

async function onEdited(): Promise<void> {
  actions.clear()
  actions.message.value = t('acad.flash.levelUpdated')
  await load()
}

async function onCourseCreated(): Promise<void> {
  actions.clear()
  actions.message.value = t('acad.flash.courseCreated')
  await table.value?.refresh()
}

async function remove(): Promise<void> {
  const current = level.value

  if (!current) return

  const done = await actions.confirmAndRun(
    {
      title: t('acad.confirm.delete.level.title', { code: current.code }),
      description: t('acad.confirm.delete.level.description'),
      confirmLabel: t('acad.confirm.delete.confirm', { code: current.code }),
      destructive: true,
    },
    () => deleteEducationLevel(current.public_id),
    t('acad.flash.levelDeleted'),
  )

  if (done) {
    void router.push({ name: 'acad-structure', query: { academic_year: current.academic_year } })
  }
}
</script>

<template>
  <div class="mx-auto flex max-w-5xl flex-col gap-6 px-4 py-6">
    <LoadingState v-if="loading" />
    <ErrorState v-else-if="error" :state="error" @retry="load" />

    <template v-else-if="level">
      <header class="flex flex-col gap-1">
        <p class="text-sm">
          <RouterLink
            :to="{ name: 'acad-structure', query: { academic_year: level.academic_year } }"
            class="text-primary-on-background underline underline-offset-4"
          >
            {{ t('acad.nav.backToStructure') }}
          </RouterLink>
        </p>
        <h1 class="text-lg font-semibold">
          {{ level.code }} ·
          <TranslatedText :text="level.name" :translations="level.name_translations" />
        </h1>
      </header>

      <StructureNotices
        :read-only="readOnly"
        :message="actions.message.value"
        :action-error="actions.actionError.value"
        :dependents="actions.dependents.value"
      />

      <section
        v-if="canUpdate || canDelete"
        class="flex flex-wrap gap-2"
        :aria-label="t('acad.detail.actions')"
      >
        <Button v-if="canUpdate" type="button" variant="outline" @click="editing = true">
          {{ t('acad.detail.edit') }}
        </Button>
        <Button
          v-if="canDelete"
          type="button"
          variant="outline"
          :disabled="actions.busy.value"
          @click="remove"
        >
          {{ t('acad.detail.delete') }}
        </Button>
      </section>

      <section aria-labelledby="acad-level-data">
        <h2 id="acad-level-data" class="mb-2 text-sm font-semibold">{{ t('acad.detail.data') }}</h2>
        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-muted-foreground">{{ t('acad.fields.code') }}</dt>
            <dd>{{ level.code }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground">{{ t('acad.fields.stage') }}</dt>
            <dd>{{ stageLabel(level.stage) }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground">{{ t('acad.fields.regime') }}</dt>
            <dd>{{ regimeLabel(level.regime) }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground">{{ t('acad.fields.position') }}</dt>
            <dd>{{ level.position }}</dd>
          </div>
        </dl>
      </section>

      <section class="flex flex-col gap-3" aria-labelledby="acad-level-courses">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 id="acad-level-courses" class="text-sm font-semibold">
            {{ t('acad.level.courses') }}
          </h2>
          <Button v-if="canCreate" type="button" size="sm" @click="creatingCourse = true">
            {{ t('acad.level.newCourse') }}
          </Button>
        </div>

        <DataTable
          ref="table"
          table-id="acad.level_courses"
          :caption="t('acad.level.coursesCaption')"
          :columns="columns"
          mode="page"
          :fetcher="fetchCourses"
          :empty-title="t('acad.level.noCourses')"
          :card-heading-level="3"
          hide-single-page-footer
        >
          <template #cell-code="{ row }">
            <RouterLink
              :to="{ name: 'acad-course-detail', params: { publicId: row.public_id } }"
              class="text-primary-on-background focus-visible:ring-ring/50 rounded-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-3"
            >
              {{ row.code }}
            </RouterLink>
          </template>
          <template #cell-name="{ row }">
            <TranslatedText :text="row.name" :translations="row.name_translations" />
          </template>
        </DataTable>
      </section>

      <PeriodsSection :level="level" />

      <EntityFormDialog
        v-model:open="editing"
        kind="level"
        :entity="level"
        :save="saveLevel"
        @saved="onEdited"
      />
      <EntityFormDialog
        v-model:open="creatingCourse"
        kind="course"
        :entity="null"
        :extra="{ education_level: level.public_id }"
        :save="saveCourse"
        @saved="onCourseCreated"
      />
    </template>

    <ConfirmDialog
      :open="actions.confirmation.open.value"
      :request="actions.confirmation.request.value"
      @confirm="actions.confirmation.confirm"
      @cancel="actions.confirmation.cancel"
    />
  </div>
</template>
