<script setup lang="ts">
/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §4.1`, `§10` (`REQ-FAM-UNIT-001`). Alta de una unidad familiar: el código es
 * opcional, y si se deja vacío lo genera el sistema (`OPEN-FAM-UNIT-06`). Los errores del servidor ya vienen
 * traducidos (`ADR-038 §6.3`): `code_taken` y `code_invalid` junto al campo; cualquier otro, junto a la acción.
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
import { problemDetail, problemFieldErrors } from '../composables/problem'

const props = defineProps<{ open: boolean; save: (body: { code?: string }) => Promise<unknown> }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const t = useT()

const code = ref('')
const busy = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)

watch(
  () => props.open,
  (open) => {
    if (open) {
      code.value = ''
      fieldErrors.value = {}
      formError.value = null
    }
  },
  { immediate: true },
)

const otherErrors = computed(() =>
  Object.entries(fieldErrors.value)
    .filter(([field]) => field !== 'code')
    .flatMap(([, messages]) => messages),
)

async function submit(): Promise<void> {
  fieldErrors.value = {}
  formError.value = null
  busy.value = true

  try {
    const trimmed = code.value.trim()

    await props.save(trimmed === '' ? {} : { code: trimmed })
    emit('saved')
    emit('update:open', false)
  } catch (err) {
    fieldErrors.value = problemFieldErrors(err)
    formError.value =
      problemDetail(err) ??
      (Object.keys(fieldErrors.value).length === 0 ? t('famUnit.errors.unexpected') : null)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent :close-label="t('famUnit.form.close')" data-slot="fam-unit-unit-form">
      <DialogHeader>
        <DialogTitle>{{ t('famUnit.units.form.title') }}</DialogTitle>
        <DialogDescription>{{ t('famUnit.units.form.description') }}</DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="flex flex-col gap-1.5">
          <Label for="fam-unit-code">{{ t('famUnit.fields.code') }}</Label>
          <Input
            id="fam-unit-code"
            v-model="code"
            :maxlength="64"
            :aria-invalid="fieldErrors.code ? 'true' : undefined"
            :aria-describedby="
              fieldErrors.code ? 'fam-unit-code-hint fam-unit-code-errors' : 'fam-unit-code-hint'
            "
          />
          <p id="fam-unit-code-hint" class="text-muted-foreground text-xs">
            {{ t('famUnit.units.form.codeHint') }}
          </p>
          <ul
            v-if="fieldErrors.code"
            id="fam-unit-code-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in fieldErrors.code" :key="message">{{ message }}</li>
          </ul>
        </div>

        <ul v-if="otherErrors.length > 0" role="alert" class="text-destructive text-sm">
          <li v-for="message in otherErrors" :key="message">{{ message }}</li>
        </ul>
        <p v-if="formError" role="alert" class="text-destructive text-sm">{{ formError }}</p>

        <DialogFooter>
          <Button type="button" variant="outline" @click="emit('update:open', false)">
            {{ t('famUnit.form.cancel') }}
          </Button>
          <Button type="submit" :disabled="busy">{{ t('famUnit.form.create') }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
