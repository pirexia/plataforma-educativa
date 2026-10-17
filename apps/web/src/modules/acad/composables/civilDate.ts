/**
 * `starts_on`/`ends_on` son fechas civiles `AAAA-MM-DD` (`api.md §11.1`): sin
 * hora ni zona. Se formatean sin pasar por `Date` en UTC, que las desplazaría un
 * día en zonas con desfase negativo. (Módulo propio: `curso` no expone su
 * formateador, `INV-007`.)
 */
import { i18n } from '@/i18n'

const CIVIL_DATE = /^(\d{4})-(\d{2})-(\d{2})$/

/** Fecha civil con el idioma activo; un valor que no es fecha civil se devuelve tal cual. */
export function formatCivilDate(value: string | null | undefined): string {
  const match = value ? CIVIL_DATE.exec(value) : null

  if (!match) {
    return value ?? ''
  }

  const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))

  return new Intl.DateTimeFormat(i18n.global.locale.value, { dateStyle: 'medium' }).format(date)
}
