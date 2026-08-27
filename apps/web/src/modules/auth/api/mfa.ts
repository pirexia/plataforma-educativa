/**
 * REQ-AUTH (1.3). Cliente de los ocho endpoints de MFA de este módulo
 * (api.md §C.3-§C.4) salvo `POST /auth/session`, que sigue en `session.ts`
 * por ser el mismo endpoint que ya existía en 1.2. Los dos de
 * administración (`GET /mfa-compliance`, `POST /mfa-resets`) no se
 * entregan en 1.3 (funcional.md §C.11: "sin pantalla de administración") y
 * no tienen cliente aquí.
 */
import { apiFetch } from '@/api/client'
import type {
  MfaChallenge,
  MfaEnrollment,
  MfaFactorConfirmation,
  MfaMethod,
  MfaStatus,
  PublicId,
  SessionUser,
} from '../types'

/** api.md §C.4 `GET /auth/mfa`. Autorizado por identidad, sin permiso. */
export function getMfaStatus(): Promise<MfaStatus> {
  return apiFetch<MfaStatus>('/auth/mfa')
}

/**
 * api.md §C.4 `POST /auth/mfa-enrollments`. Inicia el alta, no la activa
 * (RN-AUTH-59). En 1.3 solo `totp` supera la validación del servidor.
 */
export function createMfaEnrollment(method: MfaMethod = 'totp'): Promise<MfaEnrollment> {
  return apiFetch<MfaEnrollment>('/auth/mfa-enrollments', {
    method: 'POST',
    body: JSON.stringify({ method }),
  })
}

/** api.md §C.4 `POST /auth/mfa-factors`. Confirma el alta con el código de la app. */
export function confirmMfaFactor(
  enrollment: PublicId,
  code: string,
): Promise<MfaFactorConfirmation> {
  return apiFetch<MfaFactorConfirmation>('/auth/mfa-factors', {
    method: 'POST',
    body: JSON.stringify({ enrollment, code }),
  })
}

/**
 * api.md §C.4 `DELETE /auth/mfa-factors/{public_id}`. `DELETE` con cuerpo
 * a propósito (`§C.8.3`): la contraseña actual no puede ir en la URL.
 */
export function removeMfaFactor(publicId: PublicId, currentPassword: string): Promise<void> {
  return apiFetch<void>(`/auth/mfa-factors/${publicId}`, {
    method: 'DELETE',
    body: JSON.stringify({ current_password: currentPassword }),
  })
}

/** api.md §C.4 `POST /auth/mfa-recovery-codes`. Los códigos anteriores dejan de servir en el acto. */
export function regenerateMfaRecoveryCodes(currentPassword: string): Promise<{
  recovery_codes: string[]
}> {
  return apiFetch<{ recovery_codes: string[] }>('/auth/mfa-recovery-codes', {
    method: 'POST',
    body: JSON.stringify({ current_password: currentPassword }),
  })
}

/**
 * api.md §C.3 `POST /api/v1/auth/mfa-verifications` — paso 2 del login.
 * Autorizado por la cookie de sesión anónima que abrió el desafío
 * (RN-AUTH-53), no por identidad ni por permiso. Responde el mismo
 * recurso que `GET /me`.
 */
export function verifyMfaChallenge(
  payload: { code: string } | { recovery_code: string },
): Promise<SessionUser> {
  return apiFetch<SessionUser>('/auth/mfa-verifications', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

/**
 * api.md §C.3 `POST /api/v1/auth/mfa-challenges`. Cambia el método del
 * desafío en curso o reenvía su código — no reinicia intentos ni
 * `expires_at` (RN-AUTH-54).
 */
export function switchMfaChallenge(method: MfaMethod): Promise<MfaChallenge> {
  return apiFetch<MfaChallenge>('/auth/mfa-challenges', {
    method: 'POST',
    body: JSON.stringify({ method }),
  })
}
