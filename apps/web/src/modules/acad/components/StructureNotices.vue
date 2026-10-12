<script setup lang="ts">
/**
 * `RN-ACAD-42`, `CA-ACAD-048`, `CA-ACAD-050`: avisos de una pantalla de la
 * estructura. El aviso de solo lectura es **persistente y de texto** (no solo
 * color, WCAG 1.4.1); el resultado de una acción, `status`; los errores, `alert`
 * junto a la acción, con la lista de dependientes de un `409 has_dependents`.
 */
import { useT } from '@/i18n'

defineProps<{
  readOnly: boolean
  message: string | null
  actionError: string | null
  dependents: string[]
}>()

const t = useT()
</script>

<template>
  <div class="flex flex-col gap-3">
    <p
      v-if="readOnly"
      role="note"
      data-slot="acad-read-only"
      class="border-border bg-muted rounded-lg border px-3 py-2 text-sm"
    >
      {{ t('acad.readOnly') }}
    </p>

    <p
      v-if="message"
      role="status"
      class="border-border bg-muted rounded-lg border px-3 py-2 text-sm"
    >
      {{ message }}
    </p>

    <div v-if="actionError" role="alert" class="text-destructive flex flex-col gap-1 text-sm">
      <p>{{ actionError }}</p>
      <template v-if="dependents.length > 0">
        <p class="font-medium">{{ t('acad.errors.dependentsTitle') }}</p>
        <ul class="list-disc pl-5">
          <li v-for="dependent in dependents" :key="dependent">{{ dependent }}</li>
        </ul>
      </template>
    </div>
  </div>
</template>
