<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

const props = defineProps<{
  for?: string
  class?: HTMLAttributes['class']
}>()
</script>

<template>
  <label
    :for="props.for"
    data-slot="label"
    :class="
      cn(
        // `OPEN-CORE-14` (opción B): una `<label>` asociada activa su
        // control al pulsarla — en punteros gruesos, su caja mínima sube
        // a 44px de alto sin cambiar el escritorio con ratón.
        'text-sm leading-none font-medium select-none [@media(any-pointer:coarse)]:inline-flex [@media(any-pointer:coarse)]:min-h-11 [@media(any-pointer:coarse)]:items-center',
        props.class,
      )
    "
  >
    <slot />
  </label>
</template>
