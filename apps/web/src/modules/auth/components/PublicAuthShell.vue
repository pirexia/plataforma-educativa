<script setup lang="ts">
/**
 * funcional.md §1.6: envoltorio común de las cinco pantallas públicas.
 * Sin `AppLayout` (sin navegación, a propósito: ninguna depende del
 * *layout* de 1.8). Pinta nombre, logo y fondo de `useTenantBranding()`
 * (capa B, `@/tenant`) — ya no recibe `branding` por *prop* (`CA-DS-045`,
 * `docs/design-system.md` §13.1).
 *
 * El tema del centro es ahora **global** (`ADR-052 §1`): la capa A aplica
 * `--brand-primary`/`--brand-primary-foreground` sobre `<html>` desde
 * `useTenantBranding`, y la tarjeta los hereda como cualquier otro
 * elemento — ya no liga `--primary`/`--primary-foreground` como `:style`
 * propio. Los usos del color de marca sobre el fondo (el enlace de
 * recuperación de `LoginView`, entre otros) pasan a
 * `text-primary-on-background`, con contraste garantizado por
 * `deriveOnBackground` (`RUX-BRAND-006`, §6) — la afirmación de que aquí
 * no se recalcula contraste en cliente ya no es cierta para esos usos.
 */
import { computed, type CSSProperties } from 'vue'
import { useTenantBranding } from '@/tenant/useTenantBranding'

const { branding, reportAssetError } = useTenantBranding()

const backgroundStyle = computed<CSSProperties>(() => {
  if (!branding.value?.login_background_url) {
    return {}
  }

  return {
    backgroundImage: `url(${branding.value.login_background_url})`,
    backgroundSize: 'cover',
    backgroundPosition: 'center',
  }
})

/**
 * §7.4: si el logo falla al cargar (URL firmada caducada en una sesión
 * larga), se informa a la capa B para que renueve `GET /tenant/branding`.
 * El fondo es CSS (`background-image`), sin evento `error` nativo: queda
 * sin recuperación automática en 1.7 y se acepta (`docs/design-system.md`
 * §13.1).
 */
function onLogoError(): void {
  if (branding.value?.logo_url) {
    reportAssetError(branding.value.logo_url)
  }
}
</script>

<template>
  <div
    class="bg-muted flex min-h-svh flex-col items-center justify-center px-4 py-10"
    :style="backgroundStyle"
  >
    <div class="border-border bg-background w-full max-w-sm rounded-xl border p-6 shadow-sm">
      <div class="mb-6 flex flex-col items-center gap-2 text-center">
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
      </div>

      <slot />
    </div>
  </div>
</template>
