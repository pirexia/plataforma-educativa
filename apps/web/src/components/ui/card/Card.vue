<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

const props = defineProps<{ class?: HTMLAttributes['class'] }>()
</script>

<template>
  <div
    data-slot="card"
    :class="
      cn(
        'bg-card text-card-foreground border-border flex flex-col gap-3 rounded-lg border p-4',
        props.class,
      )
    "
  >
    <slot />
  </div>
</template>
