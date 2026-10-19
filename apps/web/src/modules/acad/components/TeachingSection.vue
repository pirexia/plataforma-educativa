<script setup lang="ts">
/**
 * `docs/modulos/REQ-ACAD/funcional.md §18.3` punto 1, `§18.4.2`, `§18.8`,
 * `CA-ACAD-107` a `-113`. Sección «Profesorado» de la ficha de grupo
 * (`acad-group-detail`), visible solo con `asignacion_docente.leer`: las
 * asignaciones docentes del grupo (asignatura, subgrupo opcional y profesor) y
 * sus tutores. Una asignación es inmutable (`RN-ACAD-95`): se asigna y se quita,
 * no se edita; cambiar de profesor es quitar y asignar. Los tutores se sustituyen
 * como conjunto completo (`PUT`, `RN-ACAD-97`).
 *
 * Las acciones se muestran con su permiso (`asignacion_docente.crear` /
 * `.eliminar` / `.actualizar`) **y** si el curso admite escritura (`RN-ACAD-42`);
 * el servidor decide igualmente (`INV-002`). Un `409` o un `422` se muestran con
 * el mensaje del servidor junto a la acción. Asignar profesorado no concede
 * permisos ni roles (`RN-ACAD-92`).
 */
import { computed, onMounted, ref } from 'vue'
import { useAcademicYear } from '@/academic-year'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import TranslatedText from '@/components/TranslatedText.vue'
import { DataTable, type DataTableColumn, type DataTableLocalFetcher } from '@/data-table'
import {
  createTeachingAssignment,
  deleteTeachingAssignment,
  listGroupSubjects,
  listGroupTutors,
  listSubgroups,
  listTeachingAssignments,
  replaceGroupTutors,
} from '../api'
import { problemDetail, problemFieldErrors } from '../composables/problem'
import { usePermissions } from '../composables/usePermissions'
import { useStructureActions } from '../composables/useStructureActions'
import type { Group, StaffSummary, Subgroup, Subject, TeachingAssignment } from '../types'
import StaffPicker from './StaffPicker.vue'
import StructureNotices from './StructureNotices.vue'

const props = defineProps<{ group: Group }>()

/** Un grupo tiene decenas de asignaciones como mucho; la API se pide con su máximo. */
const PER_PAGE = 100
const NO_SUBGROUP = '__none__'

interface Row extends TeachingAssignment {
  subjectLabel: string
  subgroupLabel: string
  teacherName: string
}

const t = useT()
const { can } = usePermissions()
const { writable } = useAcademicYear()
const actions = useStructureActions()

const canRead = computed(() => can('asignacion_docente.leer'))
const canAssign = computed(() => can('asignacion_docente.crear') && writable.value)
const canRemove = computed(() => can('asignacion_docente.eliminar') && writable.value)
const canTutors = computed(() => can('asignacion_docente.actualizar') && writable.value)

const subjects = ref<Subject[]>([])
const subgroups = ref<Subgroup[]>([])
const table = ref<{ refresh: () => Promise<void> } | null>(null)
/** La tabla se monta cuando ya se conocen asignaturas y subgrupos: los usa para etiquetar sus filas. */
const ready = ref(false)

onMounted(async () => {
  if (!canRead.value) return

  try {
    const [mine, subs] = await Promise.all([
      listGroupSubjects(props.group.public_id),
      listSubgroups(props.group.public_id),
    ])

    subjects.value = mine.data
    subgroups.value = subs.data
  } catch (err) {
    actions.actionError.value = problemDetail(err) ?? t('acad.errors.unexpected')
  }

  ready.value = true
  await loadTutors()
})

function subjectLabel(publicId: string): string {
  const subject = subjects.value.find((item) => item.public_id === publicId)

  return subject ? `${subject.code} · ${subject.name}` : publicId
}

function subgroupLabel(publicId: string | null): string {
  if (publicId === null) return t('acad.teaching.wholeGroup')

  const subgroup = subgroups.value.find((item) => item.public_id === publicId)

  return subgroup ? subgroup.code : publicId
}

const hasRowActions = computed(() => canRemove.value)

