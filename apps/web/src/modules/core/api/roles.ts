import { apiFetch } from '@/api/client'
import { buildQuery } from './shared'
import type { Paginated, Permission, PublicId, Role, RoleSummary } from '../types'

/** Solo lectura en 1.1 (CA-CORE-041, funcional.md §1.3) — la escritura de roles es 1.5. */
export function listRoles(
  params: { page?: number; per_page?: number } = {},
): Promise<Paginated<Role>> {
  const query = buildQuery({ page: params.page, per_page: params.per_page })

  return apiFetch<Paginated<Role>>(`/roles${query}`)
}

export function getRole(publicId: PublicId): Promise<Role> {
  return apiFetch<Role>(`/roles/${publicId}`)
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
