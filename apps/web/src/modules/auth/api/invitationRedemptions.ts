import { apiFetch } from '@/api/client'

export interface RedeemInvitationPayload {
  token: string
  password: string
  password_confirmation: string
}

/**
 * api.md §3. El token viaja en el cuerpo, nunca en la ruta
 * (`funcional.md §4.7`). `RN-AUTH-21`: no inicia sesión.
 */
export function redeemInvitation(payload: RedeemInvitationPayload): Promise<void> {
  return apiFetch<void>('/auth/invitation-redemptions', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}
