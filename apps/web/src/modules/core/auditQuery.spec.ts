/**
 * `docs/modulos/REQ-CORE/funcional.md §14.7`, `CA-CORE-243`/`-247`
 * (`OPEN-CORE-35` = A): conversión de los filtros de la tabla de auditoría a
 * los parámetros del listado y al cuerpo de la exportación.
 */
import { describe, expect, it } from 'vitest'
import {
  auditExportPayload,
  auditFilterParams,
  auditListParams,
  dayEndInstant,
  dayStartInstant,
} from './auditQuery'

describe('CA-CORE-243 (OPEN-CORE-35 = A): días a instantes en la zona del navegador', () => {
  it('el inicio del día es la medianoche local y el fin es el último instante del día local', () => {
    expect(dayStartInstant('2026-03-01')).toBe(new Date(2026, 2, 1, 0, 0, 0, 0).toISOString())
    expect(dayEndInstant('2026-03-03')).toBe(
      new Date(2026, 2, 3, 23, 59, 59, 999).toISOString().replace('.999Z', '.999999Z'),
    )
  })

  it('el fin de un día no invade el día siguiente y el inicio del siguiente es posterior', () => {
    const end = Date.parse(dayEndInstant('2026-03-03')!.replace('.999999Z', '.999Z'))
    const nextStart = Date.parse(dayStartInstant('2026-03-04')!)

    expect(nextStart - end).toBe(1)
  })

  it('un valor que no es un día devuelve null', () => {
    expect(dayStartInstant('03/03/2026')).toBeNull()
    expect(dayEndInstant('')).toBeNull()
  })
})

describe('CA-CORE-247 (RN-CORE-78): una sola traducción de filtros para el listado y la exportación', () => {
  const filters = {
    occurred_at_from: '2026-03-01',
    occurred_at_to: '2026-03-03',
    actor_id: '01HZX0000000000000000000AA',
    event: 'created,updated',
    actor_type: 'user,system',
    module: 'core',
    auditable_type: 'user,role',
  }

  it('los múltiples pasan a lista y el rango, a instantes', () => {
    expect(auditFilterParams(filters)).toEqual({
      occurred_at_from: dayStartInstant('2026-03-01'),
      occurred_at_to: dayEndInstant('2026-03-03'),
      actor_id: '01HZX0000000000000000000AA',
      event: ['created', 'updated'],
      actor_type: ['user', 'system'],
      module: ['core'],
      auditable_type: ['user', 'role'],
    })
  })

  it('el listado añade el cursor y la exportación añade format csv; ninguna lleva q, sort ni limit', () => {
    expect(auditListParams(filters, 'CUR')).toMatchObject({
      cursor: 'CUR',
      event: ['created', 'updated'],
    })

    const payload = auditExportPayload(filters)

    expect(payload.format).toBe('csv')
    expect(Object.keys(payload).sort()).toEqual(
      [
        'actor_id',
        'actor_type',
        'auditable_type',
        'event',
        'format',
        'module',
        'occurred_at_from',
        'occurred_at_to',
      ].sort(),
    )
  })

  it('sin filtros no envía ningún parámetro', () => {
    expect(auditFilterParams({})).toEqual({})
    expect(auditExportPayload({})).toEqual({ format: 'csv' })
  })
})
