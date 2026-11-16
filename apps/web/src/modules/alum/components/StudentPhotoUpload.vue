<script setup lang="ts">
/**
 * `docs/modulos/REQ-ALUM/funcional.md §10` (foto), `api.md §4` (`PUT /students/{id}/photo`), `RN-ALUM-51`,
 * `CA-ALUM-070`, `CA-ALUM-071`. Campo de fichero con comprobación **de ayuda** en cliente (JPEG o PNG y 5 MB: el
 * servidor manda, `INV-010`), **sin vista previa local**: `URL.createObjectURL` está vetado fuera de una lista cerrada de
 * excepciones (`CA-CORE-192`) y la especificación no pide previa. Todos los rechazos del servidor son `422` con el código en
 * `errors.file` (`file_required`, `type_unsupported`, `invalid_image`, `too_large`, todos bajo `alum.photo`) y se muestran junto al
 * campo con el mensaje traducido del servidor, sin cambiar nada más.
 */
import { computed, ref } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { problemDetail, problemFieldErrors } from '../composables/problem'

/** `RN-ALUM-51`: 5 MB y JPEG/PNG; solo ayuda en cliente. */
const MAX_BYTES = 5 * 1024 * 1024
const TYPES = ['image/jpeg', 'image/png']

const props = defineProps<{
  idPrefix: string
  studentName: string
  upload: (file: File) => Promise<unknown>
  submitLabel: string
}>()
const emit = defineEmits<{ uploaded: [] }>()

const t = useT()

const file = ref<File | null>(null)
const clientError = ref<string | null>(null)
const serverErrors = ref<string[]>([])
const busy = ref(false)

const errors = computed(() => (clientError.value ? [clientError.value] : serverErrors.value))
const fieldId = computed(() => `${props.idPrefix}-file`)

function onChange(event: Event): void {
  const chosen = (event.target as HTMLInputElement).files?.[0] ?? null

  serverErrors.value = []
  clientError.value = null
  file.value = null

  if (chosen === null) {
    return
  }

  if (chosen.size > MAX_BYTES) {
    clientError.value = t('alum.photo.tooLarge')

    return
  }

  if (!TYPES.includes(chosen.type)) {
    clientError.value = t('alum.photo.wrongType')

    return
  }

  file.value = chosen
}

async function submit(): Promise<void> {
  if (file.value === null) {
    return
  }

  busy.value = true
  serverErrors.value = []

  try {
    await props.upload(file.value)
    file.value = null
    emit('uploaded')
  } catch (err) {
    const fields = Object.values(problemFieldErrors(err)).flat()

    serverErrors.value =
      fields.length > 0 ? fields : [problemDetail(err) ?? t('alum.errors.unexpected')]
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <div class="flex flex-col gap-1.5">
      <Label :for="fieldId">{{ t('alum.photo.file') }}</Label>
      <Input
        :id="fieldId"
        type="file"
        accept="image/jpeg,image/png"
        :aria-invalid="errors.length > 0 ? 'true' : undefined"
        :aria-describedby="`${idPrefix}-hint${errors.length > 0 ? ` ${idPrefix}-errors` : ''}`"
        @change="onChange"
      />
      <p :id="`${idPrefix}-hint`" class="text-muted-foreground text-xs">
        {{ t('alum.photo.hint') }}
      </p>
      <ul
        v-if="errors.length > 0"
        :id="`${idPrefix}-errors`"
        role="alert"
        class="text-destructive text-xs"
      >
        <li v-for="message in errors" :key="message">{{ message }}</li>
      </ul>
    </div>

    <div>
      <Button type="button" size="sm" :disabled="busy || file === null" @click="submit">
        {{ submitLabel }}
      </Button>
    </div>
  </div>
</template>
