<script setup lang="ts">
/**
 * `/academico/horarios` (`docs/modulos/REQ-ACAD/funcional.md §18.3` puntos 2-3,
 * `§18.8`, `CA-ACAD-122` a `-127`, `CA-ACAD-134`). Rejilla semanal de sesiones. Se
 * elige la vista (grupo, profesor, aula o asignatura) y la entidad; ambas viven en
 * la URL (`?view=group&id=…`, `RN-ACAD-40` para el curso), así que recargar o
 * compartir el enlace conserva la selección. Una vista pide siempre
 * `GET /timetable-sessions` con `academic_year` y el filtro de la vista
 * (`RN-ACAD-127`).
 *
 * - **Vista de grupo**: las franjas son las del marco ordinario de la etapa del
 *   grupo y se pueden **crear** sesiones activando una celda vacía (con
 *   `horarios.crear`).
 * - **Vistas de profesor, aula y asignatura**: la rejilla se forma con las franjas
 *   de las sesiones que muestran; permiten editar y quitar (con `horarios.actualizar`
 *   / `eliminar`), no crear (una sesión se crea para una asignación de un grupo).
 *
 * Solo lectura para quien solo tiene `horarios.leer`; con un curso de solo lectura,
 * aviso persistente de texto (`RN-ACAD-42`). El servidor decide igualmente (`INV-002`).
 */
import { computed, ref, watch } from 'vue'
import { CalendarX } from '@lucide/vue'
import { useRoute, useRouter } from 'vue-router'
import { useAcademicYear } from '@/academic-year'
import { useT } from '@/i18n'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import EmptyState from '@/layouts/components/EmptyState.vue'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import { resolveErrorState, type ShellErrorState } from '@/layouts/errorState'
import {
  createTimetableSession,
  deleteTimetableSession,
  getCourse,
  getGroup,
  listFacilities,
  listGroups,
  listGroupSubjects,
  listSubgroups,
  listSubjects,
  listTeachingAssignments,
  listTimetableFrames,
  listTimetableSessions,
  updateTimetableSession,
} from '../api'
import SessionFormDialog, { type AssignmentOption } from '../components/SessionFormDialog.vue'
import StaffPicker from '../components/StaffPicker.vue'
import StructureNotices from '../components/StructureNotices.vue'
import TimetableGrid from '../components/TimetableGrid.vue'
import { problemDetail } from '../composables/problem'
import { usePermissions } from '../composables/usePermissions'
import { useStructureActions } from '../composables/useStructureActions'
import type { Facility, Group, Subject, TimeSlot, TimetableSession } from '../types'

const VIEWS = ['group', 'teacher', 'facility', 'subject'] as const
type View = (typeof VIEWS)[number]

/** Máximo de la API para un selector de entidad (`api.md §3`). */
const OPTIONS_PER_PAGE = 100

const t = useT()
const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const { selectedId, noActive, readOnly, writable } = useAcademicYear()
const actions = useStructureActions()

const view = computed<View>(() => {
  const raw = route.query.view

  return VIEWS.includes(raw as View) ? (raw as View) : 'group'
})
const entityId = computed<string | null>(() => {
  const raw = route.query.id

  return typeof raw === 'string' && raw !== '' ? raw : null
})

const canCreate = computed(() => can('horarios.crear') && writable.value && view.value === 'group')
const canEdit = computed(() => can('horarios.actualizar') && writable.value)
const canRemove = computed(() => can('horarios.eliminar') && writable.value)

function setQuery(next: { view?: View; id?: string | null }): void {
  const query = { ...route.query }
  const nextView = next.view ?? view.value

  query.view = nextView

  const id = next.id === undefined ? entityId.value : next.id

  if (id === null) delete query.id
  else query.id = id

  void router.replace({ path: route.path, query })
}

function chooseView(value: unknown): void {
  setQuery({ view: String(value) as View, id: null })
}

function chooseEntity(value: unknown): void {
  setQuery({ id: String(value) })
}

// --- Opciones de la entidad ---------------------------------------------------------------

const groups = ref<Group[]>([])
const subjects = ref<Subject[]>([])
const facilities = ref<Facility[]>([])
const teacherSelection = computed({
  get: () => (view.value === 'teacher' && entityId.value ? [entityId.value] : []),
  set: (value: string[]) => setQuery({ id: value[0] ?? null }),
})

// --- Datos de la vista -----------------------------------------------------------------------

const loading = ref(false)
const error = ref<ShellErrorState | null>(null)
const sessions = ref<TimetableSession[]>([])
const frameSlots = ref<TimeSlot[]>([])
/** Vista de grupo sin marco horario en su etapa: se avisa con texto. */
const noFrame = ref(false)
const groupAssignments = ref<AssignmentOption[]>([])
const sessionsError = ref<string | null>(null)

