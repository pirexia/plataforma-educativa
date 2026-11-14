<script setup lang="ts">
/**
 * `docs/modulos/REQ-CORE/funcional.md §14.9`, `RN-CORE-65`: un campo de texto
 * (o numérico) de la configuración del centro con su etiqueta, la marca de
 * obligatorio no solo visual (`CA-CORE-260`), la pista opcional y los
 * mensajes del servidor bajo el campo (`aria-invalid` + `aria-describedby`).
 * Es presentación: la validación decisiva es del servidor (`INV-010`).
 */
import { computed } from 'vue'
import { useT } from '@/i18n'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import FormFieldError from './FormFieldError.vue'

const props = defineProps<{
  id: string
  label: string
  modelValue: string
  required?: boolean
  maxlength?: number
  type?: 'text' | 'number' | 'email' | 'tel'
  min?: number
  max?: number
  hint?: string
  errors?: readonly string[]
  placeholder?: string
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const t = useT()

const hasErrors = computed(() => (props.errors?.length ?? 0) > 0)
const describedBy = computed(() => {
  const ids = [props.hint ? `${props.id}-hint` : null, hasErrors.value ? `${props.id}-error` : null]

  const present = ids.filter(Boolean)

  return present.length > 0 ? present.join(' ') : undefined
})
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <Label :for="id">
      {{ label }}
      <template v-if="required">
        <span aria-hidden="true">*</span>
        <span class="sr-only">{{ t('core.settings.required') }}</span>
      </template>
    </Label>
    <Input
      :id="id"
      :model-value="modelValue"
      :type="type ?? 'text'"
      :maxlength="maxlength"
      :min="min"
      :max="max"
      :required="required"
      :placeholder="placeholder"
      autocomplete="off"
      :aria-invalid="hasErrors ? true : undefined"
      :aria-describedby="describedBy"
      @update:model-value="
        (value: string | number | undefined) => emit('update:modelValue', String(value ?? ''))
      "
    />
    <p v-if="hint" :id="`${id}-hint`" class="text-muted-foreground text-xs">{{ hint }}</p>
    <FormFieldError :id="`${id}-error`" :messages="errors" />
  </div>
</template>
