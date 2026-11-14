<script setup lang="ts">
/**
 * `docs/modulos/REQ-ALUM/funcional.md §10` (`REQ-ALUM-003`; `1.15-d` T2b), `RN-ALUM-42`, `RN-ALUM-43`, `RN-ALUM-44`,
 * `RN-ALUM-45`; `api.md §3` (`approval`, `rejection`, `cancellation`). Decide una solicitud de cambio de grupo
 * `pendiente`: aprobar y rechazar (`matricula.aprobar`) y cancelar (`matricula.actualizar`) con una nota de 1 a 500
 * caracteres; la nota es obligatoria solo al rechazar.
 *
 * Los errores del servidor (`403 self_approval`, `409 request_closed`, `409 enrollment_changed`, `422 decision_note_required`,
 * `academic-year-closed`) se pintan con su `detail` ya traducido, en el propio diálogo, que sigue abierto. Ante un `409`
 * se emite `stale` para que la pantalla recargue la lista (la solicitud ya no está como se vio). La validación de
 * negocio es del servidor (`INV-010`).
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
import { Textarea } from '@/components/ui/textarea'
import {
  approveGroupChangeRequest,
  cancelGroupChangeRequest,
  rejectGroupChangeRequest,
} from '../api'
import { problemDetail, problemFieldErrors, problemStatus } from '../composables/problem'
import type { GroupChangeDecisionMode, GroupChangeRequest } from '../types'
import FieldErrors from './FieldErrors.vue'

const MAX_NOTE = 500

const props = defineProps<{
  open: boolean
  mode: GroupChangeDecisionMode
  request: GroupChangeRequest | null
}>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: []; stale: [] }>()

const t = useT()

const note = ref('')
const busy = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)

watch(
  () => props.open,
  (open) => {
    if (!open) return

    note.value = ''
    fieldErrors.value = {}
    formError.value = null
  },
  { immediate: true },
)

const noteRequired = computed(() => props.mode === 'reject')
const noteErrors = computed(() => fieldErrors.value.decision_note ?? [])
const otherErrors = computed(() =>
  Object.entries(fieldErrors.value)
    .filter(([field]) => field !== 'decision_note')
    .flatMap(([, messages]) => messages),
)
const noteDescribedBy = computed(() =>
  ['alum-gcd-note-hint', noteErrors.value.length > 0 ? 'alum-gcd-note-errors' : '']
    .filter((id) => id !== '')
    .join(' '),
)
const canSubmit = computed(
  () => !busy.value && props.request !== null && (!noteRequired.value || note.value.trim() !== ''),
)

async function submit(): Promise<void> {
  const target = props.request

  if (!target) return

  fieldErrors.value = {}
  formError.value = null
  busy.value = true

  const trimmed = note.value.trim()
  const body = trimmed === '' ? {} : { decision_note: trimmed }

  try {
    if (props.mode === 'approve') {
      await approveGroupChangeRequest(target.public_id, body)
    } else if (props.mode === 'reject') {
      await rejectGroupChangeRequest(target.public_id, body)
    } else {
      await cancelGroupChangeRequest(target.public_id, body)
    }

    emit('saved')
    emit('update:open', false)
  } catch (err) {
    fieldErrors.value = problemFieldErrors(err)
    formError.value =
      problemDetail(err) ??
      (Object.keys(fieldErrors.value).length === 0 ? t('alum.errors.unexpected') : null)

    if (problemStatus(err) === 409) emit('stale')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent :close-label="t('alum.form.close')" data-slot="alum-group-change-decision">
      <DialogHeader>
        <DialogTitle>{{ t(`alum.groupChanges.${mode}.title`) }}</DialogTitle>
        <DialogDescription>
          {{
            t(`alum.groupChanges.${mode}.description`, {
              student: request?.enrollment.student.display_name ?? '',
              from: request?.from_group?.name ?? t('alum.common.dash'),
              to: request?.to_group?.name ?? t('alum.common.dash'),
            })
          }}
        </DialogDescription>
      </DialogHeader>

      <form class="flex max-h-[70vh] flex-col gap-4 overflow-y-auto" @submit.prevent="submit">
        <div class="flex flex-col gap-1.5">
          <Label for="alum-gcd-note">{{ t('alum.groupChanges.note') }}</Label>
          <Textarea
            id="alum-gcd-note"
            v-model="note"
            :required="noteRequired"
            :maxlength="MAX_NOTE"
            rows="3"
            :aria-invalid="noteErrors.length > 0 ? 'true' : undefined"
            :aria-describedby="noteDescribedBy"
          />
          <p id="alum-gcd-note-hint" class="text-muted-foreground text-xs">
            {{
              noteRequired
                ? t('alum.groupChanges.noteHintRequired', { max: MAX_NOTE })
                : t('alum.groupChanges.noteHintOptional', { max: MAX_NOTE })
            }}
          </p>
          <FieldErrors id="alum-gcd-note-errors" :messages="noteErrors" />
        </div>

        <ul v-if="otherErrors.length > 0" role="alert" class="text-destructive text-sm">
          <li v-for="message in otherErrors" :key="message">{{ message }}</li>
        </ul>
        <p v-if="formError" role="alert" class="text-destructive text-sm">{{ formError }}</p>

        <DialogFooter>
          <Button type="button" variant="outline" @click="emit('update:open', false)">
            {{ t('alum.form.cancel') }}
          </Button>
          <Button
            type="submit"
            :variant="mode === 'approve' ? 'default' : 'destructive'"
            :disabled="!canSubmit"
          >
            {{ t(`alum.groupChanges.${mode}.submit`) }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
