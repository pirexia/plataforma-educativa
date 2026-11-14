/**
 * `docs/modulos/REQ-CURSO/datos.md §1.1` (1.10): `starts_on`/`ends_on` son
 * fechas civiles, sin hora ni zona; no se desplazan un día por la zona horaria.
 */
import { describe, expect, it } from 'vitest'
import { setLocale } from '@/i18n'
import { formatCivilDate, hasEnded, isReadOnlyStatus, parseCivilDate } from './civilDate'

describe('civilDate', () => {
  it('parsea AAAA-MM-DD como fecha local (sin desplazamiento de zona)', () => {
    const date = parseCivilDate('2026-09-01')!

    expect([date.getFullYear(), date.getMonth(), date.getDate()]).toEqual([2026, 8, 1])
    expect(parseCivilDate('01/09/2026')).toBeNull()
    expect(parseCivilDate('2026-9-1')).toBeNull()
  })

  it('formatea con el idioma activo y devuelve el valor tal cual si no es una fecha civil', () => {
    setLocale('es')
    expect(formatCivilDate('2026-09-01')).toBe('1 sept 2026')
    setLocale('en')
    expect(formatCivilDate('2026-09-01')).toBe('Sep 1, 2026')
    expect(formatCivilDate('')).toBe('')
    expect(formatCivilDate(null)).toBe('')
    expect(formatCivilDate('no-es-fecha')).toBe('no-es-fecha')
    setLocale('es')
  })

  it('hasEnded: solo cuando el último día ya pasó (RN-CURSO-14: es un aviso, no un impedimento)', () => {
    const today = new Date(2026, 5, 30)

    expect(hasEnded('2026-06-29', today)).toBe(true)
    expect(hasEnded('2026-06-30', today)).toBe(false)
    expect(hasEnded('2027-06-30', today)).toBe(false)
    expect(hasEnded('basura', today)).toBe(false)
  })

  it('isReadOnlyStatus: cerrado, archivado y cualquier valor no anticipado son de solo lectura (ADR-038 §7.3)', () => {
    expect(isReadOnlyStatus('planificacion')).toBe(false)
    expect(isReadOnlyStatus('activo')).toBe(false)
    expect(isReadOnlyStatus('cerrado')).toBe(true)
    expect(isReadOnlyStatus('archivado')).toBe(true)
    expect(isReadOnlyStatus('suspendido')).toBe(true)
  })
})
