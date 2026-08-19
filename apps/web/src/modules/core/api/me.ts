import { apiFetch } from '@/api/client'
import type { User } from '../types'

/** funcional.md §4.9. Autorizado por identidad, no por permiso. */
export function getMe(): Promise<User> {
  return apiFetch<User>('/me')
}

export interface UpdateMePayload {
  person?: Partial<{
    locale: string
    contact_email: string
    contact_phone: string
  }>
}

/** Cualquier otro campo enviado se ignora silenciosamente en el servidor (CA-CORE-018). */
export function updateMe(payload: UpdateMePayload): Promise<User> {
  return apiFetch<User>('/me', { method: 'PATCH', body: JSON.stringify(payload) })
}
