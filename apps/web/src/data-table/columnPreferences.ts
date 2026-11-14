/**
 * `docs/modulos/REQ-CORE/funcional.md §13.8`, `RN-CORE-43`,
 * `ADR-054 §5.2`. Configuración de columnas de una tabla en
 * `localStorage`, clave `plataforma.table.<tableId>`, valor exactamente
 * `{"v":1,"hidden":["<id>",…]}`. Solo `id` de columna: nunca un dato de
 * fila, un filtro ni el texto de búsqueda (`RN-CORE-50`). Todo acceso va
 * en `try/catch`: navegación privada o almacenamiento bloqueado ⇒ se sigue
 * con la configuración por defecto.
 */
import { COLUMN_PREFERENCES_KEY_PREFIX } from './constants'

const FORMAT_VERSION = 1

function storageKey(tableId: string): string {
  return `${COLUMN_PREFERENCES_KEY_PREFIX}${tableId}`
}

function safeStorage(): Storage | null {
  try {
    return window.localStorage
  } catch {
    return null
  }
}

/** Borra la clave (valor ilegible, versión desconocida o «Restablecer columnas»). Nunca lanza. */
export function clearHiddenColumns(tableId: string): void {
  try {
    safeStorage()?.removeItem(storageKey(tableId))
  } catch {
    // Almacenamiento bloqueado: nada que hacer.
  }
}

/**
 * Lista de `id` ocultos guardada para esta tabla, o `null` si no hay
 * configuración utilizable (sin clave, ilegible o `v` distinto de 1: en
 * los dos últimos casos se borra la clave). Los `id` que ya no existen los
 * descarta quien aplica la lista (`RN-CORE-43`).
 */
export function readHiddenColumns(tableId: string): string[] | null {
  let raw: string | null

  try {
    raw = safeStorage()?.getItem(storageKey(tableId)) ?? null
  } catch {
    return null
  }

  if (raw === null) {
    return null
  }

  try {
    const parsed: unknown = JSON.parse(raw)

    if (
      typeof parsed === 'object' &&
      parsed !== null &&
      (parsed as { v?: unknown }).v === FORMAT_VERSION &&
      Array.isArray((parsed as { hidden?: unknown }).hidden) &&
      (parsed as { hidden: unknown[] }).hidden.every((id) => typeof id === 'string')
    ) {
      return (parsed as { hidden: string[] }).hidden
    }
  } catch {
    // Ilegible: se trata como configuración no utilizable.
  }

  clearHiddenColumns(tableId)

  return null
}

export function writeHiddenColumns(tableId: string, hidden: readonly string[]): void {
  try {
    safeStorage()?.setItem(
      storageKey(tableId),
      JSON.stringify({ v: FORMAT_VERSION, hidden: [...hidden] }),
    )
  } catch {
    // Almacenamiento bloqueado o lleno: la preferencia no persiste, el resto funciona.
  }
}
