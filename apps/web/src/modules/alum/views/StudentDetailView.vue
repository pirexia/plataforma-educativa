<script setup lang="ts">
/**
 * `/alumnado/:publicId` (`docs/modulos/REQ-ALUM/funcional.md §10`, `REQ-ALUM-001`; `1.15-d` T1, `CA-ALUM-070`,
 * `CA-ALUM-071`). Ficha del alumno: datos personales (documento **enmascarado**, nunca completo), fotografía y, con su
 * permiso, tutores y estado de imagen de `fam-unit`. Las matrículas, en `1.15-d` T2a.
 *
 * - Cada sección y cada acción se muestran si y solo si el permiso de su *endpoint* está en `/me.permissions`
 *   (`RN-CORE-61`); el servidor decide igualmente (`INV-002`): `alumno.actualizar` (corregir, foto), `alumno.eliminar`
 *   (baja), `vinculo_tutor.leer` (tutores y contacto de emergencia), `consentimiento_imagen.leer` (estado de imagen),
 *   `matricula.leer` (matrículas) y, por acción, `matricula.crear` (alta), `matricula.actualizar` (baja y solicitud de
 *   cambio de grupo) y `matricula.eliminar` (anulación) (`1.15-d` T2a, decisión del usuario 2026-11-11).
 * - Sin acciones de escritura con un curso de solo lectura (`RN-ACAD-42`): el curso es el de `?academic_year=`.
 * - **Lecturas auditadas bajo demanda**: la URL de la foto, los vínculos de tutor y los consentimientos de imagen
 *   escriben una fila `read`; no se piden al montar sino con una acción explícita (`frontend-spa` regla 7).
 * - Los vínculos no traen el nombre del tutor (la API de `fam_unit` los devuelve por identificador): se muestran la
 *   relación, la representación legal, el contacto de emergencia y el estado, con texto además de color.
 * - Accesibilidad (WCAG 2.2 AA): estados con texto e icono, botones con nombre único, baja con confirmación
 *   (`RN-CORE-64`), errores junto a la acción en una región `alert`.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Ban, CircleCheck, CircleHelp, CircleX, Siren } from '@lucide/vue'
import { i18n, useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableLocalFetcher,
} from '@/data-table'
import { useAcademicYear } from '@/academic-year'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import { listImageConsents } from '@/modules/fam-unit/api'
import type { GuardianStudentLink, ImageConsent } from '@/modules/fam-unit/types'
import ActionNotices from '../components/ActionNotices.vue'
import EnrollmentFormDialog from '../components/EnrollmentFormDialog.vue'
import EnrollmentWithdrawDialog from '../components/EnrollmentWithdrawDialog.vue'
import GroupChangeRequestDialog from '../components/GroupChangeRequestDialog.vue'
import StudentFormDialog from '../components/StudentFormDialog.vue'
import StudentPhotoPanel from '../components/StudentPhotoPanel.vue'
import {
  deleteEnrollment,
  deleteStudent,
  deleteStudentPhoto,
  getStudent,
  listStudentGuardianLinks,
  putStudentPhoto,
  updateStudent,
} from '../api'
import { useActions } from '../composables/useActions'
import { useLoad } from '../composables/useLoad'
import { usePermissions } from '../composables/usePermissions'
import { problemDetail } from '../composables/problem'
import type { Enrollment, Student, StudentUpdate } from '../types'

/** Una persona tiene un puñado de vínculos y de niveles de consentimiento: una página basta. */
const PER_PAGE = 100

const t = useT()
const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const { formatDate } = useDataTableFormatters()
const year = useAcademicYear()
const actions = useActions()

const publicId = computed(() => String(route.params.publicId))

/**
 * `RN-ACAD-42`: con un curso de solo lectura no hay acciones de escritura. Se usa `year.writable` (no `!readOnly`): con
 * el curso aún sin resolver no es escribible (`useAcademicYear`).
 */
