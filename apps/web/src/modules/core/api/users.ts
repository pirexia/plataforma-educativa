import { apiFetch } from '@/api/client'
import { buildQuery, joinList } from './shared'
import type { CreatedUser, Paginated, PublicId, User, UserStatus } from '../types'

export interface ListUsersParams {
  q?: string
  status?: UserStatus[]
  role?: PublicId[]
  locale?: string
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
    locale: params.locale,
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

export function getUser(publicId: PublicId): Promise<User> {
  return apiFetch<User>(`/users/${publicId}`)
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
