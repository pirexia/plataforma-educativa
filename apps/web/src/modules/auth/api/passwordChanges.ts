import { apiFetch } from '@/api/client'

export interface ChangePasswordPayload {
  current_password: string
  password: string
  password_confirmation: string
}

/**
 * api.md §5b, funcional.md §4.8. Con sesión, sin permiso: se autoriza por
 * identidad del portador de la cookie, igual que el logout y `GET /me`.
 */
export function changePassword(payload: ChangePasswordPayload): Promise<void> {
  return apiFetch<void>('/auth/password-changes', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}
