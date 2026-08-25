import { apiFetch } from '@/api/client'

export interface ResetPasswordPayload {
  token: string
  password: string
  password_confirmation: string
}

/**
 * api.md §4. `RN-AUTH-21`: no inicia sesión. `RN-AUTH-22`: revoca todas
 * las sesiones activas del usuario.
 */
export function resetPassword(payload: ResetPasswordPayload): Promise<void> {
  return apiFetch<void>('/auth/password-resets', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}
