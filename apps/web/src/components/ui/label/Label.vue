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
    :class="cn('text-sm leading-none font-medium select-none', props.class)"
  >
    <slot />
  </label>
</template>
