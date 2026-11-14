<script setup lang="ts">
/**
 * `/academico/cursos/:publicId` (`docs/modulos/REQ-ACAD/funcional.md §10.1`,
 * `§4.3`-`§4.5`). Ficha de un curso de etapa («Curso» en la interfaz,
 * `§0.1`): sus grupos y sus asignaturas. Altas, edición y borrado solo con su
 * permiso **y** si el curso académico de la entidad admite escritura
 * (`CA-ACAD-046`). Selector fijo en el curso de la entidad (**[DERIVADA]**).
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
import StructureNotices from '../components/StructureNotices.vue'
import {
  createGroup,
  createSubject,
  deleteCourse,
  getCourse,
  getEducationLevel,
  listGroups,
  listSubjects,
  updateCourse,
} from '../api'
import { useLoad } from '../composables/useLoad'
import { usePermissions } from '../composables/usePermissions'
import { useStructureActions } from '../composables/useStructureActions'
import type { Course, EducationLevel, Group, Subject } from '../types'

const t = useT()
const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const actions = useStructureActions()

const publicId = computed(() => String(route.params.publicId))
const { data: course, loading, error, load } = useLoad<Course>(() => getCourse(publicId.value))

usePinAcademicYear(computed(() => course.value?.academic_year ?? null))
const { readOnly, writable } = useAcademicYear()

const canUpdate = computed(() => can('estructura_academica.actualizar') && writable.value)
const canDelete = computed(() => can('estructura_academica.eliminar') && writable.value)
const canCreate = computed(() => can('estructura_academica.crear') && writable.value)

/** Etapa del curso, solo para el enlace de retorno: si falla, la ficha no se rompe. */
const level = ref<EducationLevel | null>(null)

const editing = ref(false)
const creatingGroup = ref(false)
const creatingSubject = ref(false)
const groupsTable = ref<{ refresh: () => Promise<void> } | null>(null)
const subjectsTable = ref<{ refresh: () => Promise<void> } | null>(null)