const columns = computed<DataTableColumn<Row>[]>(() => [
  {
    id: 'subjectLabel',
    headerKey: 'acad.teaching.subject',
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  { id: 'subgroupLabel', headerKey: 'acad.teaching.subgroup', card: 'subtitle' },
  { id: 'teacherName', headerKey: 'acad.teaching.teacher', card: 'field' },
  ...(hasRowActions.value
    ? [
        {
          id: 'actions',
          headerKey: 'acad.fields.actions',
          hideable: false,
          card: 'actions',
        } satisfies DataTableColumn<Row>,
      ]
    : []),
])

const fetchAssignments: DataTableLocalFetcher<Row> = async () => {
  const result = await listTeachingAssignments({
    academic_year: props.group.academic_year,
    group: [props.group.public_id],
    per_page: PER_PAGE,
  })

  return {
    data: result.data.map((assignment) => ({
      ...assignment,
      subjectLabel: subjectLabel(assignment.subject),
      subgroupLabel: subgroupLabel(assignment.subgroup),
      teacherName: assignment.teacher?.name ?? t('acad.teaching.unknownTeacher'),
    })),
  }
}

// --- Asignar profesorado -----------------------------------------------------------

const formOpen = ref(false)
const subject = ref('')
const subgroup = ref(NO_SUBGROUP)
const teacher = ref<string[]>([])
const busy = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)

function startAssigning(): void {
  subject.value = ''
  subgroup.value = NO_SUBGROUP
  teacher.value = []
  fieldErrors.value = {}
  formError.value = null
  formOpen.value = true
}

const otherErrors = computed(() =>
  Object.entries(fieldErrors.value)
    .filter(([field]) => !['teacher', 'subject', 'subgroup'].includes(field))
    .flatMap(([, messages]) => messages),
)

async function submitAssignment(): Promise<void> {
  fieldErrors.value = {}
  formError.value = null

  if (subject.value === '' || teacher.value.length === 0) {
    fieldErrors.value = {
      ...(subject.value === '' ? { subject: [t('acad.teaching.errors.subjectRequired')] } : {}),
      ...(teacher.value.length === 0
        ? { teacher: [t('acad.teaching.errors.teacherRequired')] }
        : {}),
    }

    return
  }

  busy.value = true

  try {
    await createTeachingAssignment({
      teacher: teacher.value[0]!,
      group: props.group.public_id,
      subject: subject.value,
      subgroup: subgroup.value === NO_SUBGROUP ? null : subgroup.value,
    })

    formOpen.value = false
    actions.clear()
    actions.message.value = t('acad.flash.assignmentCreated')
    await table.value?.refresh()
  } catch (err) {
    fieldErrors.value = problemFieldErrors(err)
    formError.value =
      problemDetail(err) ??
      (Object.keys(fieldErrors.value).length === 0 ? t('acad.errors.unexpected') : null)
  } finally {
    busy.value = false
  }
}

async function removeAssignment(row: Row): Promise<void> {
  const done = await actions.confirmAndRun(
    {
      title: t('acad.teaching.confirmDelete.title', {
        subject: row.subjectLabel,
        teacher: row.teacherName,
      }),
      description: t('acad.teaching.confirmDelete.description'),
      confirmLabel: t('acad.teaching.confirmDelete.confirm'),
      destructive: true,
    },
    () => deleteTeachingAssignment(row.public_id),
    t('acad.flash.assignmentDeleted'),
  )

  if (done) {
    await table.value?.refresh()
  }
}

// --- Tutores (`RN-ACAD-97`) --------------------------------------------------------

const tutors = ref<StaffSummary[]>([])
const tutorsError = ref<string | null>(null)
const editingTutors = ref(false)
const tutorSelection = ref<string[]>([])
const savingTutors = ref(false)
const tutorsFormError = ref<string | null>(null)
const tutorErrors = ref<string[]>([])

async function loadTutors(): Promise<void> {
  tutorsError.value = null

  try {
    tutors.value = (await listGroupTutors(props.group.public_id)).data
  } catch (err) {
    tutorsError.value = problemDetail(err) ?? t('acad.errors.unexpected')
  }
}

