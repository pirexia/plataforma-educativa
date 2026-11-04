<script setup lang="ts">
/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.5.3` (filas «Entregar» y «Entrada fuera de horario»), `RN-PRL-02`, `-06`,
 * `-07`, `-09`, `DP-3`, `DP-4`; `1.14b-c2` T2. Un solo diálogo para la **salida** (`POST /student-movements` con la
 * persona de la tarjeta y el método de verificación) y la **entrada fuera de horario** (sin persona ni método).
 *
 * Verificación (`RN-PRL-06`, `OPEN-FAM-UNIT-47`): sin fotografía, o con la imagen caída (`photoFailed`: evento `error`
 * en la tarjeta o aquí), solo «Por el documento» está habilitado y preseleccionado, con su explicación visible; el
 * servidor acepta `documento` siempre (solo cliente). Idempotencia (`RN-PRL-09`): una `Idempotency-Key` por intento,
 * reutilizada en los reintentos del mismo cuerpo y descartada al cerrar. `422 person_not_authorized`: el
 * mensaje del servidor, igual para todas las causas, sin registrar nada y con «Volver a cargar la lista» y, con
 * permiso, «Abrir una alerta». `409 idempotency_mismatch`: obliga a cerrar y reabrir. `429`: mensaje sin reintento.
 */
import { computed, ref, watch } from 'vue'
import { ImageOff, UserRound } from '@lucide/vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Textarea } from '@/components/ui/textarea'
import { createStudentMovement } from '../api'
import { describeFailure, type SubmitFailure } from '../composables/submitError'
import { useIdempotencyKey } from '../composables/useIdempotencyKey'
import { useNtLang } from '../composables/useNtLang'
import type {
  OffScheduleReason,
  PickupListEntry,
  StudentMovement,
  StudentMovementCreate,
  VerificationMethod,
} from '../types'

const props = defineProps<{
  open: boolean
  mode: 'salida' | 'entrada'
  studentPerson: string
  entry: PickupListEntry | null
  photoFailed: boolean
  canAlert: boolean
}>()
const emit = defineEmits<{
  'update:open': [value: boolean]
  recorded: [movement: StudentMovement]
  photoError: [person: string]
  reload: []
  openAlert: []
}>()

const t = useT()
const ntLang = useNtLang()
const idempotency = useIdempotencyKey()

const REASONS: OffScheduleReason[] = ['medico', 'familiar', 'actividad', 'retraso', 'otro']
const METHODS: VerificationMethod[] = ['foto', 'documento', 'foto_y_documento']

const verification = ref<string>('')
const offSchedule = ref(false)
const reason = ref<string>('')
const notes = ref('')
const busy = ref(false)
const failure = ref<SubmitFailure | null>(null)
const clientErrors = ref<Record<string, string>>({})
/** `422 person_not_authorized` o `409 idempotency_mismatch`: no se puede reintentar con este diálogo. */
const locked = ref<'rejected' | 'mismatch' | null>(null)

const isExit = computed(() => props.mode === 'salida')
const noPhoto = computed(() => props.entry !== null && !props.entry.has_photo)
const photoUnavailable = computed(() => props.entry?.has_photo === true && props.photoFailed)
const documentOnly = computed(() => isExit.value && (noPhoto.value || photoUnavailable.value))
const showPhoto = computed(
  () => props.entry?.has_photo === true && !props.photoFailed && props.entry.photo_url !== null,
)

watch(
  () => props.open,
  (open) => {
    idempotency.reset()

    if (open) {
      verification.value = documentOnly.value ? 'documento' : ''
      offSchedule.value = !isExit.value
      reason.value = ''
      notes.value = ''
      failure.value = null
      clientErrors.value = {}
      locked.value = null
      busy.value = false
    }
  },
  { immediate: true },
)

// La imagen cayó con el diálogo abierto: solo el documento.
watch(documentOnly, (only) => {
  if (only && props.open) {
    verification.value = 'documento'
  }
})

const body = computed<StudentMovementCreate>(() => {
  const common = {
    student_person: props.studentPerson,
    delivered_by: null,
    off_schedule: offSchedule.value,
    off_schedule_reason:
      offSchedule.value && reason.value !== '' ? (reason.value as OffScheduleReason) : null,
    notes: offSchedule.value && notes.value.trim() !== '' ? notes.value.trim() : null,
  }

  return isExit.value
    ? {
        ...common,
        direction: 'salida',
        picked_up_by: props.entry?.person,
        verification_method: verification.value as VerificationMethod,
      }
    : { ...common, direction: 'entrada' }
})

function fieldMessages(field: string): string[] {
  const own = clientErrors.value[field]

  return [...(own ? [own] : []), ...(failure.value?.fields[field] ?? [])]
}

const reasonMessages = computed(() => fieldMessages('off_schedule_reason'))
const notesMessages = computed(() => fieldMessages('notes'))
const methodMessages = computed(() => fieldMessages('verification_method'))
const pickedUpMessages = computed(() => fieldMessages('picked_up_by'))

const generalMessage = computed<string | null>(() => {
  const current = failure.value

  if (current === null) return null

  if (current.status === 429) {
    const wait =
      current.retryAfter !== null
        ? ` ${t('prl.errors.retryAfter', { seconds: current.retryAfter })}`
        : ''

    return `${current.message ?? t('prl.errors.unexpected')}${wait}`
  }

  // Los errores con campo se pintan junto a su campo; el resto, aquí.
  if (current.status === 422 && Object.keys(current.fields).length > 0) return null

  return current.message ?? t('prl.errors.unexpected')
})

function validateClient(): boolean {
  const errors: Record<string, string> = {}

  if (isExit.value && verification.value === '') {
    errors.verification_method = t('prl.movement.verificationRequired')
  }

  if (offSchedule.value && reason.value === '') {
    errors.off_schedule_reason = t('prl.movement.reasonRequired')
  } else if (reason.value === 'otro' && notes.value.trim() === '') {
    errors.notes = t('prl.movement.notesRequired')
  }

  clientErrors.value = errors

  return Object.keys(errors).length === 0
}

async function submit(): Promise<void> {
  if (busy.value || locked.value !== null) return

  failure.value = null

  if (!validateClient()) return

  const payload = body.value

  busy.value = true

  try {
    const movement = await createStudentMovement(payload, idempotency.keyFor(payload))

    emit('recorded', movement)
    emit('update:open', false)
  } catch (err) {
    const described = describeFailure(err)

    failure.value = described

    if (described.codes.some((code) => code.endsWith('.person_not_authorized'))) {
      locked.value = 'rejected'
    } else if (described.status === 409) {
      locked.value = 'mismatch'
    }
  } finally {
    busy.value = false
  }
}

function reloadList(): void {
  emit('reload')
  emit('update:open', false)
}

function alertInstead(): void {
  emit('openAlert')
  emit('update:open', false)
}

const rejectedMessage = computed(
  () => pickedUpMessages.value[0] ?? failure.value?.message ?? t('prl.errors.unexpected'),
)
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent :close-label="t('prl.form.close')" data-slot="prl-movement-dialog">
      <DialogHeader>
        <DialogTitle>
          {{
            isExit
              ? t('prl.movement.deliverTitle', { person: entry?.display_name ?? '' })
              : t('prl.door.entryOffSchedule')
          }}
        </DialogTitle>
        <DialogDescription>
          {{ isExit ? t('prl.movement.deliverDescription') : t('prl.movement.entryDescription') }}
        </DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <div v-if="isExit && entry" class="flex items-center gap-3">
          <img
            v-if="showPhoto"
            :src="entry.photo_url ?? undefined"
            :alt="t('prl.photo.alt', { person: entry.display_name })"
            referrerpolicy="no-referrer"
            draggable="false"
            class="size-32 rounded-md object-cover"
            @error="emit('photoError', entry.person)"
          />
          <div
            v-else
            class="bg-muted text-muted-foreground flex size-32 flex-col items-center justify-center gap-1 rounded-md p-2 text-center text-sm"
          >
            <component :is="noPhoto ? UserRound : ImageOff" class="size-6" aria-hidden="true" />
            <span>{{ noPhoto ? t('prl.photo.none') : t('prl.photo.unavailable') }}</span>
          </div>
        </div>

        <fieldset v-if="isExit" class="flex flex-col gap-2">
          <legend class="text-sm font-medium">{{ t('prl.verification.legend') }}</legend>
          <RadioGroup
            v-model="verification"
            :aria-invalid="methodMessages.length > 0 ? 'true' : undefined"
            :aria-describedby="methodMessages.length > 0 ? 'prl-movement-method-errors' : undefined"
          >
            <div v-for="method in METHODS" :key="method" class="flex items-center gap-2">
              <RadioGroupItem
                :id="`prl-movement-method-${method}`"
                :value="method"
                :disabled="documentOnly && method !== 'documento'"
              />
              <Label :for="`prl-movement-method-${method}`" class="min-h-11 flex-1">
                {{ t(`prl.verification.${method}`) }}
              </Label>
            </div>
          </RadioGroup>
          <p v-if="noPhoto" :lang="ntLang" class="text-sm font-medium">
            {{ t('prl.verification.documentOnly') }}
          </p>
          <p v-else-if="photoUnavailable" :lang="ntLang" class="text-sm font-medium">
            {{ t('prl.verification.photoUnavailable') }}
          </p>
          <ul
            v-if="methodMessages.length > 0"
            id="prl-movement-method-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in methodMessages" :key="message">{{ message }}</li>
          </ul>
        </fieldset>

        <div v-if="isExit" class="flex min-h-11 items-center gap-2">
          <Checkbox
            id="prl-movement-off-schedule"
            :model-value="offSchedule"
            @update:model-value="offSchedule = $event === true"
          />
          <Label for="prl-movement-off-schedule" class="min-h-11 flex-1 text-sm">
            {{ t('prl.movement.offSchedule') }}
          </Label>
        </div>

        <template v-if="offSchedule">
          <fieldset class="flex flex-col gap-2">
            <legend class="text-sm font-medium">{{ t('prl.movement.reason') }}</legend>
            <RadioGroup
              v-model="reason"
              :aria-invalid="reasonMessages.length > 0 ? 'true' : undefined"
              :aria-describedby="
                reasonMessages.length > 0 ? 'prl-movement-reason-errors' : undefined
              "
            >
              <div v-for="code in REASONS" :key="code" class="flex items-center gap-2">
                <RadioGroupItem :id="`prl-movement-reason-${code}`" :value="code" />
                <Label :for="`prl-movement-reason-${code}`" class="min-h-11 flex-1">
                  {{ t(`prl.reason.${code}`) }}
                </Label>
              </div>
            </RadioGroup>
            <ul
              v-if="reasonMessages.length > 0"
              id="prl-movement-reason-errors"
              role="alert"
              class="text-destructive text-xs"
            >
              <li v-for="message in reasonMessages" :key="message">{{ message }}</li>
            </ul>
          </fieldset>

          <div class="flex flex-col gap-1.5">
            <Label for="prl-movement-notes">{{ t('prl.movement.notes') }}</Label>
            <Textarea
              id="prl-movement-notes"
              v-model="notes"
              maxlength="500"
              :aria-invalid="notesMessages.length > 0 ? 'true' : undefined"
              :aria-describedby="
                notesMessages.length > 0
                  ? 'prl-movement-notes-count prl-movement-notes-errors'
                  : 'prl-movement-notes-count'
              "
            />
            <p id="prl-movement-notes-count" class="text-muted-foreground text-xs">
              {{ t('prl.movement.notesCount', { count: notes.length }) }}
            </p>
            <ul
              v-if="notesMessages.length > 0"
              id="prl-movement-notes-errors"
              role="alert"
              class="text-destructive text-xs"
            >
              <li v-for="message in notesMessages" :key="message">{{ message }}</li>
            </ul>
          </div>
        </template>

        <div v-if="locked === 'rejected'" role="alert" class="flex flex-col gap-2">
          <p class="text-destructive text-sm font-medium">{{ rejectedMessage }}</p>
          <div class="flex flex-wrap gap-2">
            <Button type="button" variant="outline" class="min-h-11" @click="reloadList">
              {{ t('prl.door.reload') }}
            </Button>
            <Button
              v-if="canAlert"
              type="button"
              variant="outline"
              class="min-h-11"
              @click="alertInstead"
            >
              {{ t('prl.movement.openAlert') }}
            </Button>
          </div>
        </div>
        <p v-else-if="generalMessage" role="alert" class="text-destructive text-sm">
          {{ generalMessage }}
        </p>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            class="min-h-11"
            @click="emit('update:open', false)"
          >
            {{ t('prl.movement.cancel') }}
          </Button>
          <Button type="submit" class="min-h-11" :disabled="busy || locked !== null">
            {{ t('prl.movement.submit') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