const gridSlots = computed<TimeSlot[]>(() => {
  if (view.value === 'group') return frameSlots.value

  const unique = new Map<string, TimeSlot>()

  for (const session of sessions.value) unique.set(session.slot.public_id, session.slot)

  return [...unique.values()]
})

async function loadOptions(): Promise<void> {
  if (selectedId.value === null) return

  const year = selectedId.value

  try {
    if (view.value === 'group') {
      groups.value = (await listGroups({ academic_year: year, per_page: OPTIONS_PER_PAGE })).data
    } else if (view.value === 'subject') {
      subjects.value = (
        await listSubjects({ academic_year: year, per_page: OPTIONS_PER_PAGE })
      ).data
    } else if (view.value === 'facility') {
      facilities.value = (
        await listFacilities({ facility_type: ['aula'], per_page: OPTIONS_PER_PAGE })
      ).data
    }
  } catch (err) {
    error.value = resolveErrorState(err) ?? { kind: 'unexpected' }
  }
}

async function loadGrid(): Promise<void> {
  sessionsError.value = null
  noFrame.value = false
  frameSlots.value = []
  groupAssignments.value = []
  sessions.value = []

  const year = selectedId.value
  const id = entityId.value

  if (year === null || id === null) return

  loading.value = true
  error.value = null

  try {
    const filter = { [view.value]: id }

    sessions.value = (await listTimetableSessions({ academic_year: year, ...filter })).data

    if (view.value === 'group') {
      const group = await getGroup(id)
      const course = await getCourse(group.course)
      const frames = await listTimetableFrames({
        academic_year: year,
        education_level: [course.education_level],
      })
      const ordinary = frames.data.find((frame) => frame.frame_type === 'ordinaria')

      noFrame.value = ordinary === undefined
      frameSlots.value = ordinary?.slots ?? []

      if (canCreate.value) {
        await loadGroupAssignments(group.public_id, year)
      }
    }
  } catch (err) {
    error.value = resolveErrorState(err) ?? { kind: 'unexpected' }
  } finally {
    loading.value = false
  }
}

async function loadGroupAssignments(groupId: string, year: string): Promise<void> {
  try {
    const [assignments, groupSubjects, subgroups] = await Promise.all([
      listTeachingAssignments({
        academic_year: year,
        group: [groupId],
        per_page: OPTIONS_PER_PAGE,
      }),
      listGroupSubjects(groupId),
      listSubgroups(groupId),
    ])

    groupAssignments.value = assignments.data.map((assignment) => {
      const subject = groupSubjects.data.find((item) => item.public_id === assignment.subject)
      const subgroup = subgroups.data.find((item) => item.public_id === assignment.subgroup)

      return {
        public_id: assignment.public_id,
        label: [
          subject ? `${subject.code} · ${subject.name}` : assignment.subject,
          subgroup ? subgroup.code : t('acad.teaching.wholeGroup'),
          assignment.teacher?.name ?? t('acad.teaching.unknownTeacher'),
        ].join(' · '),
      }
    })
  } catch (err) {
    sessionsError.value = problemDetail(err) ?? t('acad.errors.unexpected')
  }
}

watch(
  () => [view.value, selectedId.value],
  () => void loadOptions(),
  { immediate: true },
)
watch(
  () => [view.value, entityId.value, selectedId.value],
  () => void loadGrid(),
  { immediate: true },
)

// Las aulas se necesitan también en el diálogo de la vista de grupo.
const roomOptions = ref<Facility[]>([])

watch(
  () => canCreate.value || canEdit.value,
  async (needed) => {
    if (!needed || roomOptions.value.length > 0) return

    try {
      roomOptions.value = (
        await listFacilities({ facility_type: ['aula'], per_page: OPTIONS_PER_PAGE })
      ).data
    } catch {
      roomOptions.value = []
    }
  },
  { immediate: true },
)

// --- Diálogo de sesión -------------------------------------------------------------------------

const dialogOpen = ref(false)
const editing = ref<TimetableSession | null>(null)
const creatingSlot = ref<TimeSlot | null>(null)

function startCreate(slot: TimeSlot): void {
  editing.value = null
  creatingSlot.value = slot
  dialogOpen.value = true
}

function startEdit(session: TimetableSession): void {
  editing.value = session
  creatingSlot.value = null
  dialogOpen.value = true
}

function save(body: Record<string, unknown>): Promise<unknown> {
  const current = editing.value

  return current === null
    ? createTimetableSession(body as unknown as Parameters<typeof createTimetableSession>[0])
    : updateTimetableSession(current.public_id, body)
}

async function onSaved(): Promise<void> {
  actions.clear()
  actions.message.value =
    editing.value === null ? t('acad.flash.sessionCreated') : t('acad.flash.sessionUpdated')
  await loadGrid()
}