const writable = computed(() => year.writable.value)
const canUpdate = computed(() => can('alumno.actualizar') && writable.value)
const canDelete = computed(() => can('alumno.eliminar') && writable.value)
const canReadLinks = computed(() => can('vinculo_tutor.leer'))
const canReadConsents = computed(() => can('consentimiento_imagen.leer'))
const canReadEnrollments = computed(() => can('matricula.leer'))
const canEnroll = computed(() => can('matricula.crear') && writable.value)

/** Una matrícula solo se toca si es del curso académico escribible elegido (`RN-ACAD-42`). */
function inSelectedYear(enrollment: Enrollment): boolean {
  return writable.value && enrollment.academic_year.public_id === year.selectedId.value
}

function canWithdraw(enrollment: Enrollment): boolean {
  return can('matricula.actualizar') && enrollment.status === 'activa' && inSelectedYear(enrollment)
}

function canRequestChange(enrollment: Enrollment): boolean {
  return canWithdraw(enrollment) && enrollment.group !== null
}

function canCancelEnrollment(enrollment: Enrollment): boolean {
  return can('matricula.eliminar') && inSelectedYear(enrollment)
}

const { data, loading, error, load } = useLoad<Student>(() => getStudent(publicId.value))

onMounted(load)

/** Recarga sin volver al estado de carga: el foco y los diálogos se conservan. */
async function refresh(): Promise<void> {
  try {
    data.value = await getStudent(publicId.value)
    enrollmentsKey.value += 1
  } catch (err) {
    actions.fail(err)
  }
}

const formOpen = ref(false)
/** Fuerza a la tabla local de matrículas a releer tras una acción (como `refresh` de `StudentsView`). */
const enrollmentsKey = ref(0)

// --- Matrículas (`matricula.*`, `1.15-d` T2a) -----------------------------------------------------------------------

const enrollOpen = ref(false)
const withdrawOpen = ref(false)
const withdrawTarget = ref<Enrollment | null>(null)
const changeOpen = ref(false)
const changeTarget = ref<Enrollment | null>(null)

function openWithdraw(enrollment: Enrollment): void {
  withdrawTarget.value = enrollment
  withdrawOpen.value = true
}

function openChange(enrollment: Enrollment): void {
  changeTarget.value = enrollment
  changeOpen.value = true
}

async function onEnrolled(): Promise<void> {
  actions.clear()
  actions.message.value = t('alum.enrollments.created')
  await refresh()
}

async function onWithdrawn(): Promise<void> {
  actions.clear()
  actions.message.value = t('alum.enrollments.withdrawn')
  await refresh()
}

async function onChangeRequested(): Promise<void> {
  actions.clear()
  actions.message.value = t('alum.groupChange.requested')
  await refresh()
}

async function cancelEnrollment(enrollment: Enrollment): Promise<void> {
  const done = await actions.confirmAndRun(
    {
      title: t('alum.enrollments.confirmCancel.title', { course: enrollment.course.code }),
      description: t('alum.enrollments.confirmCancel.description'),
      confirmLabel: t('alum.enrollments.confirmCancel.confirm', {
        course: enrollment.course.code,
      }),
      destructive: true,
    },
    () => deleteEnrollment(enrollment.public_id),
    t('alum.enrollments.cancelled'),
  )

  if (done) {
    await refresh()
  }
}

function label(group: string, code: string | null): string {
  if (code === null) {
    return t('alum.common.dash')
  }

  const key = `alum.enums.${group}.${code}`
  const text = t(key)

  return text === key ? code : text
}

function nameOf(student: Student): string {
  return student.display_name === '' ? t('alum.students.unnamed') : student.display_name
}

async function save(body: unknown): Promise<unknown> {
  const updated = await updateStudent(publicId.value, body as StudentUpdate)

  data.value = updated
  actions.clear()
  actions.message.value = t('alum.detail.saved')

  return updated
}

