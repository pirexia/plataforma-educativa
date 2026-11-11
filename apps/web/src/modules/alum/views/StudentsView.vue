<script setup lang="ts">
/**
 * `/alumnado` (`docs/modulos/REQ-ALUM/funcional.md §10`, `REQ-ALUM-001`; `1.15-d` T1, `CA-ALUM-070`, `CA-ALUM-071`).
 * Listado del alumnado con el componente de tablas de `ADR-054`: modo `page`, estado en la URL **sin `q`** (la búsqueda
 * por nombre nunca va a la URL, `RN-CORE-54`), orden por defecto del servidor (`name`) y búsqueda por prefijo de
 * nombre (`q`, mínimo 2 caracteres). El curso académico es el del selector del *shell* (`?academic_year=`, `RN-ACAD-40`).
 *
 * - Filtros de matrícula (curso de etapa, grupo y estado): solo con `matricula.leer`; sin él el servidor responde
 *   `403 alum.validation.filter_forbidden`, así que ni se declaran. Las opciones de curso y grupo salen de la API de
 *   `acad` del curso académico elegido.
 * - «Incluir bajas»: solo con `alumno.eliminar`; las bajas se restauran desde la fila (`RN-ALUM-06`).
 * - «Nuevo alumno»: solo con `alumno.crear` y con un curso escribible (`RN-ACAD-42`); el servidor decide
 *   igualmente (`INV-002`). La unidad familiar del alta solo se ofrece con `familia.actualizar` (`OPEN-ALUM-31` = A).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFetcher,
  type DataTableFilter,
} from '@/data-table'
import { useAcademicYear } from '@/academic-year'
import { listCourses, listGroups } from '@/modules/acad/api'
import ActionNotices from '../components/ActionNotices.vue'
import StudentFormDialog from '../components/StudentFormDialog.vue'
import { createStudent, listStudents, restoreStudent } from '../api'
import { useActions } from '../composables/useActions'
import { useIdempotencyKey } from '../composables/useIdempotencyKey'
import { usePermissions } from '../composables/usePermissions'
import type { StudentCreate, StudentSummary } from '../types'

/** `ADR-038 §4`: máximo del servidor; un curso o grupo por etapa cabe de sobra (`ADR-005`). */
const OPTIONS_PER_PAGE = 100
const MIN_QUERY = 2
const STATUSES = ['activa', 'baja']

const t = useT()
const router = useRouter()
const { can } = usePermissions()
const { formatDate } = useDataTableFormatters()
const year = useAcademicYear()
const actions = useActions()
const idempotency = useIdempotencyKey()

const canCreate = computed(() => can('alumno.crear') && !year.readOnly.value)
const canRestore = computed(() => can('alumno.eliminar') && !year.readOnly.value)
const canSeeEnrollments = computed(() => can('matricula.leer'))
const canLinkFamily = computed(() => can('familia.actualizar'))

const formOpen = ref(false)
const refresh = ref(0)

// --- Opciones de los filtros de curso y grupo (de `acad`, del curso académico elegido) -----------------------------

const courseOptions = ref<{ value: string; label: string }[]>([])
const groupOptions = ref<{ value: string; label: string }[]>([])
const optionsReady = ref(false)

async function loadOptions(): Promise<void> {
  optionsReady.value = false
  courseOptions.value = []
  groupOptions.value = []

  if (canSeeEnrollments.value) {
    const params = { academic_year: year.urlYear.value ?? undefined, per_page: OPTIONS_PER_PAGE }
    const [courses, groups] = await Promise.allSettled([listCourses(params), listGroups(params)])

    // Si `acad` no responde (permiso, módulo), el filtro simplemente no se ofrece.
    if (courses.status === 'fulfilled') {
      courseOptions.value = courses.value.data.map((course) => ({
        value: course.public_id,
        label: course.name || course.code,
      }))
    }

    if (groups.status === 'fulfilled') {
      groupOptions.value = groups.value.data.map((group) => ({
        value: group.public_id,
        label: group.name || group.code,
      }))
    }
  }

  optionsReady.value = true
}

onMounted(loadOptions)
watch(() => year.urlYear.value, loadOptions)

const filters = computed<DataTableFilter[]>(() => {
  const result: DataTableFilter[] = []

  if (canSeeEnrollments.value) {
    if (courseOptions.value.length > 0) {
      result.push({
        type: 'enum',
        id: 'course',
        labelKey: 'alum.students.filters.course',
        multiple: false,
        options: courseOptions.value,
      })
    }

    if (groupOptions.value.length > 0) {
      result.push({
        type: 'enum',
        id: 'group',
        labelKey: 'alum.students.filters.group',
        multiple: false,
        options: groupOptions.value,
      })
    }

    result.push({
      type: 'enum',
      id: 'enrollment_status',
      labelKey: 'alum.students.filters.enrollmentStatus',
      multiple: false,
      options: STATUSES.map((value) => ({
        value,
        labelKey: `alum.enums.enrollmentStatus.${value}`,
      })),
    })
  }

  if (can('alumno.eliminar')) {
    result.push({
      type: 'boolean',
      id: 'include_deleted',
      labelKey: 'alum.students.filters.includeDeleted',
      twoState: true,
    })
  }

  return result
})

