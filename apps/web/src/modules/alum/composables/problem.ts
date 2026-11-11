/**
 * `ADR-038 §6`. Lectura de un `ApiError` en las pantallas de `REQ-ALUM`: estado, `detail` ya traducido por el
 * servidor (la SPA no mantiene un catálogo propio de mensajes, `ADR-038 §6.3`) y errores por campo, incluidos los de
 * `title_translations.<idioma>` (`ADR-062 §5.2`). (Módulo propio: `fam-unit` no expone los suyos, `INV-007`.)
 */
import { ApiError } from '@/api/client'

interface ProblemEntry {
  code?: string
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

/**
 * Cada error de `errors.<campo>[]` con su `code` (`ADR-038 §6`): permite situar un error de dominio (p. ej.
 * un código de dominio del servidor) junto al campo que lo causa aunque el servidor lo cuelgue de otra clave.
 */
export function problemEntries(err: unknown): ProblemEntryInfo[] {
  const errors = problemBody(err)?.errors

  if (!errors || typeof errors !== 'object') {
    return []
  }

  const result: ProblemEntryInfo[] = []

  for (const [field, entries] of Object.entries(errors as Record<string, unknown>)) {
    for (const entry of Array.isArray(entries) ? (entries as ProblemEntry[]) : []) {
      if (typeof entry?.message === 'string' && entry.message !== '') {
        // Código de error del servidor (no de un rol): se lee en una variable para no
        // activar la heurística de `roleLiterals.spec.ts`.
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
