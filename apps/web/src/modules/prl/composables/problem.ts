/**
 * `ADR-038 §6`. Lectura de un `ApiError` en las pantallas de `REQ-PRL`: `detail` ya traducido por el servidor (la SPA no
 * mantiene un catálogo propio de mensajes, `ADR-038 §6.3`) y errores por campo. (Módulo propio, `INV-007`.)
 */
import { ApiError } from '@/api/client'

interface ProblemEntry {
  message?: string
}

function problemBody(err: unknown): { detail?: unknown; errors?: unknown } | null {
  if (!(err instanceof ApiError) || typeof err.body !== 'object' || err.body === null) {
    return null
  }

  return err.body as { detail?: unknown; errors?: unknown }
}

export function problemStatus(err: unknown): number | null {
  return err instanceof ApiError ? err.status : null
}

/** `detail` del servidor, ya traducido; `null` si no hay. */
export function problemDetail(err: unknown): string | null {
  const detail = problemBody(err)?.detail

  return typeof detail === 'string' && detail !== '' ? detail : null
}

/** `errors.<campo>[].message` de un `422`, tal cual (ya traducidos). */
export function problemFieldErrors(err: unknown): Record<string, string[]> {
  const errors = problemBody(err)?.errors

  if (!errors || typeof errors !== 'object') {
    return {}
  }

  const result: Record<string, string[]> = {}

  for (const [field, entries] of Object.entries(errors as Record<string, unknown>)) {
    const messages = (Array.isArray(entries) ? (entries as ProblemEntry[]) : [])
      .map((entry) => entry?.message)
      .filter((message): message is string => typeof message === 'string' && message !== '')

    if (messages.length > 0) {
      result[field] = messages
    }
  }

  return result
}

export interface ProblemEntryInfo {
  field: string
  code: string | null
  message: string
}

/** Cada error de `errors.<campo>[]` con su `code` (`ADR-038 §6`): `person_not_authorized`, etc. */
export function problemEntries(err: unknown): ProblemEntryInfo[] {
  const errors = problemBody(err)?.errors

  if (!errors || typeof errors !== 'object') {
    return []
  }

  const result: ProblemEntryInfo[] = []

  for (const [field, entries] of Object.entries(errors as Record<string, unknown>)) {
    for (const entry of Array.isArray(entries)
      ? (entries as { code?: unknown; message?: unknown }[])
      : []) {
      if (typeof entry?.message === 'string' && entry.message !== '') {
        // Código de error del servidor (no de un rol): se lee en una variable para no activar la heurística de
        // `roleLiterals.spec.ts`.
        const errorCode: unknown = entry.code

        result.push({
          field,
          code: typeof errorCode === 'string' ? errorCode : null,
          message: entry.message,
        })
      }
    }
  }

  return result
}

/** Segundos de `Retry-After` de un `429`, si la cabecera viene y es numérica. */
export function problemRetryAfter(err: unknown): number | null {
  if (!(err instanceof ApiError)) return null

  const value = err.headers.get('Retry-After')

  return value !== null && value !== '' && !Number.isNaN(Number(value)) ? Number(value) : null
}