// --- Tabla ---------------------------------------------------------------------------------------------------------

function nameOf(student: StudentSummary): string {
  return student.display_name === '' ? t('alum.students.unnamed') : student.display_name
}

const columns = computed<DataTableColumn<StudentSummary>[]>(() => [
  {
    id: 'name',
    headerKey: 'alum.fields.name',
    sortable: true,
    rowHeader: true,
    hideable: false,
    card: 'title',
    value: nameOf,
  },
  {
    id: 'birth_date',
    headerKey: 'alum.fields.birthDate',
    value: (student) => formatDate(student.birth_date),
    card: 'field',
  },
  ...(canSeeEnrollments.value
    ? [
        {
          id: 'enrollment',
          headerKey: 'alum.fields.enrollment',
          value: (student: StudentSummary) => {
            const enrollment = student.enrollment

            if (enrollment === null) {
              return null
            }

            return enrollment.group === null
              ? enrollment.course.code
              : `${enrollment.course.code} · ${enrollment.group.name}`
          },
          card: 'field' as const,
        },
      ]
    : []),
  {
    id: 'created_at',
    headerKey: 'alum.fields.createdAt',
    sortable: true,
    defaultHidden: true,
    value: (student) => formatDate(student.created_at),
    card: 'omit',
  },
  ...(canRestore.value && filters.value.some((filter) => filter.id === 'include_deleted')
    ? [
        {
          id: 'actions',
          headerKey: 'alum.fields.actions',
          hideable: false,
          card: 'actions' as const,
          value: () => null,
        },
      ]
    : []),
])

const fetchStudents: DataTableFetcher<StudentSummary> = (query) =>
  listStudents({
    q: query.q !== undefined && query.q.length >= MIN_QUERY ? query.q : undefined,
    academic_year: year.urlYear.value ?? undefined,
    course: query.filters.course,
    group: query.filters.group,
    enrollment_status: query.filters.enrollment_status,
    include_deleted: query.filters.include_deleted,
    sort: query.sort,
    page: query.page,
    per_page: query.per_page,
  })

/** El curso académico seleccionado viaja a la ficha: un curso cerrado la deja en solo lectura (`RN-ACAD-42`). */
function detailLocation(student: StudentSummary) {
  return {
    name: 'alum-student-detail',
    params: { publicId: student.public_id },
    query: year.urlYear.value ? { academic_year: year.urlYear.value } : {},
  }
}

async function save(body: unknown): Promise<unknown> {
  const create = body as StudentCreate
  const student = await createStudent(create, idempotency.keyFor(create))

  idempotency.reset()
  await router.push({ name: 'alum-student-detail', params: { publicId: student.public_id } })

  return student
}

async function restore(student: StudentSummary): Promise<void> {
  const done = await actions.confirmAndRun(
    {
      title: t('alum.students.confirmRestore.title', { name: nameOf(student) }),
      description: t('alum.students.confirmRestore.description'),
      confirmLabel: t('alum.students.confirmRestore.confirm', { name: nameOf(student) }),
    },
    () => restoreStudent(student.public_id),
    t('alum.students.restored'),
  )

  if (done) {
    refresh.value += 1
  }
}
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-lg font-semibold">{{ t('alum.students.title') }}</h1>
        <p class="text-muted-foreground text-sm">{{ t('alum.students.intro') }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <Button v-if="canCreate" type="button" @click="formOpen = true">
          {{ t('alum.students.new') }}
        </Button>
      </div>
    </div>

    <ActionNotices :message="actions.message.value" :action-error="actions.actionError.value" />

    <DataTable
      v-if="optionsReady"
      :key="`${year.urlYear.value ?? 'active'}-${refresh}`"
      table-id="alum.students"
      :caption="t('alum.students.caption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchStudents"
      :filters="filters"
      searchable
      url-state
      :empty-title="t('alum.students.empty.title')"
      :empty-text="canCreate ? t('alum.students.empty.text') : ''"
      :empty-action-label="canCreate ? t('alum.students.new') : undefined"
      :card-heading-level="2"
      @empty-action="formOpen = true"
    >
      <template #cell-name="{ row }">
        <RouterLink
          v-if="row.deleted_at === undefined"
          :to="detailLocation(row)"
          class="text-primary-on-background focus-visible:ring-ring/50 rounded-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-3"
        >
          {{ nameOf(row) }}
        </RouterLink>
        <span v-else>
          {{ nameOf(row) }}
          <span class="text-muted-foreground text-xs">({{ t('alum.students.deleted') }})</span>
        </span>
      </template>
      <template #cell-actions="{ row }">
        <Button
          v-if="row.deleted_at !== undefined"
          type="button"
          variant="outline"
          size="sm"
          @click="restore(row)"
        >
          {{ t('alum.students.restore') }}
          <span class="sr-only">{{ nameOf(row) }}</span>
        </Button>
      </template>
    </DataTable>

    <StudentFormDialog
      v-model:open="formOpen"
      mode="create"
      :can-link-family="canLinkFamily"
      :save="save"
      @reset="idempotency.reset"
    />

    <ConfirmDialog
      :open="actions.confirmation.open.value"
      :request="actions.confirmation.request.value"
      @confirm="actions.confirmation.confirm"
      @cancel="actions.confirmation.cancel"
    />
  </div>
</template>
