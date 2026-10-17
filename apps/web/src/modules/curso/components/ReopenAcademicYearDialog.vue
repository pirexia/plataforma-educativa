<script setup lang="ts">
/**
 * Diálogo de reapertura de un curso cerrado (`docs/modulos/REQ-CURSO/funcional.md
 * §4.6` paso 1, `CA-CURSO-087`, `ADR-059`). Pide el **motivo** (obligatorio) y
 * advierte de que (a) el curso vuelve a ser el activo y todos sus datos vuelven a
 * admitir escritura en todos los módulos, (b) no sirve para corregir un dato
 * concreto y (c) el motivo queda registrado y no debe contener datos personales.
 *
 * Con el motivo vacío (tras recortar) no emite `confirm`: muestra el error en el
 * propio campo y el foco vuelve a él. Cancelar, `Esc` o clic fuera emiten
 * `cancel` y no envían ninguna petición. Sobre `alert-dialog` de shadcn-vue
 * (Reka UI), como `ConfirmDialog`: modal, foco atrapado y devuelto al control de
 * origen (WCAG 2.4.3). La validación definitiva es del servidor (`INV-010`).
 */
import { nextTick, ref, useId, watch } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'

const props = defineProps<{ open: boolean; code: string }>()
const emit = defineEmits<{ confirm: [reason: string]; cancel: [] }>()

const t = useT()
const uid = useId()
const reasonId = `${uid}-reason`
const errorId = `${uid}-reason-error`

const reason = ref('')
const invalid = ref(false)

// Cada apertura empieza limpia: ni el motivo ni el error de una apertura anterior.
watch(
  () => props.open,
  (open) => {
    if (open) {
      reason.value = ''
      invalid.value = false
    }
  },
)

function onOpenChange(next: boolean): void {
  if (!next) {
    emit('cancel')
  }
}

async function submit(): Promise<void> {
  const trimmed = reason.value.trim()

  if (trimmed === '') {
    invalid.value = true
    await nextTick()
    document.getElementById(reasonId)?.focus()

    return
  }

  invalid.value = false
  emit('confirm', trimmed)
}
</script>

<template>
  <AlertDialog :open="props.open" @update:open="onOpenChange">
    <AlertDialogContent data-slot="reopen-dialog" class="data-[size=default]:sm:max-w-md">
      <AlertDialogHeader>
        <AlertDialogTitle>{{ t('curso.reopen.title', { code: props.code }) }}</AlertDialogTitle>
        <AlertDialogDescription>{{ t('curso.reopen.description') }}</AlertDialogDescription>
      </AlertDialogHeader>

      <ul class="list-disc pl-5 text-sm">
        <li>{{ t('curso.reopen.warningWritable') }}</li>
        <li>{{ t('curso.reopen.warningNotCorrection') }}</li>
        <li>{{ t('curso.reopen.warningReason') }}</li>
      </ul>

      <div class="flex flex-col gap-1.5">
        <Label :for="reasonId">{{ t('curso.reopen.reasonLabel') }}</Label>
        <Textarea
          :id="reasonId"
          v-model="reason"
          required
          :aria-invalid="invalid ? 'true' : undefined"
          :aria-describedby="invalid ? errorId : undefined"
        />
        <p v-if="invalid" :id="errorId" role="alert" class="text-destructive text-sm">
          {{ t('curso.reopen.reasonRequired') }}
        </p>
      </div>

      <AlertDialogFooter>
        <AlertDialogCancel @click="emit('cancel')">{{
          t('shell.confirm.cancel')
        }}</AlertDialogCancel>
        <!-- Botón propio y no `AlertDialogAction`: ver el comentario de `ConfirmDialog`. -->
        <Button type="button" @click="submit">
          {{ t('curso.reopen.confirm', { code: props.code }) }}
        </Button>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
