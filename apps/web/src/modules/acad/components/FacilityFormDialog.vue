<script setup lang="ts">
/**
 * `docs/modulos/REQ-ACAD/funcional.md §18.8` («alta/edición en diálogo»),
 * `§18.4.3` (`RN-ACAD-100` a `-103`). Formulario de alta y edición de un espacio:
 * tipo (`aula` o `recurso`, **inmutable** tras el alta, `RN-ACAD-100`), código y
 * nombre (texto simple, no traducible, `RN-ACAD-101`). En la edición solo viajan
 * las claves que cambian (`ADR-038 §9.2`) y el tipo se muestra sin poder
 * cambiarse. Los errores del servidor ya vienen traducidos (`ADR-038 §6.3`) y se
 * muestran junto al campo; cualquier otro, junto a la acción.
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
import { problemDetail, problemFieldErrors } from '../composables/problem'
import { useLabels } from '../composables/useLabels'
import type { Facility } from '../types'

/** `maxLength` de `code` y `name` (`StructureLimits::MAX_TEXT_LENGTH`). */
const MAX_TEXT_LENGTH = 255
const FACILITY_TYPES = ['aula', 'recurso']

const props = defineProps<{
  open: boolean
  /** `null` = alta. */
  facility: Facility | null
  save: (body: Record<string, unknown>) => Promise<unknown>
}>()

const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const t = useT()
const { facilityTypeLabel } = useLabels()

const mode = computed(() => (props.facility === null ? 'create' : 'edit'))

const facilityType = ref('')
const code = ref('')
const name = ref('')

const busy = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)

function reset(): void {
  facilityType.value = props.facility?.facility_type ?? ''
  code.value = props.facility?.code ?? ''
  name.value = props.facility?.name ?? ''
  fieldErrors.value = {}
  formError.value = null
}

watch(
  () => [props.open, props.facility?.public_id],
  () => {
    if (props.open) {
      reset()
    }
  },
  { immediate: true },
)

/** `ADR-038 §7.3`: un tipo no anticipado se ofrece con su valor en crudo. */
const typeOptions = computed(() => {
  const current = facilityType.value
  const values =
    current !== '' && !FACILITY_TYPES.includes(current)
      ? [...FACILITY_TYPES, current]
      : FACILITY_TYPES

  return values.map((value) => ({ value, label: facilityTypeLabel(value) }))
})

/** Errores de campos que el formulario no pinta: se muestran junto a la acción. */
const otherErrors = computed(() =>
  Object.entries(fieldErrors.value)
    .filter(([field]) => !['facility_type', 'code', 'name'].includes(field))
    .flatMap(([, messages]) => messages),
)

/** Cuerpo: completo en el alta, solo las claves modificadas en la edición. */
function buildBody(): Record<string, unknown> {
  const facility = props.facility
  const body: Record<string, unknown> = {}

  if (facility === null) {
    body.facility_type = facilityType.value
    body.code = code.value.trim()
    body.name = name.value.trim()

    return body
  }

  const currentCode = facility.code

  if (code.value.trim() !== currentCode) body.code = code.value.trim()
  if (name.value.trim() !== facility.name) body.name = name.value.trim()

  return body
}

async function submit(): Promise<void> {
  fieldErrors.value = {}
  formError.value = null

  const body = buildBody()

  if (props.facility !== null && Object.keys(body).length === 0) {
    emit('update:open', false)

    return
  }

  busy.value = true

  try {
    await props.save(body)
    emit('saved')
    emit('update:open', false)
  } catch (err) {
    fieldErrors.value = problemFieldErrors(err)
    formError.value =
      problemDetail(err) ??
      (Object.keys(fieldErrors.value).length === 0 ? t('acad.errors.unexpected') : null)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent :close-label="t('acad.form.close')" data-slot="acad-facility-form">
      <DialogHeader>
        <DialogTitle>{{ t(`acad.facilities.form.title.${mode}`) }}</DialogTitle>
        <DialogDescription>{{ t('acad.form.description') }}</DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="flex flex-col gap-1.5">
          <Label for="acad-facility-type">{{ t('acad.fields.facilityType') }}</Label>
          <Select v-model="facilityType" required :disabled="facility !== null">
            <SelectTrigger
              id="acad-facility-type"
              :aria-invalid="fieldErrors.facility_type ? 'true' : undefined"
              :aria-describedby="
                facility !== null
                  ? 'acad-facility-type-hint'
                  : fieldErrors.facility_type
                    ? 'acad-facility-type-errors'
                    : undefined
              "
            >
              <SelectValue :placeholder="t('acad.form.choose')" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="option in typeOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </SelectItem>
            </SelectContent>
          </Select>
          <p
            v-if="facility !== null"
            id="acad-facility-type-hint"
            class="text-muted-foreground text-xs"
          >
            {{ t('acad.facilities.form.typeImmutable') }}
          </p>
          <ul
            v-if="fieldErrors.facility_type"
            id="acad-facility-type-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in fieldErrors.facility_type" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="acad-facility-code">{{ t('acad.fields.code') }}</Label>
          <Input
            id="acad-facility-code"
            v-model="code"
            required
            :maxlength="MAX_TEXT_LENGTH"
            :aria-invalid="fieldErrors.code ? 'true' : undefined"
            :aria-describedby="fieldErrors.code ? 'acad-facility-code-errors' : undefined"
          />
          <ul
            v-if="fieldErrors.code"
            id="acad-facility-code-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in fieldErrors.code" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="acad-facility-name">{{ t('acad.fields.name') }}</Label>
          <Input
            id="acad-facility-name"
            v-model="name"
            required
            :maxlength="MAX_TEXT_LENGTH"
            :aria-invalid="fieldErrors.name ? 'true' : undefined"
            :aria-describedby="fieldErrors.name ? 'acad-facility-name-errors' : undefined"
          />
          <ul
            v-if="fieldErrors.name"
            id="acad-facility-name-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in fieldErrors.name" :key="message">{{ message }}</li>
          </ul>
        </div>

        <ul v-if="otherErrors.length > 0" role="alert" class="text-destructive text-sm">
          <li v-for="message in otherErrors" :key="message">{{ message }}</li>
        </ul>
        <p v-if="formError" role="alert" class="text-destructive text-sm">{{ formError }}</p>

        <DialogFooter>
          <Button type="button" variant="outline" @click="emit('update:open', false)">
            {{ t('acad.form.cancel') }}
          </Button>
          <Button type="submit" :disabled="busy">{{ t('acad.form.save') }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
