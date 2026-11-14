<script setup lang="ts">
/**
 * `docs/modulos/REQ-ALUM/funcional.md §4.3, §10` (`REQ-ALUM-003`; `1.15-d` T2a), `RN-ALUM-22`; `api.md §2`
 * (`POST /enrollments/{id}/withdrawal`, `matricula.actualizar`). Baja de una matrícula con fecha y motivo
 * (`traslado`, `baja_voluntaria`, `otro`); el botón de confirmar es el de la acción destructiva. Sin `Idempotency-Key`:
 * el reintento es `409 already_withdrawn` (`RN-ALUM-27`). La validación (fecha ≥ fecha de matrícula) es del servidor.
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
import { withdrawEnrollment } from '../api'
import { problemDetail, problemFieldErrors } from '../composables/problem'
import type { Enrollment } from '../types'
import FieldErrors from './FieldErrors.vue'

const REASONS = ['traslado', 'baja_voluntaria', 'otro']
const FIELDS = ['withdrawn_on', 'withdrawal_reason']

const props = defineProps<{ open: boolean; enrollment: Enrollment | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const t = useT()

const withdrawnOn = ref('')
const reason = ref('')
const busy = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)

watch(
  () => props.open,
  (open) => {
    if (!open) return

    withdrawnOn.value = ''
    reason.value = ''
    fieldErrors.value = {}
    formError.value = null
  },
  { immediate: true },
)

function errorsFor(field: string): string[] {
  return fieldErrors.value[field] ?? []
}

function describedBy(field: string): string | undefined {
  return errorsFor(field).length > 0 ? `alum-withdraw-${field}-errors` : undefined
}

const otherErrors = computed(() =>
  Object.entries(fieldErrors.value)
    .filter(([field]) => !FIELDS.includes(field))
    .flatMap(([, messages]) => messages),
)

const canSubmit = computed(
  () => !busy.value && props.enrollment !== null && withdrawnOn.value !== '' && reason.value !== '',
)

async function submit(): Promise<void> {
  const enrollment = props.enrollment

  if (!enrollment) return

  fieldErrors.value = {}
  formError.value = null
  busy.value = true

  try {
    await withdrawEnrollment(enrollment.public_id, {
      withdrawn_on: withdrawnOn.value,
      withdrawal_reason: reason.value,
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
    <DialogContent :close-label="t('alum.form.close')" data-slot="alum-withdraw-form">
      <DialogHeader>
        <DialogTitle>{{ t('alum.enrollments.withdrawTitle') }}</DialogTitle>
        <DialogDescription>
          {{ t('alum.enrollments.withdrawDescription', { course: enrollment?.course.code ?? '' }) }}
        </DialogDescription>
      </DialogHeader>

      <form class="flex max-h-[70vh] flex-col gap-4 overflow-y-auto" @submit.prevent="submit">
        <div class="flex flex-col gap-1.5">
          <Label for="alum-withdraw-withdrawn_on">{{ t('alum.enrollments.withdrawnOn') }}</Label>
          <Input
            id="alum-withdraw-withdrawn_on"
            v-model="withdrawnOn"
            type="date"
            required
            :aria-invalid="errorsFor('withdrawn_on').length > 0 ? 'true' : undefined"
            :aria-describedby="describedBy('withdrawn_on')"
          />
          <FieldErrors
            id="alum-withdraw-withdrawn_on-errors"
            :messages="errorsFor('withdrawn_on')"
          />
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="alum-withdraw-withdrawal_reason">{{
            t('alum.enrollments.withdrawalReason')
          }}</Label>
          <Select v-model="reason">
            <SelectTrigger
              id="alum-withdraw-withdrawal_reason"
              :aria-invalid="errorsFor('withdrawal_reason').length > 0 ? 'true' : undefined"
              :aria-describedby="describedBy('withdrawal_reason')"
            >
              <SelectValue :placeholder="t('alum.enrollments.reasonPlaceholder')" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="code in REASONS" :key="code" :value="code">
                {{ t(`alum.enums.withdrawalReason.${code}`) }}
              </SelectItem>
            </SelectContent>
          </Select>
          <FieldErrors
            id="alum-withdraw-withdrawal_reason-errors"
            :messages="errorsFor('withdrawal_reason')"
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
          <Button type="submit" variant="destructive" :disabled="!canSubmit">
            {{ t('alum.enrollments.withdrawConfirm') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
