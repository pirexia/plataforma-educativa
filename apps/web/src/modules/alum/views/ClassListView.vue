<script setup lang="ts">
/**
 * `/alumnado/listas` (`docs/modulos/REQ-ALUM/funcional.md §10`, `REQ-ALUM-003`; `1.15-d` T2b, `CA-ALUM-070`,
 * `CA-ALUM-071`). Lista de clase: matrículas del curso académico elegido en el selector del *shell*
 * (`?academic_year=`, `RN-ACAD-40`), con curso de etapa y grupo **opcionales** (decisión del usuario 2026-11-11), con el
 * componente de tablas de `ADR-054`: modo `page`, estado en la URL **sin `q`** (esta lista no tiene búsqueda,
 * `RN-CORE-54`) y orden `name|enrolled_on`.
 *
 * - Exportación CSV con `matricula.exportar` (cola, `INV-012`): `POST /enrollments/exports` admite **un** valor por campo
 *   y ningún `q`, por eso los filtros son de valor único. Se permite también con un curso de solo lectura.
 * - Acción por fila «Solicitar cambio de grupo» (decisión 2026-11-11): con `matricula.actualizar`, matrícula activa con
 *   grupo, **del curso elegido** y este escribible (`RN-ACAD-42`). El servidor decide igualmente (`INV-002`).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFetcher,
  type DataTableFilter,
} from '@/data-table'
import { useAcademicYear } from '@/academic-year'
import { listCourses, listGroups } from '@/modules/acad/api'
import { getDataExport } from '@/modules/core/api'
import ActionNotices from '../components/ActionNotices.vue'
import GroupChangeRequestDialog from '../components/GroupChangeRequestDialog.vue'
import { exportEnrollments, listEnrollments } from '../api'
import { useActions } from '../composables/useActions'
import { usePermissions } from '../composables/usePermissions'
import type { Enrollment } from '../types'

/** `ADR-038 §4`: máximo del servidor; un curso o grupo por etapa cabe de sobra (`ADR-005`). */
const OPTIONS_PER_PAGE = 100
const STATUSES = ['activa', 'baja']

const t = useT()
const { can } = usePermissions()
const { formatDate } = useDataTableFormatters()
const year = useAcademicYear()
const actions = useActions()

const refresh = ref(0)
const requestOpen = ref(false)
const requestTarget = ref<Enrollment | null>(null)

// --- Opciones de los filtros de curso y grupo (de `acad`, del curso académico elegido) -----------------------------

const courseOptions = ref<{ value: string; label: string }[]>([])
const groupOptions = ref<{ value: string; label: string }[]>([])
const optionsReady = ref(false)

async function loadOptions(): Promise<void> {
  optionsReady.value = false
  courseOptions.value = []
  groupOptions.value = []

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

  optionsReady.value = true
}

onMounted(loadOptions)
watch(() => year.urlYear.value, loadOptions)

const filters = computed<DataTableFilter[]>(() => {
  const result: DataTableFilter[] = []

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
    id: 'status',
    labelKey: 'alum.enrollments.status',
    multiple: false,
    options: STATUSES.map((value) => ({
      value,
      labelKey: `alum.enums.enrollmentStatus.${value}`,
    })),
  })

  return result
})

// --- Acciones por fila ---------------------------------------------------------------------------------------------

/** `RN-ACAD-42`: solo se escribe en un curso escribible; con el curso sin resolver, tampoco. */
function canRequest(enrollment: Enrollment): boolean {
  return (
    can('matricula.actualizar') &&
    year.writable.value &&
    enrollment.status === 'activa' &&
    enrollment.group !== null &&
    enrollment.academic_year.public_id === year.selectedId.value
  )
}

const showActions = computed(() => can('matricula.actualizar') && year.writable.value)

function openRequest(enrollment: Enrollment): void {
  actions.clear()
  requestTarget.value = enrollment
  requestOpen.value = true
}

function onRequested(): void {
  actions.message.value = t('alum.groupChange.requested')
  actions.actionError.value = null
  refresh.value += 1
}

// --- Tabla ---------------------------------------------------------------------------------------------------------

