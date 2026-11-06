<script setup lang="ts">
/**
 * `funcional.md §23.4.3` punto 3 (`RN-FAM-UNIT-84`, `D-57`). Fotografía, tras el `201` de la autorización. Sin
 * `autorizado_recogida.actualizar` no se ofrece subida: se explica que la autorización queda pendiente (`photo.pendingByOther`).
 * Se puede cerrar sin subirla: la autorización queda no vigente y la tabla ofrece «Subir fotografía» en su fila. Si la
 * persona ya tenía fotografía, se ofrece conservarla o sustituirla.
 */
import { ref } from 'vue'
import { useT } from '@/i18n'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { putPickupPersonPhoto } from '../api'
import PickupPhotoUpload from './PickupPhotoUpload.vue'

const props = defineProps<{
  canUpdate: boolean
  personPublicId: string
  personName: string
  hasPhoto: boolean
}>()
const emit = defineEmits<{ finish: [uploaded: boolean] }>()

const t = useT()
const replacing = ref(!props.hasPhoto)
const upload = ref<{ reset: () => void } | null>(null)

defineExpose({ reset: () => upload.value?.reset() })
</script>

<template>
  <div class="flex flex-col gap-4">
    <template v-if="!canUpdate">
      <Alert role="status">
        <AlertDescription>{{ t('famUnit.pickup.photo.pendingByOther') }}</AlertDescription>
      </Alert>
      <div>
        <Button type="button" @click="emit('finish', false)">{{
          t('famUnit.pickup.wizard.finish')
        }}</Button>
      </div>
    </template>

    <template v-else>
      <p class="text-sm">{{ t('famUnit.pickup.photo.stepNotice') }}</p>

      <div v-if="hasPhoto && !replacing" class="flex flex-wrap gap-2">
        <Button type="button" @click="emit('finish', false)">{{
          t('famUnit.pickup.photo.keep')
        }}</Button>
        <Button type="button" variant="outline" @click="replacing = true">
          {{ t('famUnit.pickup.photo.replace') }}
        </Button>
      </div>

      <template v-else>
        <PickupPhotoUpload
          ref="upload"
          id-prefix="fam-unit-pickup-photo-step"
          :person-name="personName"
          :submit-label="t('famUnit.pickup.photo.upload')"
          :upload="(file: File) => putPickupPersonPhoto(personPublicId, file)"
          @uploaded="emit('finish', true)"
        />
        <div>
          <Button type="button" variant="outline" @click="emit('finish', false)">
            {{ t('famUnit.pickup.wizard.skipPhoto') }}
          </Button>
        </div>
      </template>
    </template>
  </div>
</template>
