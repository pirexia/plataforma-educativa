<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes['class']
  variant?: 'default' | 'destructive'
}>()
</script>

<template>
  <div
    data-slot="alert"
    :data-variant="props.variant ?? 'default'"
    :class="
      cn(
        'border-border bg-muted text-foreground flex flex-col gap-1 rounded-lg border px-3 py-2 text-sm',
        props.variant === 'destructive' && 'border-destructive text-destructive bg-background',
        props.class,
      )
    "
  >
    <slot />
  </div>
</template>
