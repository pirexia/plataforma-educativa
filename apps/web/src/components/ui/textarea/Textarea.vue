<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

/**
 * REQ-AUTH-004 (1.4c). Primitiva `Textarea` de shadcn-vue, ausente hasta
 * ahora (ningún formulario anterior necesitaba varias líneas): la
 * necesita el alta de un proveedor SAML por XML pegado
 * (`funcional.md §G.9`) y la carga manual de un certificado del IdP
 * (`api.md §G.5`). Mismos tokens y estados que `Input.vue` — mismo
 * design system, sin mezclar librerías (`CLAUDE.md §1`).
 */
defineProps<{
  class?: HTMLAttributes['class']
}>()

const model = defineModel<string>()
</script>

<template>
  <textarea
    v-model="model"
    data-slot="textarea"
    :class="
      cn(
        'border-border bg-background flex min-h-24 w-full rounded-lg border px-2.5 py-1.5 text-sm shadow-xs transition-[color,box-shadow] outline-none',
        'placeholder:text-muted-foreground',
        'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-3',
        'aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive',
        'disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50',
        $props.class,
      )
    "
  />
</template>
