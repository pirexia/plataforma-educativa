<script setup lang="ts">
/**
 * `docs/modulos/REQ-CORE/funcional.md §12.3.5`. Régimen `bare`: con
 * sesión, sin navegación, sin miga de pan, sin más opción de menú de
 * usuario que cerrar sesión — hoy solo lo usa `mfa-enrollment-wall`.
 */
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useSession, reloadSession } from '@/session/useSession'
import { hasAnyPermission } from '@/navigation/registry'
import AppUserMenu from './components/AppUserMenu.vue'
import SkipLink from './components/SkipLink.vue'
import ErrorState from './components/ErrorState.vue'
import LoadingState from './components/LoadingState.vue'
import { sessionErrorToShellError } from './errorState'
import { useSessionLocaleSync } from './useSessionLocaleSync'
import { useViewFocusAndTitle } from './useViewFocusAndTitle'

const route = useRoute()
const { user, status, error, moduleUnavailableDetail } = useSession()

useSessionLocaleSync()

const mainRef = ref<HTMLElement | null>(null)
useViewFocusAndTitle(mainRef)

const permissions = computed(() => user.value?.permissions ?? [])

const forbidden = computed(() => !hasAnyPermission(route.meta.permissions, permissions.value))

const sessionErrorState = computed(() =>
  error.value ? sessionErrorToShellError(error.value) : null,
)

function retry(): void {
  void reloadSession()
}
</script>

<template>
  <div class="bg-background text-foreground min-h-svh">
    <SkipLink />

    <header class="border-border bg-background flex h-16 items-center justify-end border-b px-4">
      <AppUserMenu minimal />
    </header>

    <main id="main-content" ref="mainRef" class="mx-auto max-w-2xl px-4 py-8 outline-none">
      <LoadingState v-if="status === 'loading'" />
      <ErrorState
        v-else-if="moduleUnavailableDetail !== null"
        :state="{ kind: 'module-disabled', detail: moduleUnavailableDetail }"
        @retry="retry"
      />
      <ErrorState v-else-if="forbidden" :state="{ kind: 'forbidden' }" @retry="retry" />
      <ErrorState v-else-if="sessionErrorState" :state="sessionErrorState" @retry="retry" />
      <RouterView v-else />
    </main>
  </div>
</template>
