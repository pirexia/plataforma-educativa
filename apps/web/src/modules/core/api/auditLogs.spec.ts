import { describe, it, expect, vi, beforeEach } from 'vitest'

// ADR-038 §5.2 (issue #266) y ADR-054 §8.2 (issue #267): los parámetros que
// la SPA envía a `GET /audit-logs` y a `POST /audit-logs/exports`.
const apiFetchMock = vi.hoisted(() => vi.fn())
vi.mock('@/api/client', () => ({ apiFetch: apiFetchMock }))

import {
  exportAuditLogs,
  getAuditFacets,
  listAuditLogs,
  type ExportAuditLogsPayload,
} from './auditLogs'

describe('auditLogs api', () => {
  beforeEach(() => {
    apiFetchMock.mockReset()
    apiFetchMock.mockResolvedValue({})
  })

  it('listAuditLogs envía el rango como occurred_at_from/occurred_at_to y no como from/to', async () => {
    await listAuditLogs({ occurred_at_from: '2026-01-01', occurred_at_to: '2026-01-31' })

    const url = apiFetchMock.mock.calls[0][0] as string
    const params = new URLSearchParams(url.split('?')[1])

    expect(params.get('occurred_at_from')).toBe('2026-01-01')
    expect(params.get('occurred_at_to')).toBe('2026-01-31')
    expect(params.has('from')).toBe(false)
    expect(params.has('to')).toBe(false)
  })

  it('exportAuditLogs envía en el cuerpo los filtros del listado, incluidos actor_id, actor_type, auditable_id y module', async () => {
    const payload: ExportAuditLogsPayload = {
      format: 'csv',
      occurred_at_from: '2026-01-01',
      occurred_at_to: '2026-01-31',
      actor_id: '01HZX0000000000000000000AA',
      actor_type: ['user', 'system'],
      event: ['created'],
      auditable_type: ['user'],
      auditable_id: '01HZX0000000000000000000BB',
      module: ['core'],
    }

    await exportAuditLogs(payload)

    const [url, init] = apiFetchMock.mock.calls[0] as [string, { method: string; body: string }]

    expect(url).toBe('/audit-logs/exports')
    expect(init.method).toBe('POST')
    expect(JSON.parse(init.body)).toEqual(payload)
  })

  it('CA-CORE-245: listAuditLogs envía actor_type y module separados por comas', async () => {
    await listAuditLogs({ actor_type: ['user', 'system'], module: ['auth', 'core'] })

    const params = new URLSearchParams((apiFetchMock.mock.calls[0][0] as string).split('?')[1])

    expect(params.get('actor_type')).toBe('user,system')
    expect(params.get('module')).toBe('auth,core')
  })

  it('CA-CORE-245: getAuditFacets pide GET /audit-logs/facets', async () => {
    await getAuditFacets()

    expect(apiFetchMock).toHaveBeenCalledWith('/audit-logs/facets')
  })
})
