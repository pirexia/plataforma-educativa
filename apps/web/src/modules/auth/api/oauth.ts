/**
 * REQ-AUTH-002 (1.4). Cliente de los seis endpoints de login con Google y
 * fusión de cuentas (`api.md §E.2-§E.5b`). El *callback*
 * (`GET /auth/oauth/google/callback`) no tiene cliente aquí: nunca lo
 * llama la SPA por `fetch` — es Google quien devuelve el navegador a esa
 * URL directamente (`api.md §E.4`, `RN-AUTH-93`).
 */
import { apiFetch } from '@/api/client'
import type { IdentityProvider, LinkedIdentity, OAuthAuthorization, PublicId } from '../types'

/** api.md §E.2 `GET /auth/identity-providers`. Anónimo. */
export function getIdentityProviders(): Promise<{ data: IdentityProvider[] }> {
  return apiFetch<{ data: IdentityProvider[] }>('/auth/identity-providers')
}

/**
 * api.md §E.3 `POST /auth/oauth-authorizations`. `intent = 'login'`
 * (anónimo, desde `/entrar`) o `'link'` (por identidad, desde
 * `/cuenta/seguridad`). La SPA navega con `window.location`, nunca un
 * formulario (funcional.md §E.9: la CSP no admite `form-action` a
 * `accounts.google.com`).
 */
export function beginOAuthAuthorization(intent: 'login' | 'link'): Promise<OAuthAuthorization> {
  return apiFetch<OAuthAuthorization>('/auth/oauth-authorizations', {
    method: 'POST',
    body: JSON.stringify({ provider: 'google', intent }),
  })
}

/**
 * api.md §E.5 `GET /auth/identities`. `email_at_link` ya llega
 * enmascarado del servidor (`DestinationMasker`) — nunca se enmascara en
 * el cliente.
 */
export function getIdentities(): Promise<{ data: LinkedIdentity[]; meta: { total: number } }> {
  return apiFetch<{ data: LinkedIdentity[]; meta: { total: number } }>('/auth/identities')
}

/**
 * api.md §E.5 `DELETE /auth/identities/{public_id}`. `DELETE` con cuerpo
 * a propósito (mismo criterio que `removeMfaFactor`): la contraseña
 * actual no puede ir en la URL.
 */
export function unlinkIdentity(publicId: PublicId, currentPassword: string): Promise<void> {
  return apiFetch<void>(`/auth/identities/${publicId}`, {
    method: 'DELETE',
    body: JSON.stringify({ current_password: currentPassword }),
  })
}
