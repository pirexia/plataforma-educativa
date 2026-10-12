/**
 * `ADR-038 §6`. Lectura de un `ApiError` en las pantallas de `REQ-ACAD`: estado,
 * `detail` ya traducido por el servidor (la SPA no mantiene un catálogo propio de
 * mensajes, `ADR-038 §6.3`), errores por campo (incluidos los de
 * `name_translations.<idioma>`, `ADR-062 §5.2`) y las entradas `errors.<clave>[]`
 * con `code` y `params` de los `409`.
 */
import { ApiError } from '@/api/client'
import type { ApiProblemBody, ProblemErrorEntry } from '../types'

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

/** `errors.<campo>[].message` de un `422`, tal cual (ya traducidos). */
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

/** Primera entrada de `errors.<clave>`: `code`, `message` y `params`. */
export function problemErrorEntry(err: unknown, key: string): ProblemErrorEntry | null {
  const entries = problemBody(err)?.errors?.[key]
  const first = Array.isArray(entries) ? entries[0] : undefined

  return first && typeof first === 'object' ? first : null
}

/** El `code` de un error de la API es un código de **error**, no de rol (`RN-CORE-23`). */
export function problemErrorCode(err: unknown, key: string): string | null {
  const entry = problemErrorEntry(err, key)

  return entry === null ? null : entry.code
}

/** `409 acad.conflict.has_dependents`: `params.dependents` (`RN-ACAD-20`). */
export function problemDependents(err: unknown): string[] {
  const dependents = problemErrorEntry(err, 'dependents')?.params?.dependents

  return Array.isArray(dependents)
    ? dependents.filter((item): item is string => typeof item === 'string')
    : []
}

/** `type` del problema (`urn:pge:error:academic-year-closed`, ...). */
export function problemType(err: unknown): string | null {
  const type = problemBody(err)?.type

  return typeof type === 'string' ? type : null
}

export const ACADEMIC_YEAR_CLOSED_TYPE = 'urn:pge:error:academic-year-closed'
