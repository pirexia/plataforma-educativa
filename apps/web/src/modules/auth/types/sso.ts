/**
 * REQ-AUTH-004 (1.4b). Tipos del catálogo de administración
 * (`api.md §F.2-§F.5`), en la forma exacta en que la API los entrega.
 */
import type { PageMeta, PublicId } from './index'

export type SsoEmailClaim = 'email' | 'preferred_username' | 'upn'
export type SsoClaimsSource = 'id_token' | 'userinfo'
export type SsoProvisioningMode = 'desactivado' | 'emparejamiento'

export interface IdentityProviderSecretStatus {
  has_active: boolean
  active_expires_at: string | null
  expiring_soon: boolean
}

/** api.md §F.2 `GET /identity-providers`: una fila de la colección. */
export interface IdentityProviderSummary {
  public_id: PublicId
  display_name: string
  issuer: string
  client_id: string
  is_enabled: boolean
  provisioning_mode: SsoProvisioningMode
  claims_source: SsoClaimsSource
  email_claim: SsoEmailClaim
  scopes: string[]
  allowed_email_domains: string[]
  discovery_fetched_at: string
  discovery_failed_at: string | null
  secret_status: IdentityProviderSecretStatus
}

/** api.md §F.4. Nunca incluye el valor de la credencial (RN-AUTH-112). */
export interface IdentityProviderSecret {
  public_id: PublicId
  activated_at: string
  expires_at: string | null
  retired_at: string | null
}

/** api.md §F.2 `GET /identity-providers/{public_id}`: el detalle. */
export interface IdentityProviderDetail extends IdentityProviderSummary {
  discovery_url: string
  authorization_endpoint: string
  token_endpoint: string
  userinfo_endpoint: string | null
  integration: {
    redirect_uri: string
    scopes: string[]
    subject_claim: 'sub'
    email_claim: SsoEmailClaim
  }
  secrets: IdentityProviderSecret[]
}

/** api.md §F.2 `GET /identity-providers`: colección paginada. */
export interface IdentityProvidersPage {
  data: IdentityProviderSummary[]
  meta: PageMeta
}

/** api.md §F.3: cuerpo de alta. */
export interface IdentityProviderInput {
  display_name: string
  discovery_url: string
  client_id: string
  email_claim?: SsoEmailClaim
  claims_source?: SsoClaimsSource
  scopes?: string[]
  allowed_email_domains?: string[]
  provisioning_mode?: SsoProvisioningMode
}

/** api.md §F.3: cuerpo de edición — cualquier subconjunto, más `is_enabled`. */
export type IdentityProviderUpdateInput = Partial<IdentityProviderInput> & {
  is_enabled?: boolean
}
