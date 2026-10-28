<script setup lang="ts">
/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §10`. Lectura del título y el texto de una versión (`GET /terms-versions/{id}`
 * ya trae el contenido resuelto en el idioma del usuario). El texto es contenido del centro (`ADR-062`): se pinta como
 * **texto**, nunca como HTML, en una región con nombre y alcanzable con el teclado (WCAG 2.1.1, 4.1.2).
 */
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
import type { TermsVersion } from '../types'

defineProps<{ open: boolean; version: TermsVersion | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean] }>()

const t = useT()
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent
      :close-label="t('famUnit.form.close')"
      class="sm:max-w-2xl"
      data-slot="fam-unit-terms-text"
    >
      <DialogHeader>
        <DialogTitle>{{ version?.title ?? '' }}</DialogTitle>
        <DialogDescription>{{ t('famUnit.terms.text.description') }}</DialogDescription>
      </DialogHeader>

      <div
        class="border-border max-h-96 overflow-y-auto rounded-md border p-4 text-sm whitespace-pre-wrap"
        tabindex="0"
        role="region"
        :aria-label="t('famUnit.terms.text.label')"
      >
        {{ version?.body ?? '' }}
      </div>

      <DialogFooter>
        <Button type="button" @click="emit('update:open', false)">
          {{ t('famUnit.form.close') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