function startEditingTutors(): void {
  tutorSelection.value = tutors.value.map((person) => person.public_id)
  tutorsFormError.value = null
  tutorErrors.value = []
  editingTutors.value = true
}

async function saveTutors(): Promise<void> {
  savingTutors.value = true
  tutorsFormError.value = null
  tutorErrors.value = []

  try {
    tutors.value = (await replaceGroupTutors(props.group.public_id, tutorSelection.value)).data
    editingTutors.value = false
    actions.clear()
    actions.message.value = t('acad.flash.tutorsUpdated')
  } catch (err) {
    tutorErrors.value = Object.values(problemFieldErrors(err)).flat()
    tutorsFormError.value =
      problemDetail(err) ?? (tutorErrors.value.length === 0 ? t('acad.errors.unexpected') : null)
  } finally {
    savingTutors.value = false
  }
}
</script>

<template>
  <section
    v-if="canRead"
    class="flex flex-col gap-4"
    aria-labelledby="acad-group-teaching"
    data-slot="acad-teaching"
  >
    <h2 id="acad-group-teaching" class="text-sm font-semibold">{{ t('acad.teaching.title') }}</h2>

    <StructureNotices
      :read-only="false"
      :message="actions.message.value"
      :action-error="actions.actionError.value"
      :dependents="actions.dependents.value"
    />

    <div class="flex flex-col gap-2" data-slot="acad-tutors">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-medium">{{ t('acad.teaching.tutors') }}</h3>
        <Button
          v-if="canTutors && !editingTutors && tutorsError === null"
          type="button"
          size="sm"
          variant="outline"
          @click="startEditingTutors"
        >
          {{ t('acad.teaching.editTutors') }}
        </Button>
      </div>

      <p v-if="tutorsError" role="alert" class="text-destructive text-sm">{{ tutorsError }}</p>

      <form v-else-if="editingTutors" class="flex flex-col gap-3" @submit.prevent="saveTutors">
        <StaffPicker
          v-model="tutorSelection"
          :initial="tutors"
          multiple
          id-base="acad-tutors"
          :legend="t('acad.teaching.chooseTutors')"
          :invalid="tutorErrors.length > 0"
          describedby="acad-tutors-errors"
        />
        <ul
          v-if="tutorErrors.length > 0"
          id="acad-tutors-errors"
          role="alert"
          class="text-destructive text-xs"
        >
          <li v-for="message in tutorErrors" :key="message">{{ message }}</li>
        </ul>
        <p v-if="tutorsFormError" role="alert" class="text-destructive text-sm">
          {{ tutorsFormError }}
        </p>
        <div class="flex gap-2">
          <Button type="submit" :disabled="savingTutors">{{ t('acad.form.save') }}</Button>
          <Button
            type="button"
            variant="outline"
            :disabled="savingTutors"
            @click="editingTutors = false"
          >
            {{ t('acad.form.cancel') }}
          </Button>
        </div>
      </form>

      <template v-else>
        <p v-if="tutors.length === 0" class="text-muted-foreground text-sm">
          {{ t('acad.teaching.noTutors') }}
        </p>
        <ul v-else class="list-disc pl-5 text-sm" :aria-label="t('acad.teaching.tutors')">
          <li v-for="person in tutors" :key="person.public_id">{{ person.name }}</li>
        </ul>
      </template>
    </div>

    <div class="flex flex-col gap-2" data-slot="acad-assignments">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-medium">{{ t('acad.teaching.assignments') }}</h3>
        <Button v-if="canAssign" type="button" size="sm" @click="startAssigning">
          {{ t('acad.teaching.assign') }}
        </Button>
      </div>

      <DataTable
        v-if="ready"
        ref="table"
        table-id="acad.group_teaching"
        :caption="t('acad.teaching.caption')"
        :columns="columns"
        mode="local"
        :fetcher="fetchAssignments"
        :empty-title="t('acad.teaching.empty')"
        :card-heading-level="4"
        hide-single-page-footer
      >
        <template #cell-actions="{ row }">
          <Button
            type="button"
            size="sm"
            variant="outline"
            :disabled="actions.busy.value"
            @click="removeAssignment(row)"
          >
            {{ t('acad.teaching.remove') }}
            <span class="sr-only">{{ row.subjectLabel }} · {{ row.teacherName }}</span>
          </Button>
        </template>
      </DataTable>
    </div>

    <Dialog v-model:open="formOpen">
      <DialogContent :close-label="t('acad.form.close')" data-slot="acad-assignment-form">
        <DialogHeader>
          <DialogTitle>{{ t('acad.teaching.form.title') }}</DialogTitle>
          <DialogDescription>{{ t('acad.teaching.form.description') }}</DialogDescription>
        </DialogHeader>

        <form class="flex flex-col gap-4" @submit.prevent="submitAssignment">
          <div class="flex flex-col gap-1.5">
            <Label for="acad-assignment-subject">{{ t('acad.teaching.subject') }}</Label>
            <Select v-model="subject" required>
              <SelectTrigger
                id="acad-assignment-subject"
                :aria-invalid="fieldErrors.subject ? 'true' : undefined"
                :aria-describedby="
                  fieldErrors.subject ? 'acad-assignment-subject-errors' : undefined
                "
              >
                <SelectValue :placeholder="t('acad.form.choose')" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem v-for="item in subjects" :key="item.public_id" :value="item.public_id">
                  {{ item.code }} ·
                  <TranslatedText :text="item.name" :translations="item.name_translations" />
                </SelectItem>
              </SelectContent>
            </Select>
            <ul
              v-if="fieldErrors.subject"
              id="acad-assignment-subject-errors"
              role="alert"
              class="text-destructive text-xs"
            >
              <li v-for="message in fieldErrors.subject" :key="message">{{ message }}</li>
            </ul>
          </div>

          <div class="flex flex-col gap-1.5">
            <Label for="acad-assignment-subgroup">{{ t('acad.teaching.subgroup') }}</Label>
            <Select v-model="subgroup">
              <SelectTrigger
                id="acad-assignment-subgroup"
                :aria-invalid="fieldErrors.subgroup ? 'true' : undefined"
                :aria-describedby="
                  fieldErrors.subgroup ? 'acad-assignment-subgroup-errors' : undefined
                "
              >
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem :value="NO_SUBGROUP">{{ t('acad.teaching.wholeGroup') }}</SelectItem>
                <SelectItem v-for="item in subgroups" :key="item.public_id" :value="item.public_id">
                  {{ item.code }} · {{ item.name }}
                </SelectItem>
              </SelectContent>
            </Select>
            <ul
              v-if="fieldErrors.subgroup"
              id="acad-assignment-subgroup-errors"
              role="alert"
              class="text-destructive text-xs"
            >
              <li v-for="message in fieldErrors.subgroup" :key="message">{{ message }}</li>
            </ul>
          </div>

          <div class="flex flex-col gap-1.5">
            <StaffPicker
              v-model="teacher"
              id-base="acad-assignment-teacher"
              :legend="t('acad.teaching.teacher')"
              :invalid="Boolean(fieldErrors.teacher)"
              describedby="acad-assignment-teacher-errors"
            />
            <ul
              v-if="fieldErrors.teacher"
              id="acad-assignment-teacher-errors"
              role="alert"
              class="text-destructive text-xs"
            >
              <li v-for="message in fieldErrors.teacher" :key="message">{{ message }}</li>
            </ul>
          </div>

          <ul v-if="otherErrors.length > 0" role="alert" class="text-destructive text-sm">
            <li v-for="message in otherErrors" :key="message">{{ message }}</li>
          </ul>
          <p v-if="formError" role="alert" class="text-destructive text-sm">{{ formError }}</p>

          <DialogFooter>
            <Button type="button" variant="outline" @click="formOpen = false">
              {{ t('acad.form.cancel') }}
            </Button>
            <Button type="submit" :disabled="busy">{{ t('acad.teaching.form.submit') }}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>

    <ConfirmDialog
      :open="actions.confirmation.open.value"
      :request="actions.confirmation.request.value"
      @confirm="actions.confirmation.confirm"
      @cancel="actions.confirmation.cancel"
    />
  </section>
</template>
