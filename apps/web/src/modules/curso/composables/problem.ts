/**
 * `ADR-038 §6`. Lectura de un `ApiError` de la API en las pantallas de
 * `REQ-CURSO`: estado, `detail` ya traducido por el servidor (la SPA no
 * mantiene un catálogo propio de mensajes, `ADR-038 §6.3`), errores por campo
 * y las entradas `errors.<clave>[]` con `code` y `params` de los `409`.
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

/** Primera entrada de `errors.<clave>` (`ADR-038 §6.3`): `code`, `message` y `params`. */
export function problemErrorEntry(err: unknown, key: string): ProblemErrorEntry | null {
  const entries = problemBody(err)?.errors?.[key]
  const first = Array.isArray(entries) ? entries[0] : undefined

  return first && typeof first === 'object' ? first : null
}

/** Todas las entradas de `errors.<clave>` (p. ej. `errors.closure[]`, una por validación de cierre). */
export function problemErrorEntries(err: unknown, key: string): ProblemErrorEntry[] {
  const entries = problemBody(err)?.errors?.[key]

  return Array.isArray(entries) ? entries.filter((entry) => entry && typeof entry === 'object') : []
}

/**
 * Como `problemErrorEntry`, pero solo si el `code` de esa entrada es el esperado.
 * El `code` es un código de **error** del servidor (`ADR-038 §6.3`, `api.md §5`),
 * no un código de rol (`RN-CORE-23`): se compara aquí, tras desestructurar, como
 * en `core`.
 */
export function problemErrorEntryWithCode(
  err: unknown,
  key: string,
  expectedCode: string,
): ProblemErrorEntry | null {
  const entry = problemErrorEntry(err, key)

  if (entry === null) {
    return null
  }

  const { code: errorCode } = entry

  return errorCode === expectedCode ? entry : null
}

/** `params.public_id` y `params.code` de una entrada de conflicto (`active_exists`, `planning_exists`). */
export function conflictYear(
  entry: ProblemErrorEntry | null,
): { public_id: string; code: string } | null {
  const { public_id: publicId, code: yearCode } = entry?.params ?? {}

  return typeof publicId === 'string' && typeof yearCode === 'string'
    ? { public_id: publicId, code: yearCode }
    : null
}