async function remove(): Promise<void> {
  const student = data.value

  if (student === null) {
    return
  }

  const done = await actions.confirmAndRun(
    {
      title: t('alum.detail.confirmDelete.title', { name: nameOf(student) }),
      description: t('alum.detail.confirmDelete.description'),
      confirmLabel: t('alum.detail.confirmDelete.confirm', { name: nameOf(student) }),
      destructive: true,
    },
    () => deleteStudent(student.public_id),
    t('alum.detail.deleted'),
  )

  if (done) {
    await router.push({ name: 'alum-students' })
  }
}

async function uploadPhoto(file: File): Promise<unknown> {
  return putStudentPhoto(publicId.value, file)
}

async function onPhotoUploaded(): Promise<void> {
  actions.clear()
  actions.message.value = t('alum.photo.saved')
  await refresh()
}

async function removePhoto(): Promise<void> {
  const student = data.value

  if (student === null) {
    return
  }

  const done = await actions.confirmAndRun(
    {
      title: t('alum.photo.confirmRemove.title', { name: nameOf(student) }),
      description: t('alum.photo.confirmRemove.description'),
      confirmLabel: t('alum.photo.confirmRemove.confirm', { name: nameOf(student) }),
      destructive: true,
    },
    () => deleteStudentPhoto(student.public_id),
    t('alum.photo.removed'),
  )

  if (done) {
    await refresh()
  }
}

// --- Secciones de `fam-unit`, bajo demanda (lectura auditada) -------------------------------------------------------

const links = ref<GuardianStudentLink[] | null>(null)
const linksLoading = ref(false)
const linksError = ref<string | null>(null)

async function loadLinks(): Promise<void> {
  const student = data.value

  if (student === null) {
    return
  }

  linksLoading.value = true
  linksError.value = null

  try {
    links.value = (
      await listStudentGuardianLinks(student.person.public_id, { per_page: PER_PAGE })
    ).data
  } catch (err) {
    links.value = null
    linksError.value = problemDetail(err) ?? t('alum.errors.unexpected')
  } finally {
    linksLoading.value = false
  }
}

const consents = ref<ImageConsent[] | null>(null)
const consentsLoading = ref(false)
const consentsError = ref<string | null>(null)

async function loadConsents(): Promise<void> {
  const student = data.value

  if (student === null) {
    return
  }

  consentsLoading.value = true
  consentsError.value = null

  try {
    consents.value = (
      await listImageConsents({ student_person: student.person.public_id, per_page: PER_PAGE })
    ).data
  } catch (err) {
    consents.value = null
    consentsError.value = problemDetail(err) ?? t('alum.errors.unexpected')
  } finally {
    consentsLoading.value = false
  }
}

const fetchLinks: DataTableLocalFetcher<GuardianStudentLink> = async () => ({
  data: links.value ?? [],
})
const fetchConsents: DataTableLocalFetcher<ImageConsent> = async () => ({
  data: consents.value ?? [],
})
const consentRowKey = (row: ImageConsent): string => row.level

const fetchEnrollments: DataTableLocalFetcher<Enrollment> = async () => ({
  data: data.value?.enrollments ?? [],
})
const enrollmentRowKey = (row: Enrollment): string => row.public_id

/** Nombre del curso de etapa en el idioma activo (`es` ⇒ `es-ES`), con respaldo en es-ES y en el código. */
function courseName(row: Enrollment): string {
  const active = String(i18n.global.locale.value)
  const names = row.course.name_translations

  return names[active === 'es' ? 'es-ES' : active] ?? names['es-ES'] ?? row.course.code
}

const hasRowActions = (rows: Enrollment[]): boolean =>
  rows.some((row) => canWithdraw(row) || canRequestChange(row) || canCancelEnrollment(row))

