/**
 * `docs/modulos/REQ-CORE/funcional.md §13.12`. Formateadores de cifras y
 * fechas con el idioma activo, para que ninguna vista cree el suyo
 * (`MfaComplianceArea.vue` tenía uno propio). Se accede al idioma por
 * `@/i18n` (no se importa `vue-i18n` directamente, `docs/i18n.md`).
 */
import { computed } from 'vue'
import { i18n } from '@/i18n'

/** Fecha inválida ⇒ `null` (issue #276): `Intl.DateTimeFormat.format` lanza `RangeError` con `Invalid Date`. */
function formatValid(format: Intl.DateTimeFormat, value: string | null | undefined): string | null {
  if (!value) {
    return null
  }

  const date = new Date(value)

  return Number.isNaN(date.getTime()) ? null : format.format(date)
}

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
      formatValid(dateFormat.value, value),
    formatDateTime: (value: string | null | undefined): string | null =>
      formatValid(dateTimeFormat.value, value),
  }
}
