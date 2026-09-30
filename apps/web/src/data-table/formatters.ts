/**
 * `docs/modulos/REQ-CORE/funcional.md §13.12`. Formateadores de cifras y
 * fechas con el idioma activo, para que ninguna vista cree el suyo
 * (`MfaComplianceArea.vue` tenía uno propio). Se accede al idioma por
 * `@/i18n` (no se importa `vue-i18n` directamente, `docs/i18n.md`).
 */
import { computed } from 'vue'
import { i18n } from '@/i18n'

export function useDataTableFormatters() {
  const locale = computed(() => i18n.global.locale.value)

  const numberFormat = computed(() => new Intl.NumberFormat(locale.value))
  const dateFormat = computed(() => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }))
  const dateTimeFormat = computed(
    () => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }),
  )

  return {
    formatNumber: (value: number): string => numberFormat.value.format(value),
    /** `null`/vacío ⇒ `null`: la celda pinta el valor vacío común (`dataTable.emptyValue`). */
    formatDate: (value: string | null | undefined): string | null =>
      value ? dateFormat.value.format(new Date(value)) : null,
    formatDateTime: (value: string | null | undefined): string | null =>
      value ? dateTimeFormat.value.format(new Date(value)) : null,
  }
}
