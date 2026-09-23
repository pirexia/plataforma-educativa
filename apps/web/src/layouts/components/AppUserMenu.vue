<script setup lang="ts">
/**
 * `docs/modulos/REQ-CORE/funcional.md §12.3.6`, `§12.3.7`, `§12.3.8`,
 * `CA-CORE-120` a `CA-CORE-124`. Menú de usuario: cuenta (enlaces a
 * contraseña, sesiones, seguridad), selector de idioma, control de modo
 * de color, cerrar sesión. En régimen `bare` (`minimal`), solo cerrar
 * sesión (`§12.3.5`).
 */
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { CircleUser, LogOut, Monitor, Moon, Sun } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useColorScheme, type ColorModePreference } from '@/design-system/color-mode/useColorScheme'
import { useTenantBranding } from '@/tenant/useTenantBranding'
import { logout } from '@/modules/auth/api'
import { ApiError } from '@/api/client'
import { useT, setLocale, localeFromDomain, type DomainLocale } from '@/i18n'
import { clearSession, updateSessionLocale, useSession } from '@/session/useSession'

const props = defineProps<{ minimal?: boolean }>()

const t = useT()
const router = useRouter()
const { user } = useSession()
const { branding } = useTenantBranding()
const { preference, setPreference } = useColorScheme()

const displayName = computed(() => {
  const person = user.value?.person
  return person ? `${person.given_name} ${person.family_name_1}` : ''
})

const activeLocales = computed<DomainLocale[]>(() => branding.value?.active_locales ?? [])
const currentDomainLocale = computed<DomainLocale>(() => user.value?.person.locale ?? 'es-ES')

const LOCALE_NAME_KEYS: Record<DomainLocale, string> = {
  'es-ES': 'shell.userMenu.language.names.esES',
  en: 'shell.userMenu.language.names.en',
  de: 'shell.userMenu.language.names.de',
  fr: 'shell.userMenu.language.names.fr',
}

const localeError = ref<string | null>(null)
const changingLocale = ref(false)

async function onLocaleSelected(value: unknown): Promise<void> {
  const domainLocale = value as DomainLocale
  if (domainLocale === currentDomainLocale.value) return

  changingLocale.value = true
  localeError.value = null

  try {
    const updated = await updateSessionLocale(domainLocale)
    setLocale(localeFromDomain(updated.person.locale as DomainLocale))
  } catch (err) {
    if (err instanceof ApiError && err.status === 422) {
      const body = err.body as { detail?: string } | null
      localeError.value = body?.detail ?? t('shell.localeChangeError')
    } else {
      localeError.value = t('shell.localeChangeError')
    }
  } finally {
    changingLocale.value = false
  }
}

function onColorModeSelected(value: unknown): void {
  setPreference(value as ColorModePreference)
}

async function onLogout(): Promise<void> {
  try {
    await logout()
  } catch {
    // RN-CORE-27: se vacía el estado igualmente aunque falle la petición.
  } finally {
    clearSession()
    await router.push({ name: 'login' })
  }
}
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button
        type="button"
        variant="ghost"
        class="min-h-11 min-w-11 gap-2"
        :aria-label="t('shell.userMenu.trigger')"
      >
        <CircleUser class="size-5" aria-hidden="true" />
        <span v-if="!props.minimal" class="max-w-32 truncate" :title="displayName">{{
          displayName
        }}</span>
      </Button>
    </DropdownMenuTrigger>

    <DropdownMenuContent align="end" class="w-64">
      <DropdownMenuLabel class="truncate" :title="displayName">{{ displayName }}</DropdownMenuLabel>

      <template v-if="!props.minimal">
        <DropdownMenuSeparator />

        <DropdownMenuItem as-child class="min-h-11">
          <RouterLink :to="{ name: 'password-change' }">{{
            t('auth.nav.passwordChange')
          }}</RouterLink>
        </DropdownMenuItem>
        <DropdownMenuItem as-child class="min-h-11">
          <RouterLink :to="{ name: 'sessions' }">{{ t('auth.nav.sessions') }}</RouterLink>
        </DropdownMenuItem>
        <DropdownMenuItem as-child class="min-h-11">
          <RouterLink :to="{ name: 'mfa-security' }">{{ t('auth.mfa.security.title') }}</RouterLink>
        </DropdownMenuItem>

        <DropdownMenuSeparator />

        <DropdownMenuLabel>{{ t('shell.userMenu.language.label') }}</DropdownMenuLabel>
        <DropdownMenuRadioGroup
          :model-value="currentDomainLocale"
          @update:model-value="onLocaleSelected"
        >
          <DropdownMenuRadioItem
            v-for="locale in activeLocales"
            :key="locale"
            :value="locale"
            class="min-h-11"
            :disabled="changingLocale"
          >
            {{ t(LOCALE_NAME_KEYS[locale]) }}
          </DropdownMenuRadioItem>
        </DropdownMenuRadioGroup>
        <p v-if="localeError" role="alert" class="text-destructive px-1.5 py-1 text-xs">
          {{ localeError }}
        </p>

        <DropdownMenuSeparator />

        <DropdownMenuLabel>{{ t('shell.userMenu.colorMode.label') }}</DropdownMenuLabel>
        <DropdownMenuRadioGroup :model-value="preference" @update:model-value="onColorModeSelected">
          <DropdownMenuRadioItem value="system" class="min-h-11">
            <Monitor class="size-4" aria-hidden="true" />
            {{ t('shell.userMenu.colorMode.system') }}
          </DropdownMenuRadioItem>
          <DropdownMenuRadioItem value="light" class="min-h-11">
            <Sun class="size-4" aria-hidden="true" />
            {{ t('shell.userMenu.colorMode.light') }}
          </DropdownMenuRadioItem>
          <DropdownMenuRadioItem value="dark" class="min-h-11">
            <Moon class="size-4" aria-hidden="true" />
            {{ t('shell.userMenu.colorMode.dark') }}
          </DropdownMenuRadioItem>
        </DropdownMenuRadioGroup>

        <DropdownMenuSeparator />
      </template>
      <template v-else>
        <DropdownMenuSeparator />
      </template>

      <DropdownMenuItem class="min-h-11" @select="onLogout">
        <LogOut class="size-4" aria-hidden="true" />
        {{ t('shell.userMenu.logout') }}
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
