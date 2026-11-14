import { apiFetch } from '@/api/client'
import type { EffectivePermissionsResponse, PublicId } from '../types'

/**
 * `REQ-PERM/api.md §7.1`: permisos efectivos de un usuario, con procedencia.
 * Sin paginar (`§7.3`) y sin parámetros: la pantalla pide la fotografía entera
 * (`RN-PERM-41`) y filtra en cliente.
 */
export function getUserEffectivePermissions(
  userPublicId: PublicId,
): Promise<EffectivePermissionsResponse> {
  return apiFetch<EffectivePermissionsResponse>(`/users/${userPublicId}/effective-permissions`)
}

/**
 * `REQ-PERM/api.md §7.4`: los del propio solicitante, por identidad y sin
 * permiso. Única fuente fiable de lo que posee **con ámbito** (`§14.2`).
 */
export function getMyEffectivePermissions(): Promise<EffectivePermissionsResponse> {
  return apiFetch<EffectivePermissionsResponse>('/me/effective-permissions')
}
