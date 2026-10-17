/**
 * Aviso de resultado que sobrevive a una navegación (alta, edición o borrado de
 * una escala → su ficha o su listado). Guarda la **clave** de traducción y la
 * entrega una sola vez a la pantalla de destino, que la traduce. (Módulo propio:
 * `curso` no expone su `useFlash`, `INV-007`.)
 */
import { shallowRef } from 'vue'

const pending = shallowRef<string | null>(null)

export function setFlash(key: string): void {
  pending.value = key
}

/** Devuelve la clave pendiente y la olvida. */
export function takeFlash(): string | null {
  const key = pending.value

  pending.value = null

  return key
}
