<script setup lang="ts">
/**
 * `funcional.md §23.4.1`/`§23.4.2`: subir o sustituir la fotografía de una persona desde su fila (`PUT
 * /pickup-persons/{p}/photo`, `autorizado_recogida.actualizar`). Reutiliza el campo de subida del paso 3 del asistente.
 * La vista previa se libera al cerrar.
 */
import { ref, watch } from 'vue'
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
import { putPickupPersonPhoto } from '../api'
import PickupPhotoUpload from './PickupPhotoUpload.vue'

const props = defineProps<{ open: boolean; personPublicId: string; personName: string }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const t = useT()
const upload = ref<{ reset: () => void } | null>(null)

watch(
  () => props.open,
  (open) => {
    if (!open) {
      upload.value?.reset()
    }
  },
)

function onUploaded(): void {
  emit('saved')
  emit('update:open', false)
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent :close-label="t('famUnit.form.close')" data-slot="fam-unit-pickup-photo">
      <DialogHeader>
        <DialogTitle>{{
          t('famUnit.pickup.photoDialog.title', { person: personName })
        }}</DialogTitle>
        <DialogDescription>{{ t('famUnit.pickup.photoDialog.description') }}</DialogDescription>
      </DialogHeader>

      <PickupPhotoUpload
        ref="upload"
        id-prefix="fam-unit-pickup-photo-dialog"
        :person-name="personName"
        :submit-label="t('famUnit.pickup.photo.replace')"
        :upload="(file: File) => putPickupPersonPhoto(personPublicId, file)"
        @uploaded="onUploaded"
      />

      <DialogFooter>
        <Button type="button" variant="outline" @click="emit('update:open', false)">
          {{ t('famUnit.form.cancel') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
