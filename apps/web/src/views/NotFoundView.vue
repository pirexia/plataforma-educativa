<script setup lang="ts">
/**
 * `docs/modulos/REQ-CORE/funcional.md §12.6`, `CA-CORE-096`. *Catch-all*:
 * se pinta dentro del *shell* con sesión y en régimen público sin ella
 * (`App.vue` decide el *layout*); este componente es el mismo en los dos
 * casos.
 */
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'

const t = useT()
</script>

<template>
  <div class="mx-auto flex max-w-xl flex-col items-center gap-3 py-16 text-center">
    <h1 class="text-2xl font-semibold">{{ t('shell.states.error.notFound.title') }}</h1>
    <p class="text-muted-foreground">{{ t('shell.states.error.notFound.text') }}</p>
    <Button as-child class="mt-2 min-h-11">
      <RouterLink :to="{ name: 'home' }">{{ t('core.nav.home') }}</RouterLink>
    </Button>
  </div>
</template>
