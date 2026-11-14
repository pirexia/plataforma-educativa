<script setup lang="ts">
/** `docs/modulos/REQ-CORE/funcional.md §12.7`, `RUX-004`, `CA-CORE-085`. */
import { useT } from '@/i18n'

const t = useT()
</script>

<template>
  <a
    href="#main-content"
    class="focus-visible:border-ring sr-only focus-visible:not-sr-only focus-visible:fixed focus-visible:top-2 focus-visible:left-2 focus-visible:z-100 focus-visible:bg-background focus-visible:text-foreground focus-visible:inline-flex focus-visible:min-h-11 focus-visible:min-w-11 focus-visible:items-center focus-visible:rounded-md focus-visible:border focus-visible:px-4 focus-visible:py-2"
  >
    {{ t('shell.skipToContent') }}
  </a>
</template>
