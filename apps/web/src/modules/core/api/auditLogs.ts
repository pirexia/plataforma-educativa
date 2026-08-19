import { apiFetch } from '@/api/client'
import { buildQuery, joinList } from './shared'
import type { AuditEvent, AuditLog, CursorPaginated, DataExport, PublicId } from '../types'

export interface ListAuditLogsParams {
  from?: string
  to?: string
  actor_id?: PublicId
  actor_type?: string
  event?: AuditEvent[]
  auditable_type?: string[]
  auditable_id?: PublicId
  module?: string
  cursor?: string
  limit?: number
}

/**
 * ADR-038 §4.4/§4.5: paginación por cursor, no por página — `audit_logs`
 * es un flujo de eventos append-only. No hay paginador numerado; la
 * pantalla de 1.8 usará "cargar más".
 */
export function listAuditLogs(
  params: ListAuditLogsParams = {},
): Promise<CursorPaginated<AuditLog>> {
  const query = buildQuery({
    from: params.from,
    to: params.to,
    actor_id: params.actor_id,
    actor_type: params.actor_type,
    event: joinList(params.event),
    auditable_type: joinList(params.auditable_type),
    auditable_id: params.auditable_id,
    module: params.module,
    cursor: params.cursor,
    limit: params.limit,
  })

  return apiFetch<CursorPaginated<AuditLog>>(`/audit-logs${query}`)
}

export interface ExportAuditLogsPayload {
  format: 'csv'
  from?: string
  to?: string
  event?: AuditEvent[]
  auditable_type?: string[]
}

/** `format: 'pdf'` no está disponible en 1.1 (diferido a 1.17). */
export function exportAuditLogs(
  payload: ExportAuditLogsPayload,
): Promise<{ public_id: PublicId; status: string }> {
  return apiFetch<{ public_id: PublicId; status: string }>('/audit-logs/exports', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

/** Primitiva compartida (funcional.md §7): estado y descarga de cualquier exportación, no solo de auditoría. */
export function getDataExport(publicId: PublicId): Promise<DataExport> {
  return apiFetch<DataExport>(`/data-exports/${publicId}`)
}
