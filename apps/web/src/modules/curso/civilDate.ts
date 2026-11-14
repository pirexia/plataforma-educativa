/**
 * `starts_on`/`ends_on` son fechas civiles `AAAA-MM-DD` (`datos.md §1.1`): sin
 * hora ni zona. Se formatean sin pasar por `Date` en UTC, que las desplazaría un
 * día en zonas con desfase negativo.
 */
import { i18n } from '@/i18n'

const CIVIL_DATE = /^(\d{4})-(\d{2})-(\d{2})$/

export function parseCivilDate(value: string): Date | null {
  const match = CIVIL_DATE.exec(value)

  if (!match) {
    return null
  }

  const [, year, month, day] = match

  return new Date(Number(year), Number(month) - 1, Number(day))
}

export function formatCivilDate(value: string | null | undefined): string {
  const date = value ? parseCivilDate(value) : null

  if (!date) {
    return value ?? ''
  }

  return new Intl.DateTimeFormat(i18n.global.locale.value, { dateStyle: 'medium' }).format(date)
}

/** ¿Ya pasó el último día del curso? (aviso al activar, `funcional.md §6`; no impide nada). */
export function hasEnded(endsOn: string, today: Date = new Date()): boolean {
  const end = parseCivilDate(endsOn)

  if (!end) {
    return false
  }

  const startOfToday = new Date(today.getFullYear(), today.getMonth(), today.getDate())

  return end.getTime() < startOfToday.getTime()
}

/** ¿Es un estado de solo lectura? Un valor no anticipado se trata como solo lectura (`ADR-038 §7.3`). */
export function isReadOnlyStatus(status: string): boolean {
  return status !== 'planificacion' && status !== 'activo'
}
