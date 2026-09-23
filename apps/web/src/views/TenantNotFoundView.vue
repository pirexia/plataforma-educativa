<script setup lang="ts">
/**
 * `docs/modulos/REQ-CORE/funcional.md §12.6`, `RN-CORE-35`. Host sin
 * tenant: sin *shell*, sin `GET /me`. Régimen `public`
 * (`src/router/index.ts`).
 */
import { useT } from '@/i18n'

const t = useT()
</script>

<template>
  <div
    class="mx-auto flex min-h-svh max-w-xl flex-col items-center justify-center gap-3 px-4 text-center"
  >
    <h1 class="text-2xl font-semibold">{{ t('shell.tenantNotFound.title') }}</h1>
    <p class="text-muted-foreground">{{ t('shell.tenantNotFound.text') }}</p>
  </div>
</template>