async function removeSession(): Promise<void> {
  const current = editing.value

  if (current === null) return

  dialogOpen.value = false

  const done = await actions.confirmAndRun(
    {
      title: t('acad.timetable.confirmDelete.title', { subject: current.subject.name }),
      description: t('acad.timetable.confirmDelete.description'),
      confirmLabel: t('acad.timetable.confirmDelete.confirm'),
      destructive: true,
    },
    () => deleteTimetableSession(current.public_id),
    t('acad.flash.sessionDeleted'),
  )

  if (done) {
    await loadGrid()
  }
}

const viewLabel = computed(() => t(`acad.timetable.views.${view.value}`))
const caption = computed(() => t('acad.timetable.grid.caption', { view: viewLabel.value }))
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-6">
    <header class="flex flex-col gap-1">
      <h1 class="text-lg font-semibold">{{ t('acad.timetable.title') }}</h1>
      <p class="text-muted-foreground text-sm">{{ t('acad.timetable.description') }}</p>
    </header>

    <StructureNotices
      :read-only="readOnly"
      :message="actions.message.value"
      :action-error="actions.actionError.value"
      :dependents="actions.dependents.value"
    />

    <EmptyState
      v-if="noActive"
      :icon="CalendarX"
      :title="t('acad.structure.noActive.title')"
      :text="t('acad.structure.noActive.text')"
    />

    <template v-else>
      <section class="flex flex-wrap items-start gap-4" :aria-label="t('acad.timetable.selection')">
        <div class="flex min-w-48 flex-col gap-1.5">
          <Label for="acad-timetable-view">{{ t('acad.timetable.viewLabel') }}</Label>
          <Select :model-value="view" @update:model-value="chooseView">
            <SelectTrigger id="acad-timetable-view">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="item in VIEWS" :key="item" :value="item">
                {{ t(`acad.timetable.views.${item}`) }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div v-if="view === 'teacher'" class="min-w-64 flex-1">
          <StaffPicker
            v-model="teacherSelection"
            id-base="acad-timetable-teacher"
            :legend="t('acad.timetable.entity.teacher')"
          />
        </div>
        <div v-else class="flex min-w-64 flex-col gap-1.5">
          <Label for="acad-timetable-entity">{{ t(`acad.timetable.entity.${view}`) }}</Label>
          <Select :model-value="entityId ?? undefined" @update:model-value="chooseEntity">
            <SelectTrigger id="acad-timetable-entity">
              <SelectValue :placeholder="t('acad.form.choose')" />
            </SelectTrigger>
            <SelectContent>
              <template v-if="view === 'group'">
                <SelectItem v-for="item in groups" :key="item.public_id" :value="item.public_id">
                  {{ item.code }} · {{ item.name }}
                </SelectItem>
              </template>
              <template v-else-if="view === 'subject'">
                <SelectItem v-for="item in subjects" :key="item.public_id" :value="item.public_id">
                  {{ item.code }} · {{ item.name }}
                </SelectItem>
              </template>
              <template v-else>
                <SelectItem
                  v-for="item in facilities"
                  :key="item.public_id"
                  :value="item.public_id"
                >
                  {{ item.code }} · {{ item.name }}
                </SelectItem>
              </template>
            </SelectContent>
          </Select>
        </div>
      </section>

      <p v-if="entityId === null" role="status" class="text-muted-foreground text-sm">
        {{ t('acad.timetable.chooseEntity') }}
      </p>
      <LoadingState v-else-if="loading" />
      <ErrorState v-else-if="error" :state="error" @retry="loadGrid" />
      <template v-else>
        <p v-if="sessionsError" role="alert" class="text-destructive text-sm">
          {{ sessionsError }}
        </p>
        <p
          v-if="noFrame"
          role="status"
          class="text-muted-foreground text-sm"
          data-slot="acad-timetable-no-frame"
        >
          {{ t('acad.timetable.noFrame') }}
        </p>
        <TimetableGrid
          v-else
          :slots="gridSlots"
          :sessions="sessions"
          :can-create="canCreate"
          :can-edit="canEdit"
          :caption="caption"
          @create="startCreate"
          @edit="startEdit"
        />
      </template>
    </template>

    <SessionFormDialog
      v-model:open="dialogOpen"
      :session="editing"
      :target-slot="creatingSlot"
      :can-remove="canRemove"
      :assignments="groupAssignments"
      :facilities="roomOptions"
      :save="save"
      @saved="onSaved"
      @remove="removeSession"
    />

    <ConfirmDialog
      :open="actions.confirmation.open.value"
      :request="actions.confirmation.request.value"
      @confirm="actions.confirmation.confirm"
      @cancel="actions.confirmation.cancel"
    />
  </div>
</template>
