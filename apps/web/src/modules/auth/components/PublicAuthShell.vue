<script setup lang="ts">
/**
 * funcional.md §1.6: envoltorio común de las pantallas públicas, con la
 * disposición «Panel» de `ADR-064 §2` (`capa-visual.md §5.4`): región
 * visual a la izquierda (imagen del centro o ilustración, con bienvenida
 * genérica sobre un velo) y panel de acceso a la derecha, a toda la altura;
 * por debajo de `md`, una franja superior y el panel debajo, sin sombra.
 * Sin `AppLayout` (sin navegación, a propósito: ninguna depende del
 * *layout* de 1.8). Pinta nombre, logo y fondo de `useTenantBranding()`
 * (capa B, `@/tenant`); no recibe `branding` por *prop* (`CA-UI-021`,
 * `docs/design-system.md` §13.1).
 *
 * El tema del centro es **global** (`ADR-052 §1`): la capa A aplica
 * `--brand-primary`/`--brand-primary-foreground` sobre `<html>` y el
 * panel los hereda como cualquier otro elemento. Los usos del color de
 * marca sobre el fondo (el enlace de recuperación de `LoginView`, entre
 * otros) usan `text-primary-on-background` (`RUX-BRAND-006`).
 *
 * El selector de idioma del pie es público (sin sesión): no reutiliza
 * `AppUserMenu`, que exige sesión. Cambia el idioma con `setLocale()` (elección explícita), que
 * guarda `plataforma.locale`; la precedencia de las pantallas siguientes la
 * resuelve `usePublicAuthScreen` (`RN-UI-15`).
 */
import { computed, type CSSProperties } from 'vue'
import { i18n, localeFromDomain, setLocale, useT, type DomainLocale } from '@/i18n'
import { useTenantBranding } from '@/tenant/useTenantBranding'

const t = useT()
const { branding, status, reportAssetError } = useTenantBranding()

/** `RN-UI-10`: imagen del centro como fondo CSS, con la ilustración debajo. */
const centerImageStyle = computed<CSSProperties>(() => {
  if (!branding.value?.login_background_url) {
    return {}
  }

  return {
    backgroundImage: `url(${JSON.stringify(branding.value.login_background_url)})`,
    backgroundSize: 'cover',
    backgroundPosition: 'center',
  }
})

const hasCenterImage = computed(() => Boolean(branding.value?.login_background_url))

/** `loading`: superficie neutra, ni imagen ni ilustración (`RN-UI-10`). */
const showIllustration = computed(() => status.value !== 'loading')

/** `RN-UI-14`: solo los idiomas activos del centro; con uno solo, nada. */
const languages = computed(() => {
  const active = branding.value?.active_locales ?? []

  if (active.length < 2) {
    return []
  }

  return active.map((domain) => {
    const code = localeFromDomain(domain)

    return {
      domain,
      locale: code,
      label: code.toUpperCase(),
      nameKey: domain === 'es-ES' ? 'esES' : domain,
    }
  })
})

const activeCode = computed(() => i18n.global.locale.value)

function chooseLanguage(domain: DomainLocale): void {
  setLocale(localeFromDomain(domain))
}

/**
 * §7.4: si el logo falla al cargar (URL firmada caducada en una sesión
 * larga), se informa a la capa B para que renueve `GET /tenant/branding`.
 * La imagen de fondo es CSS, sin evento `error` nativo: la ilustración de
 * debajo evita el hueco (`RN-UI-10`).
 */
function onLogoError(): void {
  if (branding.value?.logo_url) {
    reportAssetError(branding.value.logo_url)
  }
}
</script>

<template>
  <div class="bg-background md:grid md:min-h-svh md:grid-cols-[1fr_var(--auth-panel-width)]">
    <!-- Región visual: sin ningún elemento enfocable (WCAG 1.3.2, 2.4.3). -->
    <aside
      :aria-label="t('auth.shell.visualLabel')"
      data-testid="auth-visual"
      :class="[
        'relative flex min-h-[var(--auth-visual-min-height)] flex-col justify-end overflow-hidden md:min-h-svh',
        status === 'loading' ? 'bg-muted' : 'bg-primary text-primary-foreground',
      ]"
    >
      <div
        v-if="showIllustration"
        data-testid="auth-illustration"
        aria-hidden="true"
        class="absolute inset-0 bg-[url('/auth/illustration.svg')] bg-cover bg-center"
      />
      <div
        v-if="hasCenterImage"
        data-testid="auth-center-image"
        aria-hidden="true"
        class="absolute inset-0"
        :style="centerImageStyle"
      />

      <!-- `RN-UI-12`: degradado solo por encima de la primera línea; placa sólida tras todo el texto. -->
      <div class="relative">
        <div aria-hidden="true" class="to-scrim-auth h-12 bg-gradient-to-b from-transparent" />
        <div class="bg-scrim-auth text-on-scrim px-6 pb-6 md:px-10 md:pb-10">
          <p
            data-testid="auth-welcome-title"
            class="font-heading text-xl font-semibold md:text-3xl"
          >
            {{ t('auth.shell.welcomeTitle') }}
          </p>
          <p data-testid="auth-welcome-text" class="mt-2 hidden text-base md:block">
            {{ t('auth.shell.welcomeText') }}
          </p>
        </div>
      </div>
    </aside>

    <div class="bg-background relative md:shadow-auth-panel">
      <main class="flex min-h-full flex-col px-6 py-8 md:min-h-svh md:py-10">
        <div class="mx-auto flex w-full max-w-sm flex-1 flex-col">
          <header v-if="branding?.logo_url || branding?.name" class="mb-8 flex items-center gap-3">
            <img
              v-if="branding?.logo_url"
              :src="branding.logo_url"
              alt=""
              class="h-10 w-auto"
              @error="onLogoError"
            />
            <span v-if="branding?.name" class="font-heading text-lg font-semibold">{{
              branding.name
            }}</span>
          </header>

          <div class="my-auto">
            <slot />
          </div>

          <footer class="mt-8 flex flex-col gap-3">
            <nav v-if="languages.length > 0" :aria-label="t('auth.shell.languageNav')">
              <ul class="flex flex-wrap gap-1">
                <li v-for="language in languages" :key="language.domain">
                  <button
                    type="button"
                    :lang="language.locale"
                    :aria-label="t(`shell.userMenu.language.names.${language.nameKey}`)"
                    :aria-current="activeCode === language.locale ? 'true' : undefined"
                    :class="[
                      'focus-visible:border-ring focus-visible:ring-ring/50 inline-flex outline-none min-h-6 min-w-6 items-center justify-center rounded-md border border-transparent px-2 text-sm focus-visible:ring-3 pointer-coarse:min-h-11 pointer-coarse:min-w-11',
                      activeCode === language.locale
                        ? 'font-semibold underline underline-offset-4'
                        : 'text-muted-foreground',
                    ]"
                    @click="chooseLanguage(language.domain)"
                  >
                    {{ language.label }}
                  </button>
                </li>
              </ul>
            </nav>
            <p class="text-muted-foreground text-sm">
              {{ t('auth.shell.poweredBy', { platform: t('auth.shell.platformName') }) }}
            </p>
          </footer>
        </div>
      </main>
    </div>
  </div>
</template>
