/**
 * `REQ-FAM-UNIT/funcional.md §23.5.3` («Idempotencia»), `RN-PRL-09`, `DP-4` [DERIVADA en cliente]. Una `Idempotency-Key`
 * por **intento de envío**: se reutiliza en todos los reintentos del mismo cuerpo (doble toque, error de red) y se
 * descarta al cerrar el diálogo (`reset`) o al cambiar el cuerpo. 36 caracteres ASCII visibles (`crypto.randomUUID()`).
 */
function newKey(): string {
  const cryptoApi = globalThis.crypto

  if (typeof cryptoApi?.randomUUID === 'function') {
    return cryptoApi.randomUUID()
  }

  // Entornos sin `randomUUID` (contexto no seguro): UUID v4 con `getRandomValues`.
  const bytes = new Uint8Array(16)

  cryptoApi.getRandomValues(bytes)
  bytes[6] = (bytes[6]! & 0x0f) | 0x40
  bytes[8] = (bytes[8]! & 0x3f) | 0x80

  const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('')

  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

export function useIdempotencyKey() {
  let key: string | null = null
  let bodyFingerprint: string | null = null

  /** La clave del intento actual: la misma mientras el cuerpo no cambie; otra si cambió. */
  function keyFor(body: unknown): string {
    const fingerprint = JSON.stringify(body)

    if (key === null || bodyFingerprint !== fingerprint) {
      key = newKey()
      bodyFingerprint = fingerprint
    }

    return key
  }

  /** Al cerrar el diálogo: el siguiente envío es otro intento. */
  function reset(): void {
    key = null
    bodyFingerprint = null
  }

  return { keyFor, reset }
}
