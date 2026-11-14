<script setup lang="ts">
/**
 * `docs/modulos/REQ-CORE/funcional.md §12.5`, `§12.7`. Lista de
 * navegación agrupada por sección — reutilizada por la barra lateral
 * persistente (escritorio) y por el panel del `sheet` (tableta/móvil).
 * `entries` ya viene filtrada por visibilidad (`RN-CORE-23`): este
 * componente solo agrupa y pinta.
 */
import { computed } from 'vue'
import { useT } from '@/i18n'
import { SECTIONS } from '@/navigation/sections'
import type { NavigationEntry } from '@/navigation/types'

const props = defineProps<{ entries: readonly NavigationEntry[] }>()
const emit = defineEmits<{ navigate: [] }>()

const t = useT()

const groupedSections = computed(() =>
  SECTIONS.map((section) => ({
    ...section,
    entries: props.entries.filter((entry) => entry.section === section.id),
  })).filter((section) => section.entries.length > 0),
)
</script>

<template>
  <nav :aria-label="t('shell.nav.ariaLabel')" class="flex flex-col gap-1">
    <template v-for="section in groupedSections" :key="section.id">
      <p class="text-muted-foreground px-3 pt-4 pb-1 text-xs font-medium uppercase first:pt-0">
        {{ t(section.labelKey) }}
      </p>
      <RouterLink
        v-for="entry in section.entries"
        :key="entry.id"
        :to="{ name: entry.route }"
        class="hover:bg-muted focus-visible:border-ring flex min-h-11 items-center gap-2 rounded-md px-3 text-sm"
        active-class="bg-muted font-medium"
        @click="emit('navigate')"
      >
        <component :is="entry.icon" class="size-4 shrink-0" aria-hidden="true" />
        <span class="truncate">{{ t(entry.labelKey) }}</span>
      </RouterLink>
    </template>
  </nav>
</template>
