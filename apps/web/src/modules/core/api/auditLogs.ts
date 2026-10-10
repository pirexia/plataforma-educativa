import { apiFetch } from '@/api/client'
import { buildQuery, joinList } from './shared'
import type {
  AuditEvent,
  AuditFacets,
  AuditLog,
  CursorPaginated,
  DataExport,
  PublicId,
} from '../types'

export interface ListAuditLogsParams {
  /** ADR-038 §5.2: sufijo `_from`/`_to` de los filtros de rango (issue #266). */
  occurred_at_from?: string
  occurred_at_to?: string
  actor_id?: PublicId
  /** S7 de 1.9d (ADR-038 §5.2): varios tipos de actor separados por comas. */
  actor_type?: string[]
  event?: AuditEvent[]
  auditable_type?: string[]
  auditable_id?: PublicId
  /** S7 de 1.9d: varios módulos separados por comas. */
  module?: string[]
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
    occurred_at_from: params.occurred_at_from,
    occurred_at_to: params.occurred_at_to,
    actor_id: params.actor_id,
    actor_type: joinList(params.actor_type),
    event: joinList(params.event),
    auditable_type: joinList(params.auditable_type),
    auditable_id: params.auditable_id,
    module: joinList(params.module),
    cursor: params.cursor,
    limit: params.limit,
  })

  return apiFetch<CursorPaginated<AuditLog>>(`/audit-logs${query}`)
}

/**
 * ADR-054 §8.2 (issue #267): exactamente los filtros estructurados del
 * listado (`ListAuditLogsParams`), salvo `cursor`/`limit`. Sin `q` ni `sort`.
 * En el cuerpo JSON los valores múltiples van como array.
 */
export interface ExportAuditLogsPayload {
  format: 'csv'
  occurred_at_from?: string
  occurred_at_to?: string
  actor_id?: PublicId
  actor_type?: string[]
  event?: AuditEvent[]
  auditable_type?: string[]
  auditable_id?: PublicId
  module?: string[]
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

/**
 * `GET /audit-logs/facets` (S10 de 1.9d, `OPEN-CORE-34` = B, `auditoria.leer`):
 * las opciones de los filtros de módulo y tipo de entidad, del catálogo del
 * servidor, sin traducir. Una sola petición por montaje de la pantalla.
 */
export function getAuditFacets(): Promise<AuditFacets> {
  return apiFetch<AuditFacets>('/audit-logs/facets')
}
