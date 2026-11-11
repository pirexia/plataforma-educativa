<script setup lang="ts">
/**
 * `docs/modulos/REQ-ALUM/funcional.md §4.2, §10` (`REQ-ALUM-003`; `1.15-d` T2a), `RN-ALUM-21`, `RN-ALUM-29`;
 * `api.md §2` (`POST /enrollments`, `matricula.crear`). Alta de la matrícula de un alumno en el curso académico elegido:
 * curso de etapa y fecha obligatorios, grupo opcional (del curso elegido). Sin `Idempotency-Key`: el reintento es
 * `422 already_enrolled` (`RN-ALUM-27`). Los cursos y grupos salen de la API de `acad` del curso académico.
 * La validación de negocio es del servidor (`INV-010`); sus errores, ya traducidos, van junto al campo.
 */
import { computed, ref, watch } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { listCourses, listGroups } from '@/modules/acad/api'
import { createEnrollment } from '../api'
import { problemDetail, problemFieldErrors } from '../composables/problem'
import type { EnrollmentCreate, PublicId } from '../types'
import FieldErrors from './FieldErrors.vue'

const NONE = '__none__'
/** `ADR-038 §4`: máximo del servidor; un curso o grupo por etapa cabe de sobra (`ADR-005`). */
const OPTIONS_PER_PAGE = 100
const FIELDS = ['course', 'group', 'enrolled_on']

const props = defineProps<{
  open: boolean
  student: PublicId
  /** `public_id` del curso académico elegido; sin él no se puede matricular. */
  academicYear: PublicId | null
}>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const t = useT()

interface GroupOption {
  value: string
  label: string
  course: string
}

const course = ref('')
const group = ref(NONE)
const enrolledOn = ref('')
const courseOptions = ref<{ value: string; label: string }[]>([])
const allGroups = ref<GroupOption[]>([])
const loading = ref(false)
const loadError = ref<string | null>(null)
const busy = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)

const groupOptions = computed(() =>
  allGroups.value.filter((option) => option.course === course.value),
)

async function loadOptions(): Promise<void> {
  loading.value = true
  loadError.value = null
  courseOptions.value = []
  allGroups.value = []

  try {
    const params = { academic_year: props.academicYear ?? undefined, per_page: OPTIONS_PER_PAGE }
    const [courses, groups] = await Promise.all([listCourses(params), listGroups(params)])

    courseOptions.value = courses.data.map((item) => ({
      value: item.public_id,
      label: item.name || item.code,
    }))
    allGroups.value = groups.data.map((item) => ({
      value: item.public_id,
      label: item.name || item.code,
      course: item.course,
    }))
  } catch (err) {
    loadError.value = problemDetail(err) ?? t('alum.enrollments.optionsLoadError')
  } finally {
    loading.value = false
  }
}

watch(
  () => props.open,
  (open) => {
    if (!open) return

    course.value = ''
    group.value = NONE
    enrolledOn.value = ''
    fieldErrors.value = {}
    formError.value = null
    void loadOptions()
  },
  { immediate: true },
)

// Un grupo de otro curso no sirve: se descarta al cambiar de curso.
watch(course, () => {
  group.value = NONE
})

function errorsFor(field: string): string[] {
  return fieldErrors.value[field] ?? []
}

function describedBy(field: string): string | undefined {
  return errorsFor(field).length > 0 ? `alum-enrollment-${field}-errors` : undefined
}

const otherErrors = computed(() =>
  Object.entries(fieldErrors.value)
    .filter(([field]) => !FIELDS.includes(field))
    .flatMap(([, messages]) => messages),
)

const canSubmit = computed(
  () =>
    !busy.value && props.academicYear !== null && course.value !== '' && enrolledOn.value !== '',
)

async function submit(): Promise<void> {
  if (props.academicYear === null) return

  fieldErrors.value = {}
  formError.value = null
  busy.value = true

  const body: EnrollmentCreate = {
    student: props.student,
    academic_year: props.academicYear,
    course: course.value,
    enrolled_on: enrolledOn.value,
  }

  if (group.value !== NONE) body.group = group.value

  try {
    await createEnrollment(body)
    emit('saved')
    emit('update:open', false)
  } catch (err) {
    fieldErrors.value = problemFieldErrors(err)
    formError.value =
      problemDetail(err) ??
      (Object.keys(fieldErrors.value).length === 0 ? t('alum.errors.unexpected') : null)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent :close-label="t('alum.form.close')" data-slot="alum-enrollment-form">
      <DialogHeader>
        <DialogTitle>{{ t('alum.enrollments.createTitle') }}</DialogTitle>
        <DialogDescription>{{ t('alum.enrollments.createDescription') }}</DialogDescription>
      </DialogHeader>

      <form class="flex max-h-[70vh] flex-col gap-4 overflow-y-auto" @submit.prevent="submit">
        <p v-if="loadError" role="alert" class="text-destructive text-sm">{{ loadError }}</p>

        <div class="flex flex-col gap-1.5">
          <Label for="alum-enrollment-course">{{ t('alum.enrollments.course') }}</Label>
          <Select v-model="course" :disabled="loading || courseOptions.length === 0">
            <SelectTrigger
              id="alum-enrollment-course"
              :aria-invalid="errorsFor('course').length > 0 ? 'true' : undefined"
              :aria-describedby="describedBy('course')"
            >
              <SelectValue :placeholder="t('alum.enrollments.coursePlaceholder')" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="option in courseOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </SelectItem>
            </SelectContent>
          </Select>
          <FieldErrors id="alum-enrollment-course-errors" :messages="errorsFor('course')" />
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="alum-enrollment-group">{{ t('alum.enrollments.group') }}</Label>
          <Select v-model="group" :disabled="course === ''">
            <SelectTrigger
              id="alum-enrollment-group"
              :aria-invalid="errorsFor('group').length > 0 ? 'true' : undefined"
              :aria-describedby="describedBy('group')"
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem :value="NONE">{{ t('alum.enrollments.noGroup') }}</SelectItem>
              <SelectItem v-for="option in groupOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </SelectItem>
            </SelectContent>
          </Select>
          <FieldErrors id="alum-enrollment-group-errors" :messages="errorsFor('group')" />
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="alum-enrollment-enrolled_on">{{ t('alum.enrollments.enrolledOn') }}</Label>
          <Input
            id="alum-enrollment-enrolled_on"
            v-model="enrolledOn"
            type="date"
            required
            :aria-invalid="errorsFor('enrolled_on').length > 0 ? 'true' : undefined"
            :aria-describedby="describedBy('enrolled_on')"
          />
          <FieldErrors
            id="alum-enrollment-enrolled_on-errors"
            :messages="errorsFor('enrolled_on')"
          />
        </div>

        <ul v-if="otherErrors.length > 0" role="alert" class="text-destructive text-sm">
          <li v-for="message in otherErrors" :key="message">{{ message }}</li>
        </ul>
        <p v-if="formError" role="alert" class="text-destructive text-sm">{{ formError }}</p>

        <DialogFooter>
          <Button type="button" variant="outline" @click="emit('update:open', false)">
            {{ t('alum.form.cancel') }}
          </Button>
          <Button type="submit" :disabled="!canSubmit">{{ t('alum.enrollments.create') }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
