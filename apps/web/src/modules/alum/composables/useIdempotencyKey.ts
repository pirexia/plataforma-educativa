/**
 * `REQ-ALUM/api.md` (preámbulo) y `§1`, `RN-ALUM-27`, `ADR-038 §8.2`. `POST /students` exige `Idempotency-Key`, un
 * **ULID** del cliente (otro formato, `400`). Una clave por **intento de envío**: se reutiliza en todos los reintentos
 * del mismo cuerpo (doble toque, error de red) y se descarta al abrir el diálogo (`reset`) o al cambiar el cuerpo.
 */
import { ulid } from '@/lib/ulid'

export function useIdempotencyKey() {
  let key: string | null = null
  let bodyFingerprint: string | null = null

  /** La clave del intento actual: la misma mientras el cuerpo no cambie; otra si cambió. */
  function keyFor(body: unknown): string {
    const fingerprint = JSON.stringify(body)

    if (key === null || bodyFingerprint !== fingerprint) {
      key = ulid()
      bodyFingerprint = fingerprint
    }

    return key
  }

  /** Al abrir o cerrar el diálogo: el siguiente envío es otro intento. */
  function reset(): void {
    key = null
    bodyFingerprint = null
  }

  return { keyFor, reset }
}
