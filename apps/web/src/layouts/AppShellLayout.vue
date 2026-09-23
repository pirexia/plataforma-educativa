<script setup lang="ts">
/**
 * `docs/modulos/REQ-CORE/funcional.md §12.1.1` punto 2, `§12.7`. *Shell*
 * de aplicación: barra superior, navegación lateral persistente en
 * escritorio (`≥1024px`), *drawer* u hamburguesa por debajo (`RN-CORE-30`),
 * miga de pan, y la resolución de qué se pinta en el contenido principal
 * (vista real, «sin acceso», «módulo no disponible» o error de sesión a
 * pantalla completa) — nunca las tres cosas a la vez, y la vista de
 * destino no se monta si no toca (`CA-CORE-095`).
 */
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { Menu } from '@lucide/vue'
import router from '@/router'
import { Button } from '@/components/ui/button'
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { useT } from '@/i18n'
import { useTenantBranding } from '@/tenant/useTenantBranding'
import { reloadSession, useSession } from '@/session/useSession'
import { hasAnyPermission, visibleNavigationEntries } from '@/navigation/registry'
import AppNavList from './components/AppNavList.vue'
import AppUserMenu from './components/AppUserMenu.vue'
import AppBreadcrumb from './components/AppBreadcrumb.vue'
import SkipLink from './components/SkipLink.vue'
import ErrorState from './components/ErrorState.vue'
import LoadingState from './components/LoadingState.vue'
import { sessionErrorToShellError } from './errorState'
import { useSessionLocaleSync } from './useSessionLocaleSync'
import { useViewFocusAndTitle } from './useViewFocusAndTitle'

const t = useT()
const route = useRoute()
const { user, status, error, moduleUnavailableDetail } = useSession()
const { branding, reportAssetError } = useTenantBranding()

useSessionLocaleSync()

const mainRef = ref<HTMLElement | null>(null)
useViewFocusAndTitle(mainRef)

const permissions = computed(() => user.value?.permissions ?? [])
const navEntries = computed(() => visibleNavigationEntries(router, permissions.value))

const drawerOpen = ref(false)

const forbidden = computed(() => {
  const required = route.meta.permissions

  if (!required || required.length === 0) {
    return false
  }

  return !hasAnyPermission(required, permissions.value)
})

const sessionErrorState = computed(() =>
  error.value ? sessionErrorToShellError(error.value) : null,
)

function retry(): void {
  void reloadSession()
}

function onLogoError(url: string): void {
  reportAssetError(url)
}
</script>

<template>
  <div class="bg-background text-foreground min-h-svh">
    <SkipLink />

    <header
      class="border-border bg-background sticky top-0 z-40 flex h-16 items-center gap-3 border-b px-4"
    >
      <Button
        type="button"
        variant="ghost"
        size="icon"
        class="min-h-11 min-w-11 lg:hidden"
        :aria-label="drawerOpen ? t('shell.closeMenu') : t('shell.openMenu')"
        :aria-expanded="drawerOpen"
        @click="drawerOpen = true"
      >
        <Menu aria-hidden="true" />
      </Button>

      <RouterLink
        :to="{ name: 'home' }"
        class="flex min-h-11 min-w-11 items-center gap-2 font-semibold"
      >
        <img
          v-if="branding?.logo_url"
          :src="branding.logo_url"
          :alt="branding.name"
          class="h-8 w-auto"
          @error="onLogoError(branding.logo_url)"
        />
        <span class="truncate" :title="branding?.name ?? t('layout.title')">
          {{ branding?.name ?? t('layout.title') }}
        </span>
      </RouterLink>

      <div class="ml-auto flex items-center gap-2">
        <AppUserMenu />
      </div>
    </header>

    <div class="mx-auto flex w-full max-w-7xl gap-6 px-4 py-4 lg:px-6">
      <aside class="hidden w-64 shrink-0 lg:block">
        <AppNavList :entries="navEntries" />
      </aside>

      <Sheet v-model:open="drawerOpen">
        <SheetContent
          side="left"
          :close-label="t('shell.sheetClose')"
          class="data-[side=left]:w-full data-[side=left]:sm:max-w-none md:data-[side=left]:w-80 md:data-[side=left]:max-w-xs lg:hidden"
        >
          <SheetHeader>
            <SheetTitle class="sr-only">{{ t('shell.nav.ariaLabel') }}</SheetTitle>
          </SheetHeader>
          <div class="overflow-y-auto px-2 pb-4">
            <AppNavList :entries="navEntries" @navigate="drawerOpen = false" />
          </div>
        </SheetContent>
      </Sheet>

      <main id="main-content" ref="mainRef" class="min-w-0 flex-1 outline-none">
        <AppBreadcrumb class="mb-4" />

        <LoadingState v-if="status === 'loading'" />
        <ErrorState
          v-else-if="moduleUnavailableDetail !== null"
          :state="{ kind: 'module-disabled', detail: moduleUnavailableDetail }"
          @retry="retry"
        />
        <ErrorState v-else-if="forbidden" :state="{ kind: 'forbidden' }" @retry="retry" />
        <ErrorState v-else-if="sessionErrorState" :state="sessionErrorState" @retry="retry" />
        <RouterView v-else v-slot="{ Component }">
          <Transition name="route" mode="out-in">
            <component :is="Component" />
          </Transition>
        </RouterView>
      </main>
    </div>
  </div>
</template>

<style scoped>
.route-enter-active,
.route-leave-active {
  transition: opacity var(--motion-duration-normal) ease;
}
.route-enter-from,
.route-leave-to {
  opacity: 0;
}
</style>
