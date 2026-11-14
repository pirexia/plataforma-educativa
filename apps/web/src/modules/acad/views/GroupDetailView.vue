<script setup lang="ts">
/**
 * `/academico/grupos/:publicId` (`docs/modulos/REQ-ACAD/funcional.md §10.1`,
 * `§4.6`, `RN-ACAD-30..32`). Ficha de un grupo: sus subgrupos y las asignaturas
 * que cursa (subconjunto de las de su curso de etapa, asignación **explícita**,
 * `RN-ACAD-31`). «Editar asignaturas» sustituye el conjunto completo con
 * `PUT /groups/{id}/subjects` (permiso `actualizar`, no `crear`+`eliminar`) y
 * solo aparece con su permiso **y** si el curso académico admite escritura
 * (`CA-ACAD-046`). Selector fijo en el curso de la entidad (**[DERIVADA]**).
 *
 * Las asignaturas disponibles se piden con `per_page=100` (máximo de la API): un
 * curso de etapa tiene decenas (`api.md §1.6`).
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { usePinAcademicYear, useAcademicYear } from '@/academic-year'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import TranslatedText from '@/components/TranslatedText.vue'
import { DataTable, type DataTableColumn, type DataTableLocalFetcher } from '@/data-table'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import EntityFormDialog from '../components/EntityFormDialog.vue'
import StructureNotices from '../components/StructureNotices.vue'
import TeachingSection from '../components/TeachingSection.vue'
import {
  createSubgroup,
  deleteGroup,
  deleteSubgroup,
  getCourse,
  getGroup,
  listGroupSubjects,
  listSubgroups,
  listSubjects,
  replaceGroupSubjects,
  updateGroup,
  updateSubgroup,
} from '../api'
import { problemDetail } from '../composables/problem'
import { useLoad } from '../composables/useLoad'
import { usePermissions } from '../composables/usePermissions'
import { useStructureActions } from '../composables/useStructureActions'
import type { Course, Group, Subgroup, Subject } from '../types'

const t = useT()
const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const actions = useStructureActions()

const publicId = computed(() => String(route.params.publicId))
const { data: group, loading, error, load } = useLoad<Group>(() => getGroup(publicId.value))

usePinAcademicYear(computed(() => group.value?.academic_year ?? null))
const { readOnly, writable } = useAcademicYear()

const canUpdate = computed(() => can('estructura_academica.actualizar') && writable.value)
const canDelete = computed(() => can('estructura_academica.eliminar') && writable.value)
const canCreate = computed(() => can('estructura_academica.crear') && writable.value)

const course = ref<Course | null>(null)

const editing = ref(false)
const creatingSubgroup = ref(false)
const editingSubgroup = ref<Subgroup | null>(null)
const subgroupsTable = ref<{ refresh: () => Promise<void> } | null>(null)

// --- Asignaturas del grupo (`RN-ACAD-30..32`) ------------------------------------

const assigned = ref<Subject[]>([])
const available = ref<Subject[]>([])
const subjectsError = ref<string | null>(null)
const editingSubjects = ref(false)
const selection = ref<string[]>([])
const savingSubjects = ref(false)
const subjectsFormError = ref<string | null>(null)

async function loadSubjects(): Promise<void> {
  subjectsError.value = null

  try {
    const current = group.value

    if (!current) return

    const [mine, all] = await Promise.all([
      listGroupSubjects(current.public_id),
      listSubjects({
        academic_year: current.academic_year,
        course: [current.course],
        per_page: 100,
      }),
    ])

    assigned.value = mine.data
    available.value = all.data
  } catch (err) {
    subjectsError.value = problemDetail(err) ?? t('acad.errors.unexpected')
  }
}

function startEditingSubjects(): void {
  selection.value = assigned.value.map((subject) => subject.public_id)
  subjectsFormError.value = null
  editingSubjects.value = true
}

async function saveSubjects(): Promise<void> {
  savingSubjects.value = true
  subjectsFormError.value = null

  try {
    const result = await replaceGroupSubjects(publicId.value, selection.value)

    assigned.value = result.data
    editingSubjects.value = false
    actions.clear()
    actions.message.value = t('acad.flash.subjectsAssigned')
  } catch (err) {
    subjectsFormError.value = problemDetail(err) ?? t('acad.errors.unexpected')
  } finally {
    savingSubjects.value = false
  }
}

// --- Subgrupos -------------------------------------------------------------------------

const subgroupColumns: DataTableColumn<Subgroup>[] = [
  { id: 'code', headerKey: 'acad.fields.code', rowHeader: true, hideable: false, card: 'title' },
  { id: 'name', headerKey: 'acad.fields.name', card: 'subtitle' },
  { id: 'position', headerKey: 'acad.fields.position', align: 'end', card: 'field' },
  { id: 'actions', headerKey: 'acad.fields.actions', hideable: false, card: 'actions' },
]

const fetchSubgroups: DataTableLocalFetcher<Subgroup> = () => listSubgroups(publicId.value)

onMounted(async () => {
  await load()

  if (group.value) {
    try {
      course.value = await getCourse(group.value.course)
    } catch {
      course.value = null
    }

    await loadSubjects()
  }
})

function saveGroup(body: Record<string, unknown>): Promise<unknown> {
  return updateGroup(publicId.value, body)
}

function saveNewSubgroup(body: Record<string, unknown>): Promise<unknown> {
  return createSubgroup(publicId.value, body as unknown as Parameters<typeof createSubgroup>[1])
}

function saveEditedSubgroup(body: Record<string, unknown>): Promise<unknown> {
  return updateSubgroup(editingSubgroup.value!.public_id, body)
}

const editingSubgroupOpen = computed({
  get: () => editingSubgroup.value !== null,
  set: (open: boolean) => {
    if (!open) editingSubgroup.value = null
  },
})

async function onEdited(): Promise<void> {
  actions.clear()
  actions.message.value = t('acad.flash.groupUpdated')
  await load()
}

async function onSubgroupSaved(created: boolean): Promise<void> {
  actions.clear()
  actions.message.value = created
    ? t('acad.flash.subgroupCreated')
    : t('acad.flash.subgroupUpdated')
  await subgroupsTable.value?.refresh()
}

async function removeSubgroup(subgroup: Subgroup): Promise<void> {
  const done = await actions.confirmAndRun(
    {
      title: t('acad.confirm.delete.subgroup.title', { code: subgroup.code }),
      description: t('acad.confirm.delete.subgroup.description'),
      confirmLabel: t('acad.confirm.delete.confirm', { code: subgroup.code }),
      destructive: true,
    },
    () => deleteSubgroup(subgroup.public_id),
    t('acad.flash.subgroupDeleted'),
  )

  if (done) {
    await subgroupsTable.value?.refresh()
  }
}

async function remove(): Promise<void> {
  const current = group.value

  if (!current) return

  const done = await actions.confirmAndRun(
    {
      title: t('acad.confirm.delete.group.title', { code: current.code }),
      description: t('acad.confirm.delete.group.description'),
      confirmLabel: t('acad.confirm.delete.confirm', { code: current.code }),
      destructive: true,
    },
    () => deleteGroup(current.public_id),
    t('acad.flash.groupDeleted'),
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

    <template v-else-if="group">
      <header class="flex flex-col gap-1">
        <p class="text-sm">
          <RouterLink
            :to="{ name: 'acad-course-detail', params: { publicId: group.course } }"
            class="text-primary-on-background underline underline-offset-4"
          >
            <template v-if="course">{{
              t('acad.nav.backToCourse', { code: course.code })
            }}</template>
            <template v-else>{{ t('acad.nav.backToCourseGeneric') }}</template>
          </RouterLink>
        </p>
        <h1 class="text-lg font-semibold">{{ group.code }} · {{ group.name }}</h1>
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

      <section aria-labelledby="acad-group-data">
        <h2 id="acad-group-data" class="mb-2 text-sm font-semibold">{{ t('acad.detail.data') }}</h2>
        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-muted-foreground">{{ t('acad.fields.code') }}</dt>
            <dd>{{ group.code }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground">{{ t('acad.fields.name') }}</dt>
            <dd>{{ group.name }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground">{{ t('acad.fields.position') }}</dt>
            <dd>{{ group.position }}</dd>
          </div>
        </dl>
      </section>

      <section class="flex flex-col gap-3" aria-labelledby="acad-group-subjects">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 id="acad-group-subjects" class="text-sm font-semibold">
            {{ t('acad.group.subjects') }}
          </h2>
          <Button
            v-if="canUpdate && !editingSubjects && subjectsError === null"
            type="button"
            size="sm"
            variant="outline"
            @click="startEditingSubjects"
          >
            {{ t('acad.group.editSubjects') }}
          </Button>
        </div>

        <p v-if="subjectsError" role="alert" class="text-destructive text-sm">
          {{ subjectsError }}
        </p>

        <form
          v-else-if="editingSubjects"
          class="flex flex-col gap-3"
          @submit.prevent="saveSubjects"
        >
          <fieldset class="flex flex-col gap-2">
            <legend class="text-sm">{{ t('acad.group.chooseSubjects') }}</legend>
            <p v-if="available.length === 0" class="text-muted-foreground text-sm">
              {{ t('acad.group.noAvailable') }}
            </p>
            <label
              v-for="subject in available"
              :key="subject.public_id"
              class="flex min-h-11 items-center gap-2 text-sm"
            >
              <input
                v-model="selection"
                type="checkbox"
                :value="subject.public_id"
                class="size-4"
              />
              <span
                >{{ subject.code }} ·
                <TranslatedText :text="subject.name" :translations="subject.name_translations"
              /></span>
            </label>
          </fieldset>
          <p v-if="subjectsFormError" role="alert" class="text-destructive text-sm">
            {{ subjectsFormError }}
          </p>
          <div class="flex gap-2">
            <Button type="submit" :disabled="savingSubjects">{{ t('acad.form.save') }}</Button>
            <Button
              type="button"
              variant="outline"
              :disabled="savingSubjects"
              @click="editingSubjects = false"
            >
              {{ t('acad.form.cancel') }}
            </Button>
          </div>
        </form>

        <template v-else>
          <p v-if="assigned.length === 0" class="text-muted-foreground text-sm">
            {{ t('acad.group.noSubjects') }}
          </p>
          <ul v-else class="list-disc pl-5 text-sm" :aria-label="t('acad.group.subjects')">
            <li v-for="subject in assigned" :key="subject.public_id">
              <RouterLink
                :to="{ name: 'acad-subject-detail', params: { publicId: subject.public_id } }"
                class="text-primary-on-background underline underline-offset-4"
              >
                {{ subject.code }}
              </RouterLink>
              ·
              <TranslatedText :text="subject.name" :translations="subject.name_translations" />
            </li>
          </ul>
        </template>
      </section>

      <TeachingSection :group="group" />

      <section class="flex flex-col gap-3" aria-labelledby="acad-group-subgroups">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 id="acad-group-subgroups" class="text-sm font-semibold">
            {{ t('acad.group.subgroups') }}
          </h2>
          <Button v-if="canCreate" type="button" size="sm" @click="creatingSubgroup = true">
            {{ t('acad.group.newSubgroup') }}
          </Button>
        </div>

        <DataTable
          ref="subgroupsTable"
          table-id="acad.group_subgroups"
          :caption="t('acad.group.subgroupsCaption')"
          :columns="subgroupColumns"
          mode="local"
          :fetcher="fetchSubgroups"
          :empty-title="t('acad.group.noSubgroups')"
          :card-heading-level="3"
        >
          <template #cell-actions="{ row }">
            <div class="flex flex-wrap gap-2">
              <Button
                v-if="canUpdate"
                type="button"
                size="sm"
                variant="outline"
                @click="editingSubgroup = row"
              >
                {{ t('acad.detail.edit') }}
                <span class="sr-only">{{ row.code }}</span>
              </Button>
              <Button
                v-if="canDelete"
                type="button"
                size="sm"
                variant="outline"
                :disabled="actions.busy.value"
                @click="removeSubgroup(row)"
              >
                {{ t('acad.detail.delete') }}
                <span class="sr-only">{{ row.code }}</span>
              </Button>
            </div>
          </template>
        </DataTable>
      </section>

      <EntityFormDialog
        v-model:open="editing"
        kind="group"
        :entity="group"
        :save="saveGroup"
        @saved="onEdited"
      />
      <EntityFormDialog
        v-model:open="creatingSubgroup"
        kind="subgroup"
        :entity="null"
        :save="saveNewSubgroup"
        @saved="onSubgroupSaved(true)"
      />
      <EntityFormDialog
        v-model:open="editingSubgroupOpen"
        kind="subgroup"
        :entity="editingSubgroup"
        :save="saveEditedSubgroup"
        @saved="onSubgroupSaved(false)"
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
