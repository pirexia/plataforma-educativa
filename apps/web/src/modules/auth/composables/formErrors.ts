import { ApiError } from '@/api/client'
import type { AuthProblemBody } from '../types'

/**
 * Mensajes de un campo concreto de un `422` de validación, ya traducidos
 * por el servidor (`ADR-038 §6.3`) — el motivo de `RN-AUTH-37`: el
 * catálogo de reglas lo sirve el servidor, no se duplica en el cliente.
 */
export function fieldErrors(err: unknown, field: string): string[] {
  if (!(err instanceof ApiError)) {
    return []
  }

  const body = err.body as AuthProblemBody | null

  return body?.errors?.[field]?.map((entry) => entry.message) ?? []
}

/** api.md §9.2: `429` lleva `Retry-After` en segundos. */
export function retryAfterSeconds(err: unknown): number | null {
  if (!(err instanceof ApiError)) {
    return null
  }

  const header = err.headers.get('Retry-After')
  const seconds = header ? Number(header) : NaN

  return Number.isFinite(seconds) ? seconds : null
}

export function apiErrorStatus(err: unknown): number | null {
  return err instanceof ApiError ? err.status : null
}
