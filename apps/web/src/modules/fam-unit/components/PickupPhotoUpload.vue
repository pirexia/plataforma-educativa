<script setup lang="ts">
/**
 * `funcional.md §23.4.2` (fila «Subir / sustituir fotografía»), `§23.4.3` punto 3, UI-3/UI-7, `D-57`,
 * `OPEN-FAM-UNIT-60` A. Campo de fichero con comprobación **de ayuda** en cliente (tipo declarado JPEG/PNG/WebP y 5 MB
 * fijos: el servidor manda) y vista previa local con `URL.createObjectURL`, que se libera al cambiar de fichero y al
 * desmontar (la URL de objeto no sale del componente). `413`/`415`/`422 fam_unit.photo.type_mismatch`/
 * `fam_unit.photo.dimensions` se muestran junto al campo con el mensaje del servidor, sin cambiar nada más.
 */
import { computed, onBeforeUnmount, ref } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { problemDetail, problemFieldErrors } from '../composables/problem'

/** `D-57`: 5 MB y tipos fijos; solo ayuda en cliente (`OPEN-FAM-UNIT-60` A). */
const MAX_BYTES = 5 * 1024 * 1024
const TYPES = ['image/jpeg', 'image/png', 'image/webp']

const props = defineProps<{
  idPrefix: string
  personName: string
  upload: (file: File) => Promise<unknown>
  submitLabel: string
}>()
const emit = defineEmits<{ uploaded: [] }>()

const t = useT()

const file = ref<File | null>(null)
const previewUrl = ref<string | null>(null)
const clientError = ref<string | null>(null)
const serverErrors = ref<string[]>([])
const busy = ref(false)

const errors = computed(() => (clientError.value ? [clientError.value] : serverErrors.value))
const fieldId = computed(() => `${props.idPrefix}-file`)

function releasePreview(): void {
  if (previewUrl.value !== null) {
    URL.revokeObjectURL(previewUrl.value)
    previewUrl.value = null
  }
}

function onChange(event: Event): void {
  const chosen = (event.target as HTMLInputElement).files?.[0] ?? null

  releasePreview()
  serverErrors.value = []
  clientError.value = null
  file.value = null

  if (chosen === null) {
    return
  }

  if (chosen.size > MAX_BYTES) {
    clientError.value = t('famUnit.pickup.photo.tooLarge')

    return
  }

  if (!TYPES.includes(chosen.type)) {
    clientError.value = t('famUnit.pickup.photo.wrongType')

    return
  }

  file.value = chosen
  previewUrl.value = URL.createObjectURL(chosen)
}

async function submit(): Promise<void> {
  if (file.value === null) {
    return
  }

  busy.value = true
  serverErrors.value = []

  try {
    await props.upload(file.value)
    releasePreview()
    file.value = null
    emit('uploaded')
  } catch (err) {
    const fields = Object.values(problemFieldErrors(err)).flat()

    serverErrors.value =
      fields.length > 0 ? fields : [problemDetail(err) ?? t('famUnit.errors.unexpected')]
  } finally {
    busy.value = false
  }
}

/** Libera la vista previa (al cerrar el diálogo). */
function reset(): void {
  releasePreview()
  file.value = null
  clientError.value = null
  serverErrors.value = []
}

onBeforeUnmount(releasePreview)
defineExpose({ reset })
</script>

<template>
  <div class="flex flex-col gap-3">
    <div class="flex flex-col gap-1.5">
      <Label :for="fieldId">{{ t('famUnit.pickup.photo.file') }}</Label>
      <Input
        :id="fieldId"
        type="file"
        accept="image/jpeg,image/png,image/webp"
        :aria-invalid="errors.length > 0 ? 'true' : undefined"
        :aria-describedby="`${idPrefix}-hint${errors.length > 0 ? ` ${idPrefix}-errors` : ''}`"
        @change="onChange"
      />
      <p :id="`${idPrefix}-hint`" class="text-muted-foreground text-xs">
        {{ t('famUnit.pickup.photo.hint') }}
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

    <img
      v-if="previewUrl"
      :src="previewUrl"
      :alt="t('famUnit.pickup.photo.alt', { person: personName })"
      referrerpolicy="no-referrer"
      draggable="false"
      class="size-24 rounded-md object-cover"
    />

    <div>
      <Button type="button" size="sm" :disabled="busy || file === null" @click="submit">
        {{ submitLabel }}
      </Button>
    </div>
  </div>
</template>
