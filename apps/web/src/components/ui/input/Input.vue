<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

defineProps<{
  class?: HTMLAttributes['class']
}>()

const model = defineModel<string>()
</script>

<template>
  <input
    v-model="model"
    data-slot="input"
    :class="
      cn(
        'border-border bg-background flex h-8 w-full min-w-0 rounded-lg border px-2.5 py-1 text-sm shadow-xs transition-[color,box-shadow] outline-none',
        'placeholder:text-muted-foreground',
        'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-3',
        'aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive',
        'disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50',
        $props.class,
      )
    "
  />
</template>
