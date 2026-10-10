/**
 * `ADR-038 §6`, `RN-CORE-65`. Lectura de un `ApiError` de la API: estado,
 * `detail` ya traducido por el servidor (`ADR-038 §6.3`: la SPA no mantiene
 * un catálogo propio de mensajes), errores por campo y `Retry-After`.
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

/** `params` de la primera entrada con ese `code` en cualquier campo de un `422` (`ADR-038 §6.3`); `null` si no hay. */
export function problemErrorParams(err: unknown, code: string): Record<string, unknown> | null {
  const errors = problemBody(err)?.errors

  if (!errors || typeof errors !== 'object') {
    return null
  }

  for (const entries of Object.values(errors)) {
    for (const entry of Array.isArray(entries) ? entries : []) {
      // Código de error de validación del servidor, no un código de rol (`RN-CORE-23`).
      const { code: errorCode, params } = entry ?? {}

      if (errorCode === code && params && typeof params === 'object') {
        return params
      }
    }
  }

  return null
}

/**
 * Primera entrada de `errors.<clave>` (`ADR-038 §6.3`, `REQ-PERM/api.md §9.2.1`):
 * los `403`/`409` de `RPERM-013`, `RN-PERM-17` y `RN-PERM-47` llevan sus datos
 * en `errors.grant[0]`, `errors.role[0]` y `errors.administration_capacity[0]`
 * (`code`, `message`, `params`), **nunca** en un `params` de primer nivel.
 */
export function problemErrorEntry(err: unknown, key: string): ProblemErrorEntry | null {
  const entries = problemBody(err)?.errors?.[key]
  const first = Array.isArray(entries) ? entries[0] : undefined

  return first && typeof first === 'object' ? first : null
}

/**
 * Como `problemErrorEntry`, pero solo si el `code` de esa entrada es el esperado.
 * El `code` es un código de **error** del servidor (`ADR-038 §6.3`), no un código de
 * rol (`RN-CORE-23`): se compara aquí, tras desestructurar, como el resto de este fichero.
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
