/**
 * Mensaje de resultado de una escritura que cambia de pantalla («curso
 * creado»): lo deja la vista que escribe y lo recoge, una sola vez, la que se
 * abre. Solo en memoria: nunca en `localStorage`, `sessionStorage` ni en la URL
 * (mismo criterio que `RN-CORE-66`/`RN-CORE-50`).
 */
import { shallowRef } from 'vue'

export interface FlashMessage {
  /** Clave de traducción (`INV-009`). */
  key: string
  params?: Record<string, string | number>
}

const flash = shallowRef<FlashMessage | null>(null)

export function setFlash(message: FlashMessage): void {
  flash.value = message
}

/** Devuelve el mensaje pendiente y lo olvida. */
export function takeFlash(): FlashMessage | null {
  const current = flash.value

  flash.value = null

  return current
}
