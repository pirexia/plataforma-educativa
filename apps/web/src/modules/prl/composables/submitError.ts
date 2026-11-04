/**
 * `REQ-FAM-UNIT/funcional.md §23.2` UI-6, `§23.5.3`: lectura uniforme del error de un envío de `prl`. El mensaje es el
 * `detail`/`message` ya traducido por el servidor; la SPA solo añade la espera de un `429` (`Retry-After`) y nunca
 * reintenta sola.
 */
import { problemDetail, problemEntries, problemRetryAfter, problemStatus } from './problem'

export interface SubmitFailure {
  status: number | null
  /** Mensaje general (sin campo) o `null`. */
  message: string | null
  /** Mensajes del servidor por campo. */
  fields: Record<string, string[]>
  /** Códigos de error de dominio presentes (`person_not_authorized`…). */
  codes: string[]
  retryAfter: number | null
}

export function describeFailure(err: unknown): SubmitFailure {
  const fields: Record<string, string[]> = {}
  const codes: string[] = []

  for (const entry of problemEntries(err)) {
    ;(fields[entry.field] ??= []).push(entry.message)

    const errorCode = entry.code

    if (errorCode) codes.push(errorCode)
  }

  return {
    status: problemStatus(err),
    message: problemDetail(err),
    fields,
    codes,
    retryAfter: problemRetryAfter(err),
  }
}
