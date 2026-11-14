<script setup lang="ts">
/**
 * `docs/modulos/REQ-CORE/funcional.md §12.4`. Panel de inicio: tres
 * bloques fijos, en este orden — bienvenida, estado de la cuenta (aviso
 * de MFA), accesos directos. Ninguno pide un *endpoint* propio: `/me` y
 * `/tenant/branding` ya están cargados por el *router guard* y `main.ts`
 * (`RN-CORE-33`).
 */
import { computed } from 'vue'
import { Inbox } from '@lucide/vue'
import router from '@/router'
import { useT } from '@/i18n'
import { useSession } from '@/session/useSession'
import { useTenantBranding } from '@/tenant/useTenantBranding'
import { visibleShortcuts } from '@/navigation/registry'
import EmptyState from '@/layouts/components/EmptyState.vue'

const t = useT()
const { user } = useSession()
const { branding, reportAssetError } = useTenantBranding()

const givenName = computed(() => user.value?.person.given_name ?? '')
const tenantName = computed(() => branding.value?.name ?? '')

const mfaNotice = computed(() => {
  const mfa = user.value?.mfa

  if (!mfa || !mfa.obligated || mfa.enrolled) {
    return null
  }

  return mfa
})

const permissions = computed(() => user.value?.permissions ?? [])
const shortcuts = computed(() => visibleShortcuts(router, permissions.value))

function onLogoError(url: string): void {
  reportAssetError(url)
}
</script>

<template>
  <div class="flex flex-col gap-8">
    <section aria-labelledby="home-greeting">
      <div class="flex items-center gap-4">
        <img
          v-if="branding?.logo_url"
          :src="branding.logo_url"
          :alt="tenantName"
          class="h-12 w-auto"
          @error="onLogoError(branding.logo_url)"
        />
        <div>
          <h1 id="home-greeting" class="text-2xl font-semibold">
            {{ t('home.greeting', { name: givenName }) }}
          </h1>
          <p v-if="tenantName" class="text-muted-foreground">{{ tenantName }}</p>
        </div>
      </div>
    </section>

    <section
      v-if="mfaNotice"
      aria-labelledby="home-mfa-title"
      class="border-warning/40 bg-warning/10 rounded-lg border p-4"
    >
      <h2 id="home-mfa-title" class="font-medium">{{ t('home.mfa.title') }}</h2>
      <p class="text-sm">
        {{
          t('home.mfa.text', { days: mfaNotice.days_remaining ?? 0 }, mfaNotice.days_remaining ?? 0)
        }}
      </p>
      <RouterLink
        :to="{ name: 'mfa-security' }"
        class="text-primary-on-background mt-2 inline-flex min-h-11 items-center underline-offset-4 hover:underline"
      >
        {{ t('home.mfa.link') }}
      </RouterLink>
    </section>

    <section aria-labelledby="home-shortcuts-title">
      <h2 id="home-shortcuts-title" class="mb-3 text-lg font-medium">
        {{ t('home.shortcuts.title') }}
      </h2>

      <EmptyState
        v-if="shortcuts.length === 0"
        :icon="Inbox"
        :title="t('shell.states.empty.shortcuts.title')"
        :text="t('shell.states.empty.shortcuts.text')"
      />
      <ul v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <li v-for="entry in shortcuts" :key="entry.id">
          <RouterLink
            :to="{ name: entry.route }"
            class="border-border hover:bg-muted flex min-h-11 items-center gap-2 rounded-lg border p-3 text-sm"
          >
            <component :is="entry.icon" class="size-5 shrink-0" aria-hidden="true" />
            <span class="truncate">{{ t(entry.labelKey) }}</span>
          </RouterLink>
        </li>
      </ul>
    </section>
  </div>
</template>