function nameOf(enrollment: Enrollment): string {
  return enrollment.student.display_name === ''
    ? t('alum.students.unnamed')
    : enrollment.student.display_name
}

const columns = computed<DataTableColumn<Enrollment>[]>(() => [
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
    id: 'course',
    headerKey: 'alum.enrollments.course',
    value: (enrollment) => enrollment.course.code,
    card: 'field',
  },
  {
    id: 'group',
    headerKey: 'alum.enrollments.group',
    value: (enrollment) => enrollment.group?.name ?? t('alum.enrollments.noGroup'),
    card: 'field',
  },
  {
    id: 'status',
    headerKey: 'alum.enrollments.status',
    value: (enrollment) => t(`alum.enums.enrollmentStatus.${enrollment.status}`),
    card: 'field',
  },
  {
    id: 'enrolled_on',
    headerKey: 'alum.enrollments.enrolledOn',
    sortable: true,
    value: (enrollment) => formatDate(enrollment.enrolled_on),
    card: 'field',
  },
  ...(showActions.value
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

const fetchEnrollments: DataTableFetcher<Enrollment> = (query) =>
  listEnrollments({
    academic_year: year.urlYear.value ?? undefined,
    course: query.filters.course,
    group: query.filters.group,
    status: query.filters.status,
    sort: query.sort,
    page: query.page,
    per_page: query.per_page,
  })

/**
 * `POST /enrollments/exports`: un valor por campo y sin `q` (`api.md §6`). El curso es el efectivo (URL o activo): la
 * exportación lo exige. Sin curso resuelto no se ofrece (`canExport` falso).
 */
const exportConfig = computed(() => ({
  canExport: can('matricula.exportar') && year.selectedId.value !== null,
  request: (exportFilters: Record<string, string>) =>
    exportEnrollments({
      academic_year: year.selectedId.value ?? '',
      course: exportFilters.course,
      group: exportFilters.group,
      status: exportFilters.status,
    }),
  status: getDataExport,
}))

/** El curso académico seleccionado viaja a la ficha: un curso cerrado la deja en solo lectura (`RN-ACAD-42`). */
function detailLocation(enrollment: Enrollment) {
  return {
    name: 'alum-student-detail',
    params: { publicId: enrollment.student.public_id },
    query: year.urlYear.value ? { academic_year: year.urlYear.value } : {},
  }
}

const rowKey = (enrollment: Enrollment): string => enrollment.public_id
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-6">
    <div>
      <h1 class="text-lg font-semibold">{{ t('alum.classList.title') }}</h1>
      <p class="text-muted-foreground text-sm">{{ t('alum.classList.intro') }}</p>
    </div>

    <p v-if="year.readOnly.value" role="status" class="text-muted-foreground text-sm">
      {{ t('alum.classList.readOnlyYear') }}
    </p>

    <ActionNotices :message="actions.message.value" :action-error="actions.actionError.value" />

    <DataTable
      v-if="optionsReady"
      :key="`${year.urlYear.value ?? 'active'}-${refresh}`"
      table-id="alum.classList"
      :caption="t('alum.classList.caption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchEnrollments"
      :filters="filters"
      :row-key="rowKey"
      url-state
      :export-config="exportConfig"
      :empty-title="t('alum.classList.empty.title')"
      :empty-text="t('alum.classList.empty.text')"
      :card-heading-level="2"
    >
      <template #cell-name="{ row }">
        <RouterLink
          :to="detailLocation(row)"
          class="text-primary-on-background focus-visible:ring-ring/50 rounded-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-3"
        >
          {{ nameOf(row) }}
        </RouterLink>
      </template>
      <template #cell-actions="{ row }">
        <Button
          v-if="canRequest(row)"
          type="button"
          variant="outline"
          size="sm"
          @click="openRequest(row)"
        >
          {{ t('alum.groupChange.request') }}
          <span class="sr-only">{{ nameOf(row) }}</span>
        </Button>
      </template>
    </DataTable>

    <GroupChangeRequestDialog
      v-model:open="requestOpen"
      :enrollment="requestTarget"
      @saved="onRequested"
    />
  </div>
</template>
