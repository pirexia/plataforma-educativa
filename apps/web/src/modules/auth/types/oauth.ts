/**
 * REQ-AUTH-002 (1.4). Tipos de los seis endpoints de login con Google y
 * fusión de cuentas (`api.md §E.2-§E.5b`), en la forma exacta en que la
 * API los entrega — mismo criterio que `types/index.ts` y
 * `types/administration.ts`. Ampliados por REQ-AUTH-004 (1.4b, `api.md
 * §F.6`).
 */
import type { PublicId } from './index'

/**
 * api.md §E.2, ampliado por api.md §F.6 (1.4b). Anónimo. `id` es un
 * identificador **opaco** que la SPA copia tal cual en `POST
 * /auth/oauth-authorizations`, sin interpretarlo: `"google"` para el
 * *driver* global, o el `public_id` de un proveedor catalogado.
 * `display_name_key` solo en la entrada de Google (su etiqueta la
 * resuelve la SPA con su propio catálogo de 4 idiomas — nunca llegó a
 * tener consumidor real hasta ahora); `display_name` solo en las
 * catalogadas, texto del centro sin traducir (`funcional.md §F.9`).
 */
export interface IdentityProvider {
  id: string
  display_name_key?: string
  display_name?: string
}

/**
 * api.md §E.3, ampliado por api.md §F.6 (1.4b). Sin `public_id` propio a
 * propósito (`§E.3`): la única credencial del flujo es la cookie de
 * sesión.
 */
export interface OAuthAuthorization {
  authorization_url: string
  expires_at: string
}

/**
 * api.md §E.5, ampliado por api.md §F.6 (1.4b) y `§G.9` (1.4c: `provider`
 * puede valer ahora `'saml'`, derivado del `protocol` del proveedor
 * catalogado — `UserIdentityLinkingService::link()`). `email_at_link` ya
 * llega enmascarado del servidor (`DestinationMasker`) — nunca se
 * enmascara en el cliente. `provider_display_name` solo cuando hay
 * proveedor catalogado detrás (CA-AUTH-303) — nunca el `subject`.
 */
export interface LinkedIdentity {
  public_id: PublicId
  provider: 'google' | 'oidc' | 'saml'
  provider_display_name?: string
  email_at_link: string
  link_method: 'fusion_automatica' | 'perfil' | 'emparejamiento_sso'
  linked_at: string
  last_login_at: string | null
}
