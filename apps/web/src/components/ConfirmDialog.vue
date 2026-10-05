<script setup lang="ts">
/**
 * `docs/modulos/REQ-CORE/funcional.md §14.14`, `RN-CORE-64`, `OPEN-CORE-42`
 * (= A): el único componente de aplicación de confirmación, sobre
 * `alert-dialog` de shadcn-vue (Reka UI): diálogo modal con foco atrapado,
 * `Esc` cancela, el foco entra en el diálogo al abrirlo y vuelve al control
 * que lo abrió al cerrarlo (la gestión de foco es de Reka UI, no escrita a
 * mano). Sustituye a `window.confirm` y a las confirmaciones en línea.
 *
 * El texto lo aporta quien lo usa (`useConfirm().ask`): nombra la entidad
 * afectada y la consecuencia; el botón de confirmar lleva la identidad de la
 * fila en su nombre accesible.
 */
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import type { ConfirmRequest } from './useConfirm'

const props = defineProps<{ open: boolean; request: ConfirmRequest | null }>()
const emit = defineEmits<{ confirm: []; cancel: [] }>()

const t = useT()

function onOpenChange(next: boolean): void {
  if (!next) {
    emit('cancel')
  }
}
</script>

<template>
  <AlertDialog :open="props.open" @update:open="onOpenChange">
    <AlertDialogContent data-slot="confirm-dialog">
      <AlertDialogHeader>
        <AlertDialogTitle>{{ props.request?.title }}</AlertDialogTitle>
        <AlertDialogDescription>{{ props.request?.description }}</AlertDialogDescription>
      </AlertDialogHeader>
      <ul
        v-if="props.request?.details && props.request.details.length > 0"
        data-slot="confirm-dialog-details"
        class="max-h-60 list-disc overflow-y-auto pl-5 text-sm"
      >
        <li v-for="detail in props.request.details" :key="detail">{{ detail }}</li>
      </ul>
      <AlertDialogFooter>
        <AlertDialogCancel @click="emit('cancel')">
          {{ props.request?.cancelLabel ?? t('shell.confirm.cancel') }}
        </AlertDialogCancel>
        <!--
          Botón propio y no `AlertDialogAction`: el de Reka UI es también un
          cierre del diálogo, y su `update:open(false)` competiría con el
          `confirm` (que cerraría como «cancelar» si llegara antes). Así solo
          decide `useConfirm`, y el foco vuelve igual al control de origen al
          desmontarse el contenido.
        -->
        <Button
          type="button"
          :variant="props.request?.destructive ? 'destructive' : 'default'"
          @click="emit('confirm')"
        >
          {{ props.request?.confirmLabel }}
        </Button>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
