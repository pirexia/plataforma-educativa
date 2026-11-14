import { apiFetch } from '@/api/client'

export interface UnlockAccountPayload {
  token: string
}

/** api.md §5. Desbloqueo por el propio titular con el token del correo de aviso. */
export function unlockAccount(payload: UnlockAccountPayload): Promise<void> {
  return apiFetch<void>('/auth/account-unlocks', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}
