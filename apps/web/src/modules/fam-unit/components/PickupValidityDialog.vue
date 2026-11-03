<script setup lang="ts">
/**
 * `funcional.md §23.4.2` (fila «Cambiar fecha de fin»), `D-61`. `PATCH /pickup-authorizations/{id}` con
 * `{ valid_until }` (fecha o `null` = sin fecha de fin), solo para una `permanente` no retirada. `409 pickup.withdrawn`
 * (la retiró otra persona entre medias): mensaje del servidor en el diálogo, sin aplicar el cambio, y se **ofrece**
 * recargar la tabla (acción explícita, nunca sola). `422` (`pickup.not_editable`, `validation.*`) junto al campo.
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
import { problemDetail, problemFieldErrors, problemStatus } from '../composables/problem'
import type { PickupAuthorization } from '../types'

const props = defineProps<{
  open: boolean
  authorization: PickupAuthorization | null
  save: (validUntil: string | null) => Promise<unknown>
}>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: []; reload: [] }>()

const t = useT()

const validUntil = ref('')
const busy = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)
const withdrawn = ref(false)

watch(
  () => props.open,
  (open) => {
    if (open) {
      validUntil.value = props.authorization?.valid_until ?? ''
      fieldErrors.value = {}
      formError.value = null
      withdrawn.value = false
    }
  },
  { immediate: true },
)

const untilErrors = computed(() => fieldErrors.value.valid_until ?? [])

async function submit(): Promise<void> {
  fieldErrors.value = {}
  formError.value = null
  busy.value = true

  try {
    await props.save(validUntil.value === '' ? null : validUntil.value)
    emit('saved')
    emit('update:open', false)
  } catch (err) {
    withdrawn.value = problemStatus(err) === 409
    fieldErrors.value = problemFieldErrors(err)
    formError.value =
      problemDetail(err) ??
      (Object.keys(fieldErrors.value).length === 0 ? t('famUnit.errors.unexpected') : null)
  } finally {
    busy.value = false
  }
}

function reload(): void {
  emit('reload')
  emit('update:open', false)
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent :close-label="t('famUnit.form.close')" data-slot="fam-unit-pickup-validity">
      <DialogHeader>
        <DialogTitle>
          {{
            t('famUnit.pickup.validityDialog.title', {
              person: authorization?.person.display_name ?? '',
            })
          }}
        </DialogTitle>
        <DialogDescription>{{ t('famUnit.pickup.validityDialog.description') }}</DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="flex flex-col gap-1.5">
          <Label for="fam-unit-pickup-valid-until">{{
            t('famUnit.pickup.authorization.validUntil')
          }}</Label>
          <Input
            id="fam-unit-pickup-valid-until"
            v-model="validUntil"
            type="date"
            :aria-invalid="untilErrors.length > 0 ? 'true' : undefined"
            :aria-describedby="
              untilErrors.length > 0 ? 'fam-unit-pickup-valid-until-errors' : undefined
            "
          />
          <ul
            v-if="untilErrors.length > 0"
            id="fam-unit-pickup-valid-until-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in untilErrors" :key="message">{{ message }}</li>
          </ul>
        </div>

        <p v-if="formError" role="alert" class="text-destructive text-sm">{{ formError }}</p>
        <div v-if="withdrawn">
          <Button type="button" variant="outline" size="sm" @click="reload">
            {{ t('famUnit.pickup.states.reloadTable') }}
          </Button>
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" @click="emit('update:open', false)">
            {{ t('famUnit.form.cancel') }}
          </Button>
          <Button type="submit" :disabled="busy">{{ t('famUnit.form.save') }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
