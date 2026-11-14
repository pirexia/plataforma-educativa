import { apiFetch, apiFetchWithStatus } from '@/api/client'
import type { MfaChallenge, SessionUser } from '../types'

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
 * REQ-AUTH (1.3), api.md §C.2: `POST /auth/session` ahora puede responder
 * `200` (login completo, con o sin `mfa` en el bloque del recurso) o
 * `202` (credencial correcta, segundo factor exigible, **sin** sesión
 * autenticada — `RN-AUTH-52`). Unión discriminada por `kind`, no por
 * inspeccionar campos del cuerpo (`ADR-038 §7.3` pide ramas explícitas).
 */
export type LoginResult =
  { kind: 'authenticated'; user: SessionUser } | { kind: 'mfa-challenge'; challenge: MfaChallenge }

/**
 * api.md §2/§C.2, `RN-AUTH-21`: único camino del sistema que crea una
 * sesión. `200` responde el mismo recurso que `GET /me`; `202` no crea
 * sesión y devuelve el recurso del desafío.
 */
export async function login(payload: LoginPayload): Promise<LoginResult> {
  const { status, body } = await apiFetchWithStatus<SessionUser | MfaChallenge>('/auth/session', {
    method: 'POST',
    body: JSON.stringify(payload),
  })

  if (status === 202) {
    return { kind: 'mfa-challenge', challenge: body as MfaChallenge }
  }

  return { kind: 'authenticated', user: body as SessionUser }
}

/** api.md §2: idempotente — repetirlo sin sesión también responde 204, nunca 401. */
export function logout(): Promise<void> {
  return apiFetch<void>('/auth/session', { method: 'DELETE' })
}
