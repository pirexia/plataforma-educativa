<script setup lang="ts">
/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.5.4` («Registrar la llamada / cerrar»), `DP-6`, `DP-11`, `CA-PRL-008`;
 * `1.14b-c2` T2. Solo se monta con `alerta_entrega.actualizar` y la alerta abierta. Cada acción del usuario produce
 * **exactamente un** `PATCH`: «Guardar la llamada» envía solo `principal_contacted_at`; «Cerrar la alerta» pide
 * confirmación («Una alerta cerrada no se puede reabrir») y envía `resolution` (+ `resolution_notes`). Ningún `PATCH`
 * por cambio de campo ni autoguardado: cada uno sobre alerta abierta escribe `read` y gasta cupo (`DP-11`). `409
 * alert.closed` ⇒ mensaje y «Recargar la alerta» (acción explícita); `429` ⇒ mensaje sin reintento.
 */
import { computed, ref } from 'vue'
import { useT } from '@/i18n'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useConfirm } from '@/components/useConfirm'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Textarea } from '@/components/ui/textarea'
import { updatePickupAlert } from '../api'
import { describeFailure, type SubmitFailure } from '../composables/submitError'
import type { PickupAlert, PickupAlertResolution, PickupAlertUpdate } from '../types'

const props = defineProps<{ alert: PickupAlert }>()
const emit = defineEmits<{ updated: [alert: PickupAlert]; reload: [] }>()

const t = useT()
const confirmation = useConfirm()

const RESOLUTIONS: PickupAlertResolution[] = [
  'no_entregado',
  'recogido_por_autorizado',
  'autorizacion_puntual_registrada',
  'otro',
]

/**
 * Margen con el que se resta a la hora local la propuesta (#521). El servidor rechaza una hora futura
 * (`422 prl.validation.contact_time_out_of_range`) y el reloj del cliente puede ir por delante del suyo; un minuto
 * absorbe una deriva razonable sin una petición más ni tocar el cliente HTTP compartido (la alternativa, la cabecera
 * `Date` de la API, exigiría exponer cabeceras de `apiFetch` fuera de este módulo). El campo es editable.
 */
const CLOCK_SKEW_MARGIN_MS = 60_000

/**
 * Valor para `<input type="datetime-local">` en hora local: `max(ahora - margen, raised_at)` con precisión de
 * milisegundos. El servidor rechaza una hora anterior a la apertura (o futura), así que la propuesta nunca precede a
 * `raised_at` (hora del servidor, nunca futura para él) ni se trunca por debajo de él (#518).
 */
function nowLocal(): string {
  const earlier = new Date(Date.now() - CLOCK_SKEW_MARGIN_MS)
  const raised = new Date(props.alert.raised_at)
  const candidate =
    !Number.isNaN(raised.getTime()) && raised.getTime() > earlier.getTime() ? raised : earlier
  const shifted = new Date(candidate.getTime() - candidate.getTimezoneOffset() * 60000)

  return shifted.toISOString().slice(0, 23)
}

const contactedAt = ref(nowLocal())
const resolution = ref<string>('')
const notes = ref('')
const busy = ref(false)
const failure = ref<SubmitFailure | null>(null)
const clientErrors = ref<Record<string, string>>({})
const closedConflict = ref(false)

function fieldMessages(field: string): string[] {
  const own = clientErrors.value[field]

  return [...(own ? [own] : []), ...(failure.value?.fields[field] ?? [])]
}

const contactMessages = computed(() => fieldMessages('principal_contacted_at'))
const resolutionMessages = computed(() => fieldMessages('resolution'))
const notesMessages = computed(() => fieldMessages('resolution_notes'))

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

  if (current.status === 422 && Object.keys(current.fields).length > 0) return null

  return current.message ?? t('prl.errors.unexpected')
})

async function send(payload: PickupAlertUpdate): Promise<void> {
  busy.value = true
  failure.value = null

  try {
    const updated = await updatePickupAlert(props.alert.public_id, payload)

    emit('updated', updated)
  } catch (err) {
    failure.value = describeFailure(err)
    closedConflict.value = failure.value.status === 409
  } finally {
    busy.value = false
  }
}

async function saveCall(): Promise<void> {
  if (busy.value) return

  clientErrors.value = {}
  closedConflict.value = false

  const date = new Date(contactedAt.value)

  if (contactedAt.value === '' || Number.isNaN(date.getTime())) {
    clientErrors.value = { principal_contacted_at: t('prl.alert.contactRequired') }

    return
  }

  await send({ principal_contacted_at: date.toISOString() })
}

async function closeAlert(): Promise<void> {
  if (busy.value) return

  clientErrors.value = {}
  closedConflict.value = false

  if (resolution.value === '') {
    clientErrors.value = { resolution: t('prl.alert.resolutionRequired') }

    return
  }

  const confirmed = await confirmation.ask({
    title: t('prl.alert.close'),
    description: t('prl.alert.confirmClose'),
    confirmLabel: t('prl.alert.close'),
    destructive: true,
  })

  if (!confirmed) return

  const payload: PickupAlertUpdate = { resolution: resolution.value as PickupAlertResolution }

  if (notes.value.trim() !== '') {
    payload.resolution_notes = notes.value.trim()
  }

  await send(payload)
}
</script>

<template>
  <section aria-labelledby="prl-alert-actions" class="flex flex-col gap-6">
    <h2 id="prl-alert-actions" class="text-base font-semibold">{{ t('prl.alert.actions') }}</h2>

    <div class="flex flex-col gap-2">
      <Label for="prl-alert-contacted-at">{{ t('prl.alert.principalContactedAt') }}</Label>
      <Input
        id="prl-alert-contacted-at"
        v-model="contactedAt"
        type="datetime-local"
        step="any"
        :aria-invalid="contactMessages.length > 0 ? 'true' : undefined"
        :aria-describedby="contactMessages.length > 0 ? 'prl-alert-contacted-errors' : undefined"
      />
      <ul
        v-if="contactMessages.length > 0"
        id="prl-alert-contacted-errors"
        role="alert"
        class="text-destructive text-xs"
      >
        <li v-for="message in contactMessages" :key="message">{{ message }}</li>
      </ul>
      <div>
        <Button type="button" variant="outline" class="min-h-11" :disabled="busy" @click="saveCall">
          {{ t('prl.alert.saveCall') }}
        </Button>
      </div>
    </div>

    <fieldset class="flex flex-col gap-3">
      <legend class="text-sm font-medium">{{ t('prl.alert.resolution') }}</legend>
      <RadioGroup
        v-model="resolution"
        :aria-invalid="resolutionMessages.length > 0 ? 'true' : undefined"
        :aria-describedby="
          resolutionMessages.length > 0 ? 'prl-alert-resolution-errors' : undefined
        "
      >
        <div v-for="code in RESOLUTIONS" :key="code" class="flex items-center gap-2">
          <RadioGroupItem :id="`prl-alert-resolution-${code}`" :value="code" />
          <Label :for="`prl-alert-resolution-${code}`" class="min-h-11 flex-1">
            {{ t(`prl.resolution.${code}`) }}
          </Label>
        </div>
      </RadioGroup>
      <ul
        v-if="resolutionMessages.length > 0"
        id="prl-alert-resolution-errors"
        role="alert"
        class="text-destructive text-xs"
      >
        <li v-for="message in resolutionMessages" :key="message">{{ message }}</li>
      </ul>

      <div class="flex flex-col gap-1.5">
        <Label for="prl-alert-resolution-notes">{{ t('prl.alert.resolutionNotes') }}</Label>
        <Textarea
          id="prl-alert-resolution-notes"
          v-model="notes"
          maxlength="1000"
          :aria-invalid="notesMessages.length > 0 ? 'true' : undefined"
          :aria-describedby="
            notesMessages.length > 0
              ? 'prl-alert-resolution-notes-count prl-alert-resolution-notes-errors'
              : 'prl-alert-resolution-notes-count'
          "
        />
        <p id="prl-alert-resolution-notes-count" class="text-muted-foreground text-xs">
          {{ t('prl.alert.notesCount', { count: notes.length }) }}
        </p>
        <ul
          v-if="notesMessages.length > 0"
          id="prl-alert-resolution-notes-errors"
          role="alert"
          class="text-destructive text-xs"
        >
          <li v-for="message in notesMessages" :key="message">{{ message }}</li>
        </ul>
      </div>

      <div>
        <Button type="button" class="min-h-11" :disabled="busy" @click="closeAlert">
          {{ t('prl.alert.close') }}
        </Button>
      </div>
    </fieldset>

    <div v-if="generalMessage" role="alert" class="flex flex-col gap-2">
      <p class="text-destructive text-sm">{{ generalMessage }}</p>
      <div v-if="closedConflict">
        <Button type="button" variant="outline" class="min-h-11" @click="emit('reload')">
          {{ t('prl.alert.reload') }}
        </Button>
      </div>
    </div>

    <ConfirmDialog
      :open="confirmation.open.value"
      :request="confirmation.request.value"
      @confirm="confirmation.confirm()"
      @cancel="confirmation.cancel()"
    />
  </section>
</template>
