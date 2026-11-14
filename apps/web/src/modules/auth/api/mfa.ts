/**
 * REQ-AUTH (1.3, ampliado en 1.3b). Cliente de los ocho endpoints de MFA
 * de este módulo (api.md §C.3-§C.4, §D.2-§D.3) salvo `POST /auth/session`,
 * que sigue en `session.ts` por ser el mismo endpoint que ya existía en
 * 1.2. Los de administración (`mfa-compliance`, `mfa-resets`,
 * `mfa-exemptions`) tienen su propio cliente (`mfaAdministration.ts`,
 * pieza 3 de 1.3b).
 */
import { apiFetch } from '@/api/client'
import type {
  MfaChallenge,
  MfaEnrollmentEmail,
  MfaEnrollmentTotp,
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
 * api.md §C.4/§D.2 `POST /auth/mfa-enrollments`. Inicia el alta, no la
 * activa (RN-AUTH-59). Desde 1.3b también admite `email` (`sms` sigue
 * rechazado con `422`, sin proveedor). La forma de la respuesta depende
 * del método (`RN-AUTH-75`), de ahí las dos sobrecargas.
 */
export function createMfaEnrollment(method: 'totp'): Promise<MfaEnrollmentTotp>
export function createMfaEnrollment(method: 'email'): Promise<MfaEnrollmentEmail>
export function createMfaEnrollment(
  method: MfaMethod,
): Promise<MfaEnrollmentTotp | MfaEnrollmentEmail> {
  return apiFetch<MfaEnrollmentTotp | MfaEnrollmentEmail>('/auth/mfa-enrollments', {
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

/**
 * REQ-AUTH-002 (1.4), api.md §E.5b `GET /api/v1/auth/mfa-challenges`.
 * Estrictamente de lectura (CA-AUTH-239): a diferencia de
 * `switchMfaChallenge()`, no genera código ni mueve ningún contador. Lo
 * usa `/entrar/google` para recuperar el desafío que el *callback*
 * federado abrió — su `302` no lleva datos (RN-AUTH-93) — y también el
 * login local al recuperar el desafío tras recargar `/entrar`. `410`
 * (nunca `401`, RN-AUTH-52) si no hay desafío vivo para esta sesión.
 */
export function getCurrentMfaChallenge(): Promise<MfaChallenge> {
  return apiFetch<MfaChallenge>('/auth/mfa-challenges')
}
