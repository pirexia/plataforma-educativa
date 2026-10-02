/**
 * `docs/modulos/REQ-CORE/funcional.md §14.6.2`, `RN-CORE-73`. La confirmación
 * de «Ejecutar» debe decir **si se enviarán invitaciones**, pero
 * `UserImportResource` no devuelve `send_invitations` (el cambio S8 de §14.11
 * añade solo `created_at`). Hasta que el servidor lo exponga (decisión
 * pendiente, ver el informe de `1.9c`), la pantalla de subida deja aquí lo que
 * el usuario eligió y el detalle lo recuerda **solo en memoria** (`RN-CORE-50`:
 * nada en `localStorage`, `sessionStorage` ni en la URL). Si no se sabe (otra
 * sesión, recarga de la página), la confirmación usa un texto neutro que remite
 * a lo elegido al subir el fichero.
 */
const chosen = new Map<string, boolean>()

export function rememberImportInvitations(publicId: string, sendInvitations: boolean): void {
  chosen.set(publicId, sendInvitations)
}

/** `null` si no se sabe. */
export function recallImportInvitations(publicId: string): boolean | null {
  return chosen.get(publicId) ?? null
}
