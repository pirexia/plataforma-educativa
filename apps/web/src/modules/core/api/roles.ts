import { apiFetch } from '@/api/client'
import { buildQuery } from './shared'
import type { Paginated, Permission, PublicId, Role, RoleSummary } from '../types'

export function listRoles(
  params: { page?: number; per_page?: number } = {},
): Promise<Paginated<Role>> {
  const query = buildQuery({ page: params.page, per_page: params.per_page })

  return apiFetch<Paginated<Role>>(`/roles${query}`)
}

export function getRole(publicId: PublicId): Promise<Role> {
  return apiFetch<Role>(`/roles/${publicId}`)
}

/**
 * REQ-AUTH-003 (1.3), api.md §C.6: `PATCH /roles/{public_id}` acotado a
 * `mfa_required` — el único atributo escribible hasta que 1.5 amplíe el
 * cuerpo admitido (mismo verbo, misma ruta, mismo permiso). Vive en
 * `REQ-CORE` porque `roles` es su recurso (`INV-007`); lo consume la
 * pantalla de administración de MFA de 1.3b como cliente público de este
 * módulo, igual que ya hacen `getMe`/`getTenantBranding` en sentido
 * contrario.
 */
export function updateRoleMfaRequired(publicId: PublicId, mfaRequired: boolean): Promise<Role> {
  return apiFetch<Role>(`/roles/${publicId}`, {
    method: 'PATCH',
    body: JSON.stringify({ mfa_required: mfaRequired }),
  })
}

/** `REQ-PERM/api.md §3.1`: alta desde cero. `is_system` y `name_key` nunca se envían. */
export interface CreateRolePayload {
  code: string
  name: string
  mfa_required?: boolean
  special_data_access?: boolean
}

/** `REQ-PERM/api.md §3.2`: clonación; `clone_from` y `permissions` son excluyentes. */
export interface CloneRolePayload {
  clone_from: PublicId
  code: string
  name: string
}

/** `POST /roles` (`RPERM-005`, `RPERM-006`). */
export function createRole(payload: CreateRolePayload | CloneRolePayload): Promise<Role> {
  return apiFetch<Role>('/roles', { method: 'POST', body: JSON.stringify(payload) })
}

/** `REQ-PERM/api.md §4`: solo las claves modificadas (`ADR-038 §9.2`); nunca `code` ni `permissions`. */
export interface UpdateRolePayload {
  name?: string
  special_data_access?: boolean
}

/** `PATCH /roles/{id}`: edición de datos del rol. */
export function updateRole(publicId: PublicId, payload: UpdateRolePayload): Promise<Role> {
  return apiFetch<Role>(`/roles/${publicId}`, { method: 'PATCH', body: JSON.stringify(payload) })
}

/** `REQ-PERM/api.md §5`: una entrada del conjunto completo deseado; `scope` es obligatorio. */
export interface RolePermissionEntry {
  code: string
  effect: 'allow' | 'deny'
  scope: string
}

/** `PUT /roles/{id}/permissions`: reemplaza el conjunto **completo** de concesiones (idempotente). */
export function replaceRolePermissions(
  publicId: PublicId,
  permissions: readonly RolePermissionEntry[],
): Promise<Role> {
  return apiFetch<Role>(`/roles/${publicId}/permissions`, {
    method: 'PUT',
    body: JSON.stringify({ permissions }),
  })
}

/** `DELETE /roles/{id}` (`204`): baja lógica (`api.md §6`). */
export function deleteRole(publicId: PublicId): Promise<null> {
  return apiFetch<null>(`/roles/${publicId}`, { method: 'DELETE' })
}

export interface ListPermissionsParams {
  module_code?: string
  resource?: string
  include_retired?: boolean
}

export function listPermissions(
  params: ListPermissionsParams = {},
): Promise<{ data: Permission[] }> {
  const query = buildQuery({
    module_code: params.module_code,
    resource: params.resource,
    include_retired: params.include_retired,
  })

  return apiFetch<{ data: Permission[] }>(`/permissions${query}`)
}

export function listUserRoles(userPublicId: PublicId): Promise<{ data: RoleSummary[] }> {
  return apiFetch<{ data: RoleSummary[] }>(`/users/${userPublicId}/roles`)
}

/** Reemplaza el conjunto completo (api.md §5): idempotente, no PATCH/DELETE por rol. */
export function replaceUserRoles(
  userPublicId: PublicId,
  roleIds: PublicId[],
): Promise<{ data: RoleSummary[] }> {
  return apiFetch<{ data: RoleSummary[] }>(`/users/${userPublicId}/roles`, {
    method: 'PUT',
    body: JSON.stringify({ role_ids: roleIds }),
  })
}
