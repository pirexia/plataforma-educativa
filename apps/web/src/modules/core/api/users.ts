import { apiFetch } from '@/api/client'
import { buildQuery, joinList } from './shared'
import type { CreatedUser, Paginated, PublicId, User, UserStatus } from '../types'

export interface ListUsersParams {
  q?: string
  status?: UserStatus[]
  role?: PublicId[]
  /** S7 de 1.9b (ADR-038 §5.2): varios idiomas separados por comas. */
  locale?: string[]
  include_deleted?: boolean
  sort?: string
  page?: number
  per_page?: number
}

export function listUsers(params: ListUsersParams = {}): Promise<Paginated<User>> {
  const query = buildQuery({
    q: params.q,
    status: joinList(params.status),
    role: joinList(params.role),
    locale: joinList(params.locale),
    include_deleted: params.include_deleted,
    sort: params.sort,
    page: params.page,
    per_page: params.per_page,
  })

  return apiFetch<Paginated<User>>(`/users${query}`)
}

export interface UserPersonPayload {
  given_name: string
  family_name_1: string
  family_name_2?: string
  birth_date?: string
  document_type?: string
  document_number?: string
  contact_email?: string
  contact_phone?: string
  locale?: string
}

export interface CreateUserPayload {
  email: string
  person: UserPersonPayload
  role_ids?: PublicId[]
  send_invitation?: boolean
}

export function createUser(payload: CreateUserPayload): Promise<CreatedUser> {
  return apiFetch<CreatedUser>('/users', { method: 'POST', body: JSON.stringify(payload) })
}

/**
 * `include_deleted` (S6 de 1.9b, `CA-CORE-014`): la ficha de un usuario dado de
 * baja. El servidor lo exige además de `usuario.eliminar`; sin ese permiso la
 * vista no lo envía (`RN-CORE-62`).
 */
export function getUser(
  publicId: PublicId,
  options: { include_deleted?: boolean } = {},
): Promise<User> {
  const query = buildQuery({ include_deleted: options.include_deleted ? true : undefined })

  return apiFetch<User>(`/users/${publicId}${query}`)
}

/**
 * `POST /users/exports` (S1 de 1.9b, `RN-CORE-69`, `ADR-054 §8.2`). Recibe los
 * filtros del listado en su forma de *query string* (`status=a,b`) y los
 * traduce al *array* JSON que espera el cuerpo. Nunca `q`, `sort`, `page` ni
 * `per_page`; sin `format` (el servidor asume `csv`).
 */
export function exportUsers(filters: Record<string, string>): Promise<{ public_id: PublicId }> {
  const body: Record<string, string[] | boolean> = {}

  for (const key of ['status', 'role', 'locale'] as const) {
    const values = (filters[key] ?? '').split(',').filter((value) => value !== '')

    if (values.length > 0) {
      body[key] = values
    }
  }

  if (filters.include_deleted === 'true') {
    body.include_deleted = true
  }

  return apiFetch<{ public_id: PublicId }>('/users/exports', {
    method: 'POST',
    body: JSON.stringify(body),
  })
}

export interface UpdateUserPayload {
  email?: string
  person?: Partial<UserPersonPayload>
}

export function updateUser(publicId: PublicId, payload: UpdateUserPayload): Promise<User> {
  return apiFetch<User>(`/users/${publicId}`, { method: 'PATCH', body: JSON.stringify(payload) })
}

/** Baja lógica (INV-004). */
export function deleteUser(publicId: PublicId): Promise<void> {
  return apiFetch<void>(`/users/${publicId}`, { method: 'DELETE' })
}

export function restoreUser(publicId: PublicId): Promise<User> {
  return apiFetch<User>(`/users/${publicId}/restore`, { method: 'POST' })
}

export function updateUserStatus(publicId: PublicId, status: 'activo' | 'inactivo'): Promise<User> {
  return apiFetch<User>(`/users/${publicId}/status`, {
    method: 'POST',
    body: JSON.stringify({ status }),
  })
}
