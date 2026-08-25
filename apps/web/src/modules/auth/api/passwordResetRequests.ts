import { apiFetch } from '@/api/client'

export interface RequestPasswordResetPayload {
  email: string
}

/**
 * api.md §4, `RN-AUTH-10`: siempre `202`, exista o no la cuenta. El único
 * caso que no es `202` es un correo con forma inválida (`422`).
 */
export function requestPasswordReset(payload: RequestPasswordResetPayload): Promise<void> {
  return apiFetch<void>('/auth/password-reset-requests', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}
