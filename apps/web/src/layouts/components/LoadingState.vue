<script setup lang="ts">
/**
 * `docs/modulos/REQ-CORE/funcional.md §12.6`. `role="status"`,
 * `aria-busy="true"` en la región; esqueleto con los tokens de 1.7
 * (`bg-muted`, sin color literal).
 */
import { useT } from '@/i18n'

const props = defineProps<{ label?: string }>()
const t = useT()
</script>

<template>
  <div role="status" aria-busy="true" class="flex flex-col gap-3 py-6">
    <span class="sr-only">{{ props.label ?? t('shell.states.loading') }}</span>
    <div class="bg-muted h-4 w-3/4 animate-pulse rounded" aria-hidden="true" />
    <div class="bg-muted h-4 w-1/2 animate-pulse rounded" aria-hidden="true" />
    <div class="bg-muted h-4 w-2/3 animate-pulse rounded" aria-hidden="true" />
  </div>
</template>
