/**
 * `ADR-038 §6`, `RN-CORE-65`. Lectura de un `ApiError` de la API: estado,
 * `detail` ya traducido por el servidor (`ADR-038 §6.3`: la SPA no mantiene
 * un catálogo propio de mensajes), errores por campo y `Retry-After`.
 */
import { ApiError } from '@/api/client'
import type { ApiProblemBody } from '../types'

export function problemStatus(err: unknown): number | null {
  return err instanceof ApiError ? err.status : null
}

function problemBody(err: unknown): Partial<ApiProblemBody> | null {
  if (!(err instanceof ApiError) || typeof err.body !== 'object' || err.body === null) {
    return null
  }

  return err.body as Partial<ApiProblemBody>
}

/** `detail` del servidor, ya traducido; `null` si no hay. */
export function problemDetail(err: unknown): string | null {
  const detail = problemBody(err)?.detail

  return typeof detail === 'string' && detail !== '' ? detail : null
}

/** `errors.<campo>[].message` de un `422`, tal cual (ya traducidos). Clave: nombre del campo del servidor. */
export function problemFieldErrors(err: unknown): Record<string, string[]> {
  const errors = problemBody(err)?.errors

  if (!errors || typeof errors !== 'object') {
    return {}
  }

  const result: Record<string, string[]> = {}

  for (const [field, entries] of Object.entries(errors)) {
    const messages = (Array.isArray(entries) ? entries : [])
      .map((entry) => entry?.message)
      .filter((message): message is string => typeof message === 'string' && message !== '')

    if (messages.length > 0) {
      result[field] = messages
    }
  }

  return result
}

/** `Retry-After` en segundos de un `429` (`api.md §4`); `null` si falta. */
export function problemRetryAfter(err: unknown): number | null {
  if (!(err instanceof ApiError)) {
    return null
  }

  const header = err.headers.get('Retry-After')
  const seconds = header === null ? NaN : Number(header)

  return Number.isFinite(seconds) ? seconds : null
}
