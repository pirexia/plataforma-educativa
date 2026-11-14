<script setup lang="ts">
/**
 * `docs/modulos/REQ-CORE/funcional.md §12.1.1` punto 1, `§12.3.1`,
 * `CA-CORE-096`, `RN-CORE-35`. Decide qué *layout* envuelve la ruta
 * actual:
 *
 * - Host sin tenant (`branding.status === 'not-found'`): `centro no
 *   encontrado` es en sí una ruta de régimen `public` (`src/router/index.ts`)
 *   a la que el *guard* ya redirige — así que basta con respetar
 *   `route.meta.layout` en ese caso también.
 * - `not-found` (*catch-all*, `CA-CORE-096`): régimen dinámico —
 *   `app` con sesión, `public` sin ella — porque la misma ruta se pinta
 *   distinto según haya sesión o no.
 * - Cualquier otra ruta: `route.meta.layout`, tal cual lo declara su
 *   `shell.ts` (`ADR-053 §3`).
 */
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useSession } from '@/session/useSession'
import PublicLayout from '@/layouts/PublicLayout.vue'
import AppShellLayout from '@/layouts/AppShellLayout.vue'
import BareLayout from '@/layouts/BareLayout.vue'

const route = useRoute()
const { status: sessionStatus } = useSession()

const effectiveLayout = computed<'public' | 'app' | 'bare'>(() => {
  if (route.name === 'not-found') {
    const hasSession = sessionStatus.value !== 'anonymous' && sessionStatus.value !== 'idle'
    return hasSession ? 'app' : 'public'
  }

  return route.meta.layout ?? 'public'
})
</script>

<template>
  <PublicLayout v-if="effectiveLayout === 'public'" />
  <BareLayout v-else-if="effectiveLayout === 'bare'" />
  <AppShellLayout v-else />
</template>
