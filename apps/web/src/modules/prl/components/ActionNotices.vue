<script setup lang="ts">
/**
 * Resultado de una acción de una pantalla: el éxito, como `status` (región *live* `polite`, WCAG 4.1.3); el error, como
 * `alert` junto a la acción (`ADR-038 §6.3`). Ambos con texto, nunca solo color (WCAG 1.4.1). Duplicado del de `fam-unit`
 * (`AR-11`: un módulo no importa los internos de otro).
 */
defineProps<{ message: string | null; actionError: string | null }>()
</script>

<template>
  <div class="flex flex-col gap-3">
    <p
      v-if="message"
      role="status"
      class="border-border bg-muted rounded-lg border px-3 py-2 text-sm"
    >
      {{ message }}
    </p>
    <p
      v-if="actionError"
      role="alert"
      class="border-destructive text-destructive rounded-lg border px-3 py-2 text-sm"
    >
      {{ actionError }}
    </p>
  </div>
</template>
