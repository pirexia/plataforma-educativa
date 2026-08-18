<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <div class="flex min-h-svh flex-col">
    <header class="border-border border-b px-6 py-4">
      <span class="font-heading text-lg font-semibold">{{ t('layout.title') }}</span>
    </header>
    <main class="flex-1 px-6 py-8">
      <RouterView />
    </main>
  </div>
</template>
