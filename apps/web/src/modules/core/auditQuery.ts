/**
 * `docs/modulos/REQ-CORE/funcional.md §14.7`, `RN-CORE-76`/`-78`,
 * `OPEN-CORE-35` = A. Traducción de los filtros de la tabla de auditoría
 * (mapa plano `parámetro → valor` en forma de *query string*, `ADR-038 §5.2`)
 * a los parámetros de `GET /audit-logs` y al cuerpo de
 * `POST /audit-logs/exports`. Funciones puras, sin red.
 *
 * **Fechas** (`OPEN-CORE-35` = A): el parámetro del servidor es un instante
 * (`TIMESTAMPTZ`), no un día. El día elegido en el filtro se convierte a
 * instante en la **zona horaria del navegador** —la misma con la que se
 * muestran las fechas de la tabla—: inicio del día para «desde», fin del día
 * para «hasta», ambos inclusivos. El fin del día lleva microsegundos
 * (`.999999`) para no dejar fuera un registro de la última fracción.
 */
import type { ExportAuditLogsPayload, ListAuditLogsParams } from './api'
import type { AuditEvent } from './types'

function parseDay(day: string): [number, number, number] | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(day)

  return match ? [Number(match[1]), Number(match[2]), Number(match[3])] : null
}

/** Inicio del día local `YYYY-MM-DD`, como instante ISO 8601 (UTC). `null` si no es un día. */
export function dayStartInstant(day: string): string | null {
  const parts = parseDay(day)

  if (!parts) {
    return null
  }

  return new Date(parts[0], parts[1] - 1, parts[2], 0, 0, 0, 0).toISOString()
}

/** Fin del día local `YYYY-MM-DD` (inclusivo), como instante ISO 8601 (UTC) con microsegundos. */
export function dayEndInstant(day: string): string | null {
  const parts = parseDay(day)

  if (!parts) {
    return null
  }

  return new Date(parts[0], parts[1] - 1, parts[2], 23, 59, 59, 999)
    .toISOString()
    .replace('.999Z', '.999999Z')
}

function split(value: string | undefined): string[] | undefined {
  const items = value ? value.split(',').filter((item) => item !== '') : []

  return items.length > 0 ? items : undefined
}

/** Filtros estructurados comunes al listado y a la exportación. */
export function auditFilterParams(
  filters: Record<string, string>,
): Omit<ExportAuditLogsPayload, 'format'> {
  const params: Omit<ExportAuditLogsPayload, 'format'> = {}

  const from = filters.occurred_at_from ? dayStartInstant(filters.occurred_at_from) : null
  const to = filters.occurred_at_to ? dayEndInstant(filters.occurred_at_to) : null

  if (from) {
    params.occurred_at_from = from
  }

  if (to) {
    params.occurred_at_to = to
  }

  if (filters.actor_id) {
    params.actor_id = filters.actor_id
  }

  const actorType = split(filters.actor_type)
  const event = split(filters.event)
  const auditableType = split(filters.auditable_type)
  const module = split(filters.module)

  if (actorType) {
    params.actor_type = actorType
  }

  if (event) {
    params.event = event as AuditEvent[]
  }

  if (auditableType) {
    params.auditable_type = auditableType
  }

  if (module) {
    params.module = module
  }

  return params
}

export function auditListParams(
  filters: Record<string, string>,
  cursor: string | undefined,
): ListAuditLogsParams {
  return { ...auditFilterParams(filters), cursor }
}

/** `RN-CORE-78`: exactamente los filtros estructurados del listado, como arrays; nada más. */
export function auditExportPayload(filters: Record<string, string>): ExportAuditLogsPayload {
  return { format: 'csv', ...auditFilterParams(filters) }
}
