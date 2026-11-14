<script setup lang="ts">
/**
 * `/academico/asignaturas/:publicId` (`docs/modulos/REQ-ACAD/funcional.md §10.1`).
 * Ficha de una asignatura y los grupos que la cursan.
 *
 * **Grupos que la cursan**: la API no tiene un listado «grupos de una
 * asignatura» (`api.md §2`); se obtienen pidiendo los grupos de su curso de etapa
 * (`GET /groups?course=`, máximo 100) y, de cada uno, `GET /groups/{id}/subjects`.
 * Son decenas de grupos como mucho por curso de etapa (`api.md §1.6`). Ambigüedad
 * de la especificación anotada en el informe del paso.
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { usePinAcademicYear, useAcademicYear } from '@/academic-year'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import TranslatedText from '@/components/TranslatedText.vue'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import EntityFormDialog from '../components/EntityFormDialog.vue'
import StructureNotices from '../components/StructureNotices.vue'
import {
  deleteSubject,
  getCourse,
  getSubject,
  listGroups,
  listGroupSubjects,
  updateSubject,
} from '../api'
import { problemDetail } from '../composables/problem'
import { useLoad } from '../composables/useLoad'
import { usePermissions } from '../composables/usePermissions'
import { useStructureActions } from '../composables/useStructureActions'
import type { Course, Group, Subject } from '../types'

const t = useT()
const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const actions = useStructureActions()

const publicId = computed(() => String(route.params.publicId))
const { data: subject, loading, error, load } = useLoad<Subject>(() => getSubject(publicId.value))

usePinAcademicYear(computed(() => subject.value?.academic_year ?? null))
const { readOnly, writable } = useAcademicYear()

const canUpdate = computed(() => can('estructura_academica.actualizar') && writable.value)
const canDelete = computed(() => can('estructura_academica.eliminar') && writable.value)

const course = ref<Course | null>(null)
const editing = ref(false)

const groups = ref<Group[]>([])
const groupsLoaded = ref(false)
const groupsError = ref<string | null>(null)

async function loadGroups(current: Subject): Promise<void> {
  groupsError.value = null

  try {
    const all = await listGroups({
      academic_year: current.academic_year,
      course: [current.course],
      per_page: 100,
    })
    const subjectLists = await Promise.all(
      all.data.map((group) => listGroupSubjects(group.public_id)),
    )

    groups.value = all.data.filter((_, index) =>
      subjectLists[index]!.data.some((item) => item.public_id === current.public_id),
    )
    groupsLoaded.value = true
  } catch (err) {
    groupsError.value = problemDetail(err) ?? t('acad.errors.unexpected')
  }
}

onMounted(async () => {
  await load()

  if (subject.value) {
    try {
      course.value = await getCourse(subject.value.course)
    } catch {
      course.value = null
    }

    await loadGroups(subject.value)
  }
})

function saveSubject(body: Record<string, unknown>): Promise<unknown> {
  return updateSubject(publicId.value, body)
}

async function onEdited(): Promise<void> {
  actions.clear()
  actions.message.value = t('acad.flash.subjectUpdated')
  await load()
}

async function remove(): Promise<void> {
  const current = subject.value

  if (!current) return

  const done = await actions.confirmAndRun(
    {
      title: t('acad.confirm.delete.subject.title', { code: current.code }),
      description: t('acad.confirm.delete.subject.description'),
      confirmLabel: t('acad.confirm.delete.confirm', { code: current.code }),
      destructive: true,
    },
    () => deleteSubject(current.public_id),
    t('acad.flash.subjectDeleted'),
  )

  if (done) {
    void router.push({ name: 'acad-course-detail', params: { publicId: current.course } })
  }
}
</script>

<template>
  <div class="mx-auto flex max-w-5xl flex-col gap-6 px-4 py-6">
    <LoadingState v-if="loading" />
    <ErrorState v-else-if="error" :state="error" @retry="load" />

    <template v-else-if="subject">
      <header class="flex flex-col gap-1">
        <p class="text-sm">
          <RouterLink
            :to="{ name: 'acad-course-detail', params: { publicId: subject.course } }"
            class="text-primary-on-background underline underline-offset-4"
          >
            <template v-if="course">{{
              t('acad.nav.backToCourse', { code: course.code })
            }}</template>
            <template v-else>{{ t('acad.nav.backToCourseGeneric') }}</template>
          </RouterLink>
        </p>
        <h1 class="text-lg font-semibold">
          {{ subject.code }} ·
          <TranslatedText :text="subject.name" :translations="subject.name_translations" />
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

      <section aria-labelledby="acad-subject-data">
        <h2 id="acad-subject-data" class="mb-2 text-sm font-semibold">
          {{ t('acad.detail.data') }}
        </h2>
        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-muted-foreground">{{ t('acad.fields.code') }}</dt>
            <dd>{{ subject.code }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground">{{ t('acad.fields.position') }}</dt>
            <dd>{{ subject.position }}</dd>
          </div>
        </dl>
      </section>

      <section class="flex flex-col gap-2" aria-labelledby="acad-subject-groups">
        <h2 id="acad-subject-groups" class="text-sm font-semibold">
          {{ t('acad.subject.groups') }}
        </h2>
        <p v-if="groupsError" role="alert" class="text-destructive text-sm">{{ groupsError }}</p>
        <p v-else-if="groupsLoaded && groups.length === 0" class="text-muted-foreground text-sm">
          {{ t('acad.subject.noGroups') }}
        </p>
        <ul
          v-else-if="groupsLoaded"
          class="list-disc pl-5 text-sm"
          :aria-label="t('acad.subject.groups')"
        >
          <li v-for="group in groups" :key="group.public_id">
            <RouterLink
              :to="{ name: 'acad-group-detail', params: { publicId: group.public_id } }"
              class="text-primary-on-background underline underline-offset-4"
            >
              {{ group.code }}
            </RouterLink>
            · {{ group.name }}
          </li>
        </ul>
      </section>

      <EntityFormDialog
        v-model:open="editing"
        kind="subject"
        :entity="subject"
        :save="saveSubject"
        @saved="onEdited"
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