const groupColumns: DataTableColumn<Group>[] = [
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

const subjectColumns: DataTableColumn<Subject>[] = [
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

const fetchGroups: DataTableFetcher<Group> = (query) =>
  listGroups({
    academic_year: course.value?.academic_year,
    course: [publicId.value],
    sort: query.sort,
    page: query.page,
    per_page: query.per_page,
  })

const fetchSubjects: DataTableFetcher<Subject> = (query) =>
  listSubjects({
    academic_year: course.value?.academic_year,
    course: [publicId.value],
    sort: query.sort,
    page: query.page,
    per_page: query.per_page,
  })

onMounted(async () => {
  await load()

  if (course.value) {
    try {
      level.value = await getEducationLevel(course.value.education_level)
    } catch {
      level.value = null
    }
  }
})

function saveCourse(body: Record<string, unknown>): Promise<unknown> {
  return updateCourse(publicId.value, body)
}

function saveGroup(body: Record<string, unknown>): Promise<unknown> {
  return createGroup(body as unknown as Parameters<typeof createGroup>[0])
}

function saveSubject(body: Record<string, unknown>): Promise<unknown> {
  return createSubject(body as unknown as Parameters<typeof createSubject>[0])
}

async function onEdited(): Promise<void> {
  actions.clear()
  actions.message.value = t('acad.flash.courseUpdated')
  await load()
}

async function onGroupCreated(): Promise<void> {
  actions.clear()
  actions.message.value = t('acad.flash.groupCreated')
  await groupsTable.value?.refresh()
}

async function onSubjectCreated(): Promise<void> {
  actions.clear()
  actions.message.value = t('acad.flash.subjectCreated')
  await subjectsTable.value?.refresh()
}

async function remove(): Promise<void> {
  const current = course.value

  if (!current) return

  const done = await actions.confirmAndRun(
    {
      title: t('acad.confirm.delete.course.title', { code: current.code }),
      description: t('acad.confirm.delete.course.description'),
      confirmLabel: t('acad.confirm.delete.confirm', { code: current.code }),
      destructive: true,
    },
    () => deleteCourse(current.public_id),
    t('acad.flash.courseDeleted'),
  )

  if (done) {
    void router.push({
      name: 'acad-level-detail',
      params: { publicId: current.education_level },
    })
  }
}
</script>

<template>
  <div class="mx-auto flex max-w-5xl flex-col gap-6 px-4 py-6">
    <LoadingState v-if="loading" />
    <ErrorState v-else-if="error" :state="error" @retry="load" />

    <template v-else-if="course">
      <header class="flex flex-col gap-1">
        <p class="text-sm">
          <RouterLink
            :to="{ name: 'acad-level-detail', params: { publicId: course.education_level } }"
            class="text-primary-on-background underline underline-offset-4"
          >
            <template v-if="level">{{ t('acad.nav.backToLevel', { code: level.code }) }}</template>
            <template v-else>{{ t('acad.nav.backToLevelGeneric') }}</template>
          </RouterLink>
        </p>
        <h1 class="text-lg font-semibold">
          {{ course.code }} ·
          <TranslatedText :text="course.name" :translations="course.name_translations" />
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

      <section aria-labelledby="acad-course-data">
        <h2 id="acad-course-data" class="mb-2 text-sm font-semibold">
          {{ t('acad.detail.data') }}
        </h2>
        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-muted-foreground">{{ t('acad.fields.code') }}</dt>
            <dd>{{ course.code }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground">{{ t('acad.fields.position') }}</dt>
            <dd>{{ course.position }}</dd>
          </div>
        </dl>
      </section>

      <section class="flex flex-col gap-3" aria-labelledby="acad-course-groups">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 id="acad-course-groups" class="text-sm font-semibold">
            {{ t('acad.course.groups') }}
          </h2>
          <Button v-if="canCreate" type="button" size="sm" @click="creatingGroup = true">
            {{ t('acad.course.newGroup') }}
          </Button>
        </div>
        <DataTable
          ref="groupsTable"
          table-id="acad.course_groups"
          :caption="t('acad.course.groupsCaption')"
          :columns="groupColumns"
          mode="page"
          :fetcher="fetchGroups"
          :empty-title="t('acad.course.noGroups')"
          :card-heading-level="3"
          hide-single-page-footer
        >
          <template #cell-code="{ row }">
            <RouterLink
              :to="{ name: 'acad-group-detail', params: { publicId: row.public_id } }"
              class="text-primary-on-background focus-visible:ring-ring/50 rounded-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-3"
            >
              {{ row.code }}
            </RouterLink>
          </template>
        </DataTable>
      </section>

      <section class="flex flex-col gap-3" aria-labelledby="acad-course-subjects">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 id="acad-course-subjects" class="text-sm font-semibold">
            {{ t('acad.course.subjects') }}
          </h2>
          <Button v-if="canCreate" type="button" size="sm" @click="creatingSubject = true">
            {{ t('acad.course.newSubject') }}
          </Button>
        </div>
        <DataTable
          ref="subjectsTable"
          table-id="acad.course_subjects"
          :caption="t('acad.course.subjectsCaption')"
          :columns="subjectColumns"
          mode="page"
          :fetcher="fetchSubjects"
          :empty-title="t('acad.course.noSubjects')"
          :card-heading-level="3"
          hide-single-page-footer
        >
          <template #cell-code="{ row }">
            <RouterLink
              :to="{ name: 'acad-subject-detail', params: { publicId: row.public_id } }"
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

      <EntityFormDialog
        v-model:open="editing"
        kind="course"
        :entity="course"
        :save="saveCourse"
        @saved="onEdited"
      />
      <EntityFormDialog
        v-model:open="creatingGroup"
        kind="group"
        :entity="null"
        :extra="{ course: course.public_id }"
        :save="saveGroup"
        @saved="onGroupCreated"
      />
      <EntityFormDialog
        v-model:open="creatingSubject"
        kind="subject"
        :entity="null"
        :extra="{ course: course.public_id }"
        :save="saveSubject"
        @saved="onSubjectCreated"
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
