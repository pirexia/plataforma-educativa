/**
 * `ADR-038 §6`. Lectura de un `ApiError` en las pantallas de `REQ-FAM-UNIT`: estado, `detail` ya traducido por el
 * servidor (la SPA no mantiene un catálogo propio de mensajes, `ADR-038 §6.3`) y errores por campo, incluidos los de
 * `title_translations.<idioma>` (`ADR-062 §5.2`). (Módulo propio: `acad` no expone los suyos, `INV-007`.)
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
