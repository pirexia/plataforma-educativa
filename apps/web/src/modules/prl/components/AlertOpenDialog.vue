<script setup lang="ts">
/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.5.3` (fila «Abrir alerta»), `RN-PRL-08`, `-09`, `DP-5`, `DP-11`; `1.14b-c2`
 * T2. `POST /pickup-alerts` con `{ student_person, presenter_description }` (≤ 500, contador visible) y una
 * `Idempotency-Key` por intento. El alta **escribe `read` y gasta cupo** (`DP-11`): un `429` se muestra sin reintento y la
 * alerta ya creada no se pierde, así que se ofrece abrirla desde «Alertas de entrega» (si el usuario puede leerlas).
 */
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
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
import { createPickupAlert } from '../api'
import { describeFailure, type SubmitFailure } from '../composables/submitError'
import { useIdempotencyKey } from '../composables/useIdempotencyKey'
import { usePermissions } from '../composables/usePermissions'
import type { PickupAlert } from '../types'

const props = defineProps<{ open: boolean; studentPerson: string }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; raised: [alert: PickupAlert] }>()

const t = useT()
const { can } = usePermissions()
const idempotency = useIdempotencyKey()

const description = ref('')
const busy = ref(false)
const failure = ref<SubmitFailure | null>(null)
const clientError = ref<string | null>(null)
const mismatch = ref(false)

watch(
  () => props.open,
  (open) => {
    idempotency.reset()

    if (open) {
      description.value = ''
      failure.value = null
      clientError.value = null
      mismatch.value = false
      busy.value = false
    }
  },
  { immediate: true },
)

const fieldMessages = computed(() => [
  ...(clientError.value ? [clientError.value] : []),
  ...(failure.value?.fields.presenter_description ?? []),
])

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

async function submit(): Promise<void> {
  if (busy.value || mismatch.value) return

  failure.value = null
  clientError.value = null

  const text = description.value.trim()

  if (text === '') {
    clientError.value = t('prl.alert.presenterRequired')

    return
  }

  const payload = { student_person: props.studentPerson, presenter_description: text }

  busy.value = true

  try {
    const alert = await createPickupAlert(payload, idempotency.keyFor(payload))

    emit('raised', alert)
    emit('update:open', false)
  } catch (err) {
    failure.value = describeFailure(err)
    mismatch.value = failure.value.status === 409
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent :close-label="t('prl.form.close')" data-slot="prl-alert-open-dialog">
      <DialogHeader>
        <DialogTitle>{{ t('prl.door.notListed') }}</DialogTitle>
        <DialogDescription>{{ t('prl.alert.openDescription') }}</DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <div class="flex flex-col gap-1.5">
          <Label for="prl-alert-presenter">{{ t('prl.alert.presenterLabel') }}</Label>
          <Textarea
            id="prl-alert-presenter"
            v-model="description"
            maxlength="500"
            :aria-invalid="fieldMessages.length > 0 ? 'true' : undefined"
            :aria-describedby="
              fieldMessages.length > 0
                ? 'prl-alert-presenter-count prl-alert-presenter-errors'
                : 'prl-alert-presenter-count'
            "
          />
          <p id="prl-alert-presenter-count" class="text-muted-foreground text-xs">
            {{ t('prl.alert.presenterCount', { count: description.length }) }}
          </p>
          <ul
            v-if="fieldMessages.length > 0"
            id="prl-alert-presenter-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in fieldMessages" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div v-if="generalMessage" role="alert" class="flex flex-col gap-2">
          <p class="text-destructive text-sm">{{ generalMessage }}</p>
          <RouterLink
            v-if="failure?.status === 429 && can('alerta_entrega.leer')"
            :to="{ name: 'prl-alerts' }"
            class="text-primary-on-background inline-flex min-h-11 items-center text-sm underline underline-offset-4"
          >
            {{ t('prl.alert.openFromList') }}
          </RouterLink>
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            class="min-h-11"
            @click="emit('update:open', false)"
          >
            {{ t('prl.movement.cancel') }}
          </Button>
          <Button type="submit" class="min-h-11" :disabled="busy || mismatch">
            {{ t('prl.alert.open') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
