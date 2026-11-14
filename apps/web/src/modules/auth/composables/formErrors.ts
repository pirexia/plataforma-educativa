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

/**
 * REQ-AUTH (1.3): `detail` de `problem+json`, ya traducido por el
 * servidor (`ADR-038 §6.3`, mismo criterio que `fieldErrors`). Se usa
 * cuando el cuerpo del error no tiene una forma de campo fija —p.ej. el
 * `409` de "último factor exigido" de `DELETE /auth/mfa-factors/{id}`,
 * cuyo detalle nombra los roles que lo exigen— así que no hay una clave
 * de `errors` que leer.
 */
export function apiErrorDetail(err: unknown): string | null {
  if (!(err instanceof ApiError)) {
    return null
  }

  const body = err.body as AuthProblemBody | null

  return body?.detail ?? null
}

/**
 * REQ-AUTH (1.3): el `type` de `problem+json` (`ADR-038 §6`) para
 * distinguir, p.ej., el `409` de "último factor exigido" de otro `409`
 * genérico, sin acoplarse a la forma exacta del cuerpo de error.
 */
export function apiErrorType(err: unknown): string | null {
  if (!(err instanceof ApiError)) {
    return null
  }

  const body = err.body as AuthProblemBody | null

  return body?.type ?? null
}
