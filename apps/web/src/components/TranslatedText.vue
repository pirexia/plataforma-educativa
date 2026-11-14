<script setup lang="ts">
/**
 * `docs/adr/ADR-062 §5.4` punto 1, `§5.7`, `CA-ACAD-016` (WCAG 3.1.2).
 * Presenta un texto de contenido del centro tal como lo resolvió el servidor
 * (`name`) y **marca `lang`** cuando el idioma de procedencia no coincide con el
 * de la página, es decir, cuando el texto salió de la caída. La resolución no se
 * repite aquí (`RN-ACAD-13`): solo se identifica de qué idioma procede.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { type DomainLocale } from '@/i18n'
import type { TranslationValues } from '@/lib/translatable'
import { provenanceLocale } from '@/lib/translatable'
import { useTenantLocales } from '@/tenant/useTenantLocales'

const props = defineProps<{ text: string; translations: TranslationValues }>()

const { locale } = useI18n()
const { defaultLocale } = useTenantLocales()

/** El código de `vue-i18n` para el español es `es`; el del dominio, `es-ES`. */
const pageLocale = computed<DomainLocale>(() =>
  locale.value === 'es' ? 'es-ES' : (locale.value as DomainLocale),
)

const lang = computed<DomainLocale | undefined>(() => {
  const origin = provenanceLocale(
    props.text,
    props.translations,
    pageLocale.value,
    defaultLocale.value,
  )

  return origin !== null && origin !== pageLocale.value ? origin : undefined
})
</script>

<template>
  <span :lang="lang">{{ text }}</span>
</template>
