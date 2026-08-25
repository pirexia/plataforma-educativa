import { apiFetch } from '@/api/client'
import type { SessionUser } from '../types'

/**
 * api.md §2. Sin cuerpo — deja la cookie `XSRF-TOKEN` (y la de sesión
 * anónima si no existía todavía).
 */
export function getCsrfCookie(): Promise<void> {
  return apiFetch<void>('/auth/csrf-cookie')
}

export interface LoginPayload {
  email: string
  password: string
}

/**
 * api.md §2, `RN-AUTH-21`: único camino del sistema que crea una sesión.
 * Responde el mismo recurso que `GET /me` de `REQ-CORE`.
 */
export function login(payload: LoginPayload): Promise<SessionUser> {
  return apiFetch<SessionUser>('/auth/session', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

/** api.md §2: idempotente — repetirlo sin sesión también responde 204, nunca 401. */
export function logout(): Promise<void> {
  return apiFetch<void>('/auth/session', { method: 'DELETE' })
}
