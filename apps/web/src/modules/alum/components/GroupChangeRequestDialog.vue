<script setup lang="ts">
/**
 * `docs/modulos/REQ-ALUM/funcional.md §4.4, §5.3, §10` (`REQ-ALUM-003`; `1.15-d` T2a), `RN-ALUM-40`, `RN-ALUM-41`,
 * `RN-ALUM-45`; `api.md §3` (`POST /group-change-requests`). Solicitud de cambio de grupo de una matrícula activa con
 * grupo: grupo de destino **del mismo curso de etapa** (y distinto del actual) y motivo de 1 a 500 caracteres.
 *
 * Componente reutilizable (la ficha y, en el T2b, la lista de clase): recibe la matrícula y se encarga de cargar los
 * grupos del curso, enviar la solicitud y pintar los errores del servidor (`422 alum.validation.*`, `409`, `403`) junto
 * al campo o en el pie. La validación de negocio es del servidor (`INV-010`); aquí solo se orienta.
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
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { listGroups } from '@/modules/acad/api'
import { createGroupChangeRequest } from '../api'
import { problemDetail, problemFieldErrors } from '../composables/problem'
import type { Enrollment } from '../types'
import FieldErrors from './FieldErrors.vue'

/** `ADR-038 §4`: máximo del servidor; los grupos de un curso de etapa caben de sobra. */
const OPTIONS_PER_PAGE = 100
const MAX_REASON = 500
/** Campos con su propio mensaje; el resto (p. ej. `enrollment`: `request_already_pending`) va en el pie. */
const FIELDS = ['to_group', 'reason']

const props = defineProps<{ open: boolean; enrollment: Enrollment | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const t = useT()

const toGroup = ref('')
const reason = ref('')
const options = ref<{ value: string; label: string }[]>([])
const loadingGroups = ref(false)
const groupsError = ref<string | null>(null)
const busy = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)

async function loadGroups(enrollment: Enrollment): Promise<void> {
  loadingGroups.value = true
  groupsError.value = null
  options.value = []

  try {
    const groups = await listGroups({
      academic_year: enrollment.academic_year.public_id,
      course: [enrollment.course.public_id],
      per_page: OPTIONS_PER_PAGE,
    })

    // El servidor valida igualmente (`RN-ALUM-40`): aquí solo se ofrecen los del curso y distintos del actual.
    options.value = groups.data
      .filter(
        (group) =>
          group.course === enrollment.course.public_id &&
          group.public_id !== enrollment.group?.public_id,
      )
      .map((group) => ({ value: group.public_id, label: group.name || group.code }))
  } catch (err) {
    groupsError.value = problemDetail(err) ?? t('alum.enrollments.groupsLoadError')
  } finally {
    loadingGroups.value = false
  }
}

watch(
  () => props.open,
  (open) => {
    if (!open) return

    toGroup.value = ''
    reason.value = ''
    fieldErrors.value = {}
    formError.value = null

    if (props.enrollment) void loadGroups(props.enrollment)
  },
  { immediate: true },
)

function errorsFor(field: string): string[] {
  return fieldErrors.value[field] ?? []
}

function describedBy(field: string, extra = ''): string | undefined {
  const ids = [extra, errorsFor(field).length > 0 ? `alum-gcr-${field}-errors` : '']
    .filter((id) => id !== '')
    .join(' ')

  return ids === '' ? undefined : ids
}

const otherErrors = computed(() =>
  Object.entries(fieldErrors.value)
    .filter(([field]) => !FIELDS.includes(field))
    .flatMap(([, messages]) => messages),
)

const canSubmit = computed(
  () =>
    !busy.value && toGroup.value !== '' && reason.value.trim() !== '' && props.enrollment !== null,
)

async function submit(): Promise<void> {
  const enrollment = props.enrollment

  if (!enrollment) return

  fieldErrors.value = {}
  formError.value = null
  busy.value = true

  try {
    await createGroupChangeRequest({
      enrollment: enrollment.public_id,
      to_group: toGroup.value,
      reason: reason.value.trim(),
    })
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
    <DialogContent :close-label="t('alum.form.close')" data-slot="alum-group-change-form">
      <DialogHeader>
        <DialogTitle>{{ t('alum.groupChange.title') }}</DialogTitle>
        <DialogDescription>
          {{
            t('alum.groupChange.description', {
              student: enrollment?.student.display_name ?? '',
              group: enrollment?.group?.name ?? t('alum.common.dash'),
            })
          }}
        </DialogDescription>
      </DialogHeader>

      <form class="flex max-h-[70vh] flex-col gap-4 overflow-y-auto" @submit.prevent="submit">
        <div class="flex flex-col gap-1.5">
          <Label for="alum-gcr-to_group">{{ t('alum.groupChange.toGroup') }}</Label>
          <Select v-model="toGroup" :disabled="loadingGroups || options.length === 0">
            <SelectTrigger
              id="alum-gcr-to_group"
              :aria-invalid="errorsFor('to_group').length > 0 ? 'true' : undefined"
              :aria-describedby="describedBy('to_group', 'alum-gcr-to_group-hint')"
            >
              <SelectValue :placeholder="t('alum.groupChange.toGroupPlaceholder')" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="option in options" :key="option.value" :value="option.value">
                {{ option.label }}
              </SelectItem>
            </SelectContent>
          </Select>
          <p id="alum-gcr-to_group-hint" class="text-muted-foreground text-xs">
            <template v-if="loadingGroups">{{ t('alum.common.loading') }}</template>
            <template v-else-if="groupsError">{{ groupsError }}</template>
            <template v-else-if="options.length === 0">
              {{ t('alum.groupChange.noGroups') }}
            </template>
            <template v-else>{{ t('alum.groupChange.toGroupHint') }}</template>
          </p>
          <FieldErrors id="alum-gcr-to_group-errors" :messages="errorsFor('to_group')" />
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="alum-gcr-reason">{{ t('alum.groupChange.reason') }}</Label>
          <Textarea
            id="alum-gcr-reason"
            v-model="reason"
            required
            :maxlength="MAX_REASON"
            rows="3"
            :aria-invalid="errorsFor('reason').length > 0 ? 'true' : undefined"
            :aria-describedby="describedBy('reason', 'alum-gcr-reason-hint')"
          />
          <p id="alum-gcr-reason-hint" class="text-muted-foreground text-xs">
            {{ t('alum.groupChange.reasonHint', { max: MAX_REASON }) }}
          </p>
          <FieldErrors id="alum-gcr-reason-errors" :messages="errorsFor('reason')" />
        </div>

        <ul v-if="otherErrors.length > 0" role="alert" class="text-destructive text-sm">
          <li v-for="message in otherErrors" :key="message">{{ message }}</li>
        </ul>
        <p v-if="formError" role="alert" class="text-destructive text-sm">{{ formError }}</p>

        <DialogFooter>
          <Button type="button" variant="outline" @click="emit('update:open', false)">
            {{ t('alum.form.cancel') }}
          </Button>
          <Button type="submit" :disabled="!canSubmit">{{ t('alum.groupChange.submit') }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
