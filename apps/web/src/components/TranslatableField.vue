<script setup lang="ts">
/**
 * `docs/adr/ADR-062-contenido-del-centro-traducible.md §5.7`, `CA-ACAD-016`.
 * Único componente de edición de un campo traducible (sobre shadcn-vue, sin
 * componentes de terceros): **una entrada por idioma activo del centro**, el
 * idioma por defecto primero y obligatorio, cada entrada con su atributo `lang`
 * (WCAG 3.1.2).
 *
 * - El valor es un objeto `{ 'es-ES': '…', en: '…' }`; el envío como
 *   `<campo>_translations` lo hace la vista (`translationsPatch` para `PATCH`).
 * - Los errores del servidor llegan ya traducidos: los de un idioma
 *   (`<campo>_translations.<idioma>`) junto a su entrada y los del campo entero
 *   (`<campo>_translations`, p. ej. `translations.default_locale_required`) en
 *   el grupo, con `role="alert"` y enlazados por `aria-describedby`.
 * - El idioma por defecto lleva `required`: el navegador no deja enviar el
 *   formulario sin él. El servidor decide igualmente (`INV-010`).
 */
import { computed } from 'vue'
import { useT } from '@/i18n'
import type { Locale } from '@/modules/core/types'
import type { TranslationValues } from '@/lib/translatable'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

const props = withDefaults(
  defineProps<{
    modelValue: TranslationValues
    /** Nombre visible del campo (ya traducido), p. ej. «Nombre». */
    label: string
    /** Idiomas a editar, el por defecto primero (`orderedLocales`). */
    locales: readonly Locale[]
    defaultLocale: Locale
    /** Prefijo único de los `id` de las entradas. */
    idBase: string
    /** Errores por idioma, ya traducidos. */
    localeErrors?: Partial<Record<Locale, string[]>>
    /** Errores del campo entero, ya traducidos. */
    fieldErrors?: string[]
    maxLength?: number
    disabled?: boolean
  }>(),
  { localeErrors: () => ({}), fieldErrors: () => [], maxLength: undefined, disabled: false },
)

const emit = defineEmits<{ 'update:modelValue': [value: TranslationValues] }>()

const t = useT()

const groupErrorId = computed(() => `${props.idBase}-errors`)

function update(locale: Locale, value: string | number | undefined): void {
  emit('update:modelValue', { ...props.modelValue, [locale]: String(value ?? '') })
}

function languageName(locale: Locale): string {
  return t(`translatable.locales.${locale}`)
}

function entryErrors(locale: Locale): string[] {
  return props.localeErrors[locale] ?? []
}

function describedBy(locale: Locale): string | undefined {
  const ids: string[] = []

  if (entryErrors(locale).length > 0) {
    ids.push(`${props.idBase}-${locale}-errors`)
  }

  if (props.fieldErrors.length > 0 && locale === props.defaultLocale) {
    ids.push(groupErrorId.value)
  }

  return ids.length > 0 ? ids.join(' ') : undefined
}
</script>

<template>
  <fieldset data-slot="translatable-field" class="flex flex-col gap-3" :disabled="disabled">
    <legend class="text-sm font-medium">{{ t('translatable.groupLabel', { label }) }}</legend>
    <p class="text-muted-foreground text-xs">{{ t('translatable.hint') }}</p>

    <div v-for="locale in locales" :key="locale" class="flex flex-col gap-1.5">
      <Label :for="`${idBase}-${locale}`">
        {{ t('translatable.entryLabel', { label, language: languageName(locale) }) }}
        <span v-if="locale === defaultLocale" class="text-muted-foreground font-normal">
          ({{ t('translatable.required') }}, {{ t('translatable.defaultLocale') }})
        </span>
      </Label>
      <Input
        :id="`${idBase}-${locale}`"
        :model-value="modelValue[locale] ?? ''"
        :lang="locale"
        :required="locale === defaultLocale"
        :maxlength="maxLength"
        :aria-invalid="
          entryErrors(locale).length > 0 || (locale === defaultLocale && fieldErrors.length > 0)
            ? 'true'
            : undefined
        "
        :aria-describedby="describedBy(locale)"
        @update:model-value="update(locale, $event)"
      />
      <ul
        v-if="entryErrors(locale).length > 0"
        :id="`${idBase}-${locale}-errors`"
        role="alert"
        class="text-destructive text-xs"
      >
        <li v-for="message in entryErrors(locale)" :key="message">{{ message }}</li>
      </ul>
    </div>

    <ul
      v-if="fieldErrors.length > 0"
      :id="groupErrorId"
      role="alert"
      class="text-destructive text-xs"
    >
      <li v-for="message in fieldErrors" :key="message">{{ message }}</li>
    </ul>
  </fieldset>
</template>
