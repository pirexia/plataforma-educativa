/**
 * REQ-AUTH-002 (1.4). Tipos de los seis endpoints de login con Google y
 * fusión de cuentas (`api.md §E.2-§E.5b`), en la forma exacta en que la
 * API los entrega — mismo criterio que `types/index.ts` y
 * `types/administration.ts`.
 */
import type { PublicId } from './index'

/**
 * api.md §E.2 `GET /auth/identity-providers`. Anónimo. Sin `label_key`
 * (retirado en el cierre de 1.4): el texto del botón lo decide
 * `GoogleSignInButton.vue` por `intent`, no por proveedor, con su propio
 * catálogo de 4 idiomas — no había ningún consumidor real de esa clave.
 */
export interface IdentityProvider {
  provider: 'google'
}

/**
 * api.md §E.3 `POST /auth/oauth-authorizations`. Sin `public_id` a
 * propósito (`§E.3`): la única credencial del flujo es la cookie de
 * sesión.
 */
export interface OAuthAuthorization {
  authorization_url: string
  expires_at: string
}

/**
 * api.md §E.5 `GET /auth/identities`. `email_at_link` ya llega
 * enmascarado del servidor (`DestinationMasker`) — nunca se enmascara en
 * el cliente.
 */
export interface LinkedIdentity {
  public_id: PublicId
  provider: 'google'
  email_at_link: string
  link_method: 'fusion_automatica' | 'perfil'
  linked_at: string
  last_login_at: string | null
}