const enrollmentColumns = computed<DataTableColumn<Enrollment>[]>(() => [
  {
    id: 'course',
    headerKey: 'alum.enrollments.course',
    rowHeader: true,
    hideable: false,
    card: 'title',
    value: (row) => courseName(row),
  },
  {
    id: 'academic_year',
    headerKey: 'alum.enrollments.academicYear',
    card: 'field',
    value: (row) => row.academic_year.code,
  },
  {
    id: 'group',
    headerKey: 'alum.enrollments.group',
    card: 'field',
    value: (row) => row.group?.name ?? null,
  },
  { id: 'status', headerKey: 'alum.enrollments.status', card: 'field' },
  {
    id: 'enrolled_on',
    headerKey: 'alum.enrollments.enrolledOn',
    card: 'field',
    value: (row) => formatDate(row.enrolled_on),
  },
  {
    id: 'withdrawn_on',
    headerKey: 'alum.enrollments.withdrawnOn',
    card: 'field',
    defaultHidden: true,
    value: (row) => formatDate(row.withdrawn_on),
  },
  ...(hasRowActions(data.value?.enrollments ?? [])
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

const linkColumns = computed<DataTableColumn<GuardianStudentLink>[]>(() => [
  {
    id: 'relationship',
    headerKey: 'alum.guardians.relationship',
    rowHeader: true,
    hideable: false,
    card: 'title',
    value: (row) => label('relationship', row.relationship),
  },
  {
    id: 'is_legal_guardian',
    headerKey: 'alum.guardians.legal',
    card: 'field',
    value: (row) => (row.is_legal_guardian ? t('alum.common.yes') : t('alum.common.no')),
  },
  { id: 'is_emergency_contact', headerKey: 'alum.guardians.emergency', card: 'field' },
  { id: 'status', headerKey: 'alum.guardians.status', card: 'field' },
])

const consentColumns = computed<DataTableColumn<ImageConsent>[]>(() => [
  {
    id: 'level',
    headerKey: 'alum.consents.level',
    rowHeader: true,
    hideable: false,
    card: 'title',
    value: (row) => label('consentLevel', row.level),
  },
  { id: 'state', headerKey: 'alum.consents.state', card: 'field' },
  {
    id: 'last_decision_at',
    headerKey: 'alum.consents.lastDecision',
    card: 'field',
    value: (row) => formatDate(row.last_decision_at),
  },
])

function consentIcon(state: string) {
  return state === 'autorizo' ? CircleCheck : state === 'no_autorizo' ? CircleX : CircleHelp
}

/**
 * La `RouterView` no se remonta al cambiar `:publicId`: se descartan los datos, la foto (el panel se desmonta con la
 * ficha), los vínculos y los consentimientos del alumno anterior y se carga el nuevo (`INV-002`, datos de personas).
 */
watch(publicId, () => {
  data.value = null
  links.value = null
  linksError.value = null
  consents.value = null
  consentsError.value = null
  formOpen.value = false
  enrollOpen.value = false
  withdrawOpen.value = false
  changeOpen.value = false
  void load()
})
</script>

<template>
  <div class="mx-auto flex max-w-4xl flex-col gap-6 px-4 py-6">
    <LoadingState v-if="loading && data === null" />
    <ErrorState v-else-if="error" :state="error" @retry="load" />

    <template v-else-if="data">
      <header class="flex flex-col gap-2">
        <p class="text-sm">
          <RouterLink
            :to="{ name: 'alum-students' }"
            class="text-primary-on-background focus-visible:ring-ring/50 rounded-sm underline-offset-4 outline-none hover:underline focus-visible:ring-3"
          >
            {{ t('alum.nav.backToStudents') }}
          </RouterLink>
        </p>
        <div class="flex flex-wrap items-start justify-between gap-3">
          <h1 class="text-lg font-semibold">{{ nameOf(data) }}</h1>
          <div class="flex flex-wrap gap-2">
            <Button v-if="canUpdate" type="button" variant="outline" @click="formOpen = true">
              {{ t('alum.detail.edit') }}
            </Button>
            <Button v-if="canDelete" type="button" variant="outline" @click="remove">
              {{ t('alum.detail.delete') }}
            </Button>
          </div>
        </div>
        <p v-if="year.readOnly.value" role="status" class="text-muted-foreground text-sm">
          {{ t('alum.detail.readOnlyYear') }}
        </p>
      </header>

      <ActionNotices :message="actions.message.value" :action-error="actions.actionError.value" />

      <section aria-labelledby="alum-personal" class="flex flex-col gap-3">
        <h2 id="alum-personal" class="text-sm font-semibold">{{ t('alum.detail.personal') }}</h2>
        <dl class="grid gap-3 sm:grid-cols-2">
          <div>
            <dt class="text-muted-foreground text-xs">{{ t('alum.fields.givenName') }}</dt>
            <dd class="text-sm">{{ data.given_name }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">{{ t('alum.fields.familyName1') }}</dt>
            <dd class="text-sm">{{ data.family_name_1 }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">{{ t('alum.fields.familyName2') }}</dt>
            <dd class="text-sm">{{ data.family_name_2 ?? t('alum.common.dash') }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">{{ t('alum.fields.birthDate') }}</dt>
            <dd class="text-sm">{{ formatDate(data.birth_date) ?? t('alum.common.dash') }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">{{ t('alum.fields.documentType') }}</dt>
            <dd class="text-sm">{{ label('documentType', data.document_type) }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">{{ t('alum.fields.documentNumber') }}</dt>
            <dd class="text-sm">{{ data.document_number ?? t('alum.common.dash') }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">{{ t('alum.fields.locale') }}</dt>
            <dd class="text-sm">{{ label('locale', data.locale) }}</dd>
          </div>
        </dl>
      </section>

      <section
        v-if="canReadEnrollments"
        aria-labelledby="alum-enrollments"
        class="flex flex-col gap-3"
      >
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 id="alum-enrollments" class="text-sm font-semibold">
            {{ t('alum.enrollments.title') }}
          </h2>
          <Button
            v-if="canEnroll"
            type="button"
            variant="outline"
            size="sm"
            @click="enrollOpen = true"
          >
            {{ t('alum.enrollments.create') }}
          </Button>
        </div>
        <DataTable
          :key="enrollmentsKey"
          table-id="alum.enrollments"
          :caption="t('alum.enrollments.caption')"
          :columns="enrollmentColumns"
          mode="local"
          :fetcher="fetchEnrollments"
          :row-key="enrollmentRowKey"
          hide-single-page-footer
          :empty-title="t('alum.enrollments.empty')"
          :card-heading-level="3"
        >
          <template #cell-status="{ row }">
            <span class="inline-flex items-center gap-1">
              <component
                :is="row.status === 'activa' ? CircleCheck : Ban"
                class="size-4"
                aria-hidden="true"
              />
              {{ label('enrollmentStatus', row.status) }}
            </span>
          </template>
          <template #cell-actions="{ row }">
            <div class="flex flex-wrap gap-2">
              <Button
                v-if="canRequestChange(row)"
                type="button"
                variant="outline"
                size="sm"
                @click="openChange(row)"
              >
                {{ t('alum.groupChange.request') }}
                <span class="sr-only">{{ courseName(row) }}</span>
              </Button>
              <Button
                v-if="canWithdraw(row)"
                type="button"
                variant="outline"
                size="sm"
                @click="openWithdraw(row)"
              >
                {{ t('alum.enrollments.withdraw') }}
                <span class="sr-only">{{ courseName(row) }}</span>
              </Button>
              <Button
                v-if="canCancelEnrollment(row)"
                type="button"
                variant="outline"
                size="sm"
                @click="cancelEnrollment(row)"
              >
                {{ t('alum.enrollments.cancel') }}
                <span class="sr-only">{{ courseName(row) }}</span>
              </Button>
            </div>
          </template>
        </DataTable>
      </section>

      <section aria-labelledby="alum-photo" class="flex flex-col gap-3">
        <h2 id="alum-photo" class="text-sm font-semibold">{{ t('alum.photo.title') }}</h2>
        <StudentPhotoPanel
          :student-id="data.public_id"
          :student-name="nameOf(data)"
          :has-photo="data.has_photo"
          :can-write="canUpdate"
          :upload="uploadPhoto"
          @uploaded="onPhotoUploaded"
          @remove="removePhoto"
        />
      </section>

      <section v-if="canReadLinks" aria-labelledby="alum-guardians" class="flex flex-col gap-3">
        <h2 id="alum-guardians" class="text-sm font-semibold">{{ t('alum.guardians.title') }}</h2>
        <p class="text-muted-foreground text-xs">{{ t('alum.guardians.hint') }}</p>
        <div>
          <Button
            v-if="links === null"
            type="button"
            variant="outline"
            size="sm"
            :disabled="linksLoading"
            @click="loadLinks"
          >
            {{ t('alum.guardians.show') }}
          </Button>
        </div>
        <p v-if="linksLoading" role="status" class="text-muted-foreground text-sm">
          {{ t('alum.common.loading') }}
        </p>
        <p v-if="linksError" role="alert" class="text-destructive text-sm">{{ linksError }}</p>
        <DataTable
          v-if="links !== null"
          table-id="alum.guardians"
          :caption="t('alum.guardians.caption')"
          :columns="linkColumns"
          mode="local"
          :fetcher="fetchLinks"
          hide-single-page-footer
          :empty-title="t('alum.guardians.empty')"
          :card-heading-level="3"
        >
          <template #cell-is_emergency_contact="{ row }">
            <span v-if="row.is_emergency_contact" class="inline-flex items-center gap-1">
              <Siren class="size-4" aria-hidden="true" />
              {{ t('alum.common.yes') }}
            </span>
            <span v-else>{{ t('alum.common.no') }}</span>
          </template>
          <template #cell-status="{ row }">
            <span class="inline-flex items-center gap-1">
              <component
                :is="row.status === 'activo' ? CircleCheck : Ban"
                class="size-4"
                aria-hidden="true"
              />
              {{ label('linkStatus', row.status) }}
            </span>
          </template>
        </DataTable>
      </section>

      <section v-if="canReadConsents" aria-labelledby="alum-consents" class="flex flex-col gap-3">
        <h2 id="alum-consents" class="text-sm font-semibold">{{ t('alum.consents.title') }}</h2>
        <div>
          <Button
            v-if="consents === null"
            type="button"
            variant="outline"
            size="sm"
            :disabled="consentsLoading"
            @click="loadConsents"
          >
            {{ t('alum.consents.show') }}
          </Button>
        </div>
        <p v-if="consentsLoading" role="status" class="text-muted-foreground text-sm">
          {{ t('alum.common.loading') }}
        </p>
        <p v-if="consentsError" role="alert" class="text-destructive text-sm">
          {{ consentsError }}
        </p>
        <DataTable
          v-if="consents !== null"
          table-id="alum.consents"
          :caption="t('alum.consents.caption')"
          :columns="consentColumns"
          mode="local"
          :fetcher="fetchConsents"
          :row-key="consentRowKey"
          hide-single-page-footer
          :empty-title="t('alum.consents.empty')"
          :card-heading-level="3"
        >
          <template #cell-state="{ row }">
            <span class="inline-flex items-center gap-1">
              <component :is="consentIcon(row.state)" class="size-4" aria-hidden="true" />
              {{ label('consentState', row.state) }}
            </span>
          </template>
        </DataTable>
      </section>
    </template>

    <StudentFormDialog
      v-if="data"
      v-model:open="formOpen"
      mode="edit"
      :student="data"
      :save="save"
    />

    <EnrollmentFormDialog
      v-if="data"
      v-model:open="enrollOpen"
      :student="data.public_id"
      :academic-year="year.selectedId.value"
      @saved="onEnrolled"
    />
    <EnrollmentWithdrawDialog
      v-model:open="withdrawOpen"
      :enrollment="withdrawTarget"
      @saved="onWithdrawn"
    />
    <GroupChangeRequestDialog
      v-model:open="changeOpen"
      :enrollment="changeTarget"
      @saved="onChangeRequested"
    />

    <ConfirmDialog
      :open="actions.confirmation.open.value"
      :request="actions.confirmation.request.value"
      @confirm="actions.confirmation.confirm"
      @cancel="actions.confirmation.cancel"
    />
  </div>
</template>
