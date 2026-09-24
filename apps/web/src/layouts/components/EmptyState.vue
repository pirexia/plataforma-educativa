<script setup lang="ts">
/**
 * `docs/modulos/REQ-CORE/funcional.md §12.6`, `RUX-006`. Icono decorativo
 * + título + texto + acción opcional. `actionLabel`/`action` en vez de un
 * *slot* libre: así se garantiza que el botón de acción, si lo hay,
 * cumple `RN-CORE-32` (44×44 px, "botones de los estados").
 */
import type { Component } from 'vue'
import { Button } from '@/components/ui/button'

const props = defineProps<{
  icon: Component
  title: string
  text: string
  actionLabel?: string
}>()

const emit = defineEmits<{ action: [] }>()
</script>

<template>
  <div class="flex flex-col items-center gap-2 py-8 text-center">
    <component :is="props.icon" class="text-muted-foreground size-8" aria-hidden="true" />
    <p class="text-sm font-medium">{{ props.title }}</p>
    <p class="text-muted-foreground text-sm">{{ props.text }}</p>
    <Button
      v-if="actionLabel"
      type="button"
      variant="outline"
      class="mt-2 min-h-11"
      @click="emit('action')"
    >
      {{ actionLabel }}
    </Button>
  </div>
</template>
