<script setup lang="ts">
/**
 * funcional.md §1.6: envoltorio común de las cinco pantallas públicas.
 * Sin `AppLayout` (sin navegación, a propósito: ninguna depende del
 * *layout* de 1.8). Pinta el branding del centro y nada más de
 * `GET /tenant/branding` (`CA-AUTH-061`).
 */
import { computed, type CSSProperties } from 'vue'

interface Branding {
  name: string
  color_primary: string | null
  color_secondary: string | null
  logo_url: string | null
  login_background_url: string | null
}

const props = defineProps<{
  branding: Branding | null
}>()

/**
 * RUX-BRAND-002/004: colores del centro. El par `color_primary`/
 * `color_secondary` ya viene validado por `REQ-CORE` (`RN-CORE-15`:
 * contraste WCAG 2.2 AA **entre ambos**), así que se aplica tal cual como
 * el par de variables `--primary`/`--primary-foreground` de shadcn-vue —
 * es lo que permite reutilizar el color del centro en botones y enlaces
 * sin recalcular contraste en el cliente (`CA-AUTH-062`).
 */
const brandVars = computed<CSSProperties>(() => {
  if (!props.branding?.color_primary || !props.branding?.color_secondary) {
    return {}
  }

  return {
    '--primary': props.branding.color_primary,
    '--primary-foreground': props.branding.color_secondary,
  } as CSSProperties
})

const backgroundStyle = computed<CSSProperties>(() => {
  if (!props.branding?.login_background_url) {
    return {}
  }

  return {
    backgroundImage: `url(${props.branding.login_background_url})`,
    backgroundSize: 'cover',
    backgroundPosition: 'center',
  }
})
</script>

<template>
  <div
    class="bg-muted flex min-h-svh flex-col items-center justify-center px-4 py-10"
    :style="backgroundStyle"
  >
    <div
      class="border-border bg-background w-full max-w-sm rounded-xl border p-6 shadow-sm"
      :style="brandVars"
    >
      <div class="mb-6 flex flex-col items-center gap-2 text-center">
        <img v-if="branding?.logo_url" :src="branding.logo_url" alt="" class="h-10 w-auto" />
        <span v-if="branding?.name" class="font-heading text-lg font-semibold">{{
          branding.name
        }}</span>
      </div>

      <slot />
    </div>
  </div>
</template>
