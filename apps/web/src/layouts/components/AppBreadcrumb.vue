<script setup lang="ts">
/**
 * `docs/modulos/REQ-CORE/funcional.md §12.5`, `RUX-003`, `CA-CORE-088`.
 * `nav` con `aria-label`; el último elemento sin enlace y con
 * `aria-current="page"`; los anteriores, enlaces a su ruta.
 */
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { ChevronRight } from '@lucide/vue'
import { useT } from '@/i18n'
import { findNavigationEntryForRoute } from '@/navigation/registry'

const route = useRoute()
const t = useT()

interface Crumb {
  label: string
  to: { name: string } | null
}

const crumbs = computed<Crumb[]>(() => {
  const items: Crumb[] = []
  const name = String(route.name ?? '')

  if (name !== 'home') {
    items.push({ label: t('core.nav.home'), to: { name: 'home' } })
  }

  const parentName = route.meta.breadcrumbParent

  if (parentName) {
    const parentEntry = findNavigationEntryForRoute(parentName)
    items.push({
      label: parentEntry ? t(parentEntry.labelKey) : parentName,
      to: { name: parentName },
    })
  }

  const ownEntry = findNavigationEntryForRoute(name)
  const label = route.meta.breadcrumbKey
    ? t(route.meta.breadcrumbKey)
    : ownEntry
      ? t(ownEntry.labelKey)
      : route.meta.titleKey
        ? t(route.meta.titleKey)
        : name

  items.push({ label, to: null })

  return items
})
</script>

<template>
  <nav :aria-label="t('shell.breadcrumb.ariaLabel')">
    <ol class="flex flex-wrap items-center gap-0.5 text-sm">
      <li
        v-for="(crumb, index) in crumbs"
        :key="`${crumb.label}-${index}`"
        class="flex items-center gap-0.5"
      >
        <ChevronRight
          v-if="index > 0"
          class="text-muted-foreground size-3.5 shrink-0"
          aria-hidden="true"
        />
        <RouterLink
          v-if="crumb.to"
          :to="crumb.to"
          class="text-muted-foreground hover:text-foreground focus-visible:border-ring inline-flex min-h-11 items-center rounded-md px-2"
        >
          {{ crumb.label }}
        </RouterLink>
        <span v-else aria-current="page" class="inline-flex items-center px-2 font-medium">
          {{ crumb.label }}
        </span>
      </li>
    </ol>
  </nav>
</template>
