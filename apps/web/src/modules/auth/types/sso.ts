/**
 * REQ-AUTH-004 (1.4b), ampliado por `1.4c` (`api.md §G.2`-`§G.5`: el
 * discriminador `protocol` entra en el contrato, y los campos propios de
 * un proveedor SAML). En la forma exacta en que la API los entrega.
 */
import type { PageMeta, PublicId } from './index'

export type SsoProtocol = 'oidc' | 'saml'
export type SsoEmailClaim = 'email' | 'preferred_username' | 'upn'
export type SsoClaimsSource = 'id_token' | 'userinfo'
export type SsoProvisioningMode = 'desactivado' | 'emparejamiento'

/** `datos.md §G.3`. Lista blanca cerrada en el motor — `transient` NO está (`RN-AUTH-123`). */
export type SsoSamlNameIdFormat = 'emailAddress' | 'persistent' | 'unspecified'

/** `datos.md §G.3`. Conmutador explícito, no deducido de qué columna está informada. */
export type SsoSamlMetadataSource = 'url' | 'xml'

/** `datos.md §G.5`. Traza de procedencia de un certificado del IdP. */
export type SsoSamlCertificateSource = 'metadata' | 'manual'

export interface IdentityProviderSecretStatus {
  has_active: boolean
  active_expires_at: string | null
  expiring_soon: boolean
}

/**
 * `api.md §G.2`: hermano exacto de `IdentityProviderSecretStatus` para
 * un proveedor SAML — lo que la pantalla necesita para el aviso de
 * caducidad de certificado sin pedir el detalle de cada fila.
 */
export interface IdentityProviderCertificateStatus {
  vigentes: number
  proximo_vencimiento: string | null
}

/** api.md §G.5. Nunca el PEM en la lista (`RN-AUTH-127`): la huella basta para identificarlo. */
export interface IdentityProviderCertificate {
  public_id: PublicId
  fingerprint_sha256: string
  not_before: string
  not_after: string
  source: SsoSamlCertificateSource
  retired_at: string | null
}

/** api.md §F.4. Nunca incluye el valor de la credencial (RN-AUTH-112). */
export interface IdentityProviderSecret {
  public_id: PublicId
  activated_at: string
  expires_at: string | null
  retired_at: string | null
}

/** Campos comunes a los dos protocolos, en la colección y en el detalle. */
interface IdentityProviderCommon {
  public_id: PublicId
  display_name: string
  issuer: string
  is_enabled: boolean
  provisioning_mode: SsoProvisioningMode
  allowed_email_domains: string[]
}

/**
 * api.md §F.2 `GET /identity-providers`: una fila de la colección, para
 * un proveedor `oidc`.
 */
export interface IdentityProviderOidcSummary extends IdentityProviderCommon {
  protocol: 'oidc'
  client_id: string
  claims_source: SsoClaimsSource
  email_claim: SsoEmailClaim
  scopes: string[]
  discovery_fetched_at: string
  discovery_failed_at: string | null
  secret_status: IdentityProviderSecretStatus
}

/**
 * api.md §G.2: una fila de la colección, para un proveedor `saml`.
 * `certificate_status` es el hermano exacto de `secret_status`.
 */
export interface IdentityProviderSamlSummary extends IdentityProviderCommon {
  protocol: 'saml'
  certificate_status: IdentityProviderCertificateStatus
}

/**
 * api.md §F.2/§G.2: unión discriminada por `protocol` — comprobar
 * `provider.protocol === 'saml'` estrecha el tipo al resto de sus campos,
 * sin campos "por si acaso" a `undefined` del otro protocolo.
 */
export type IdentityProviderSummary = IdentityProviderOidcSummary | IdentityProviderSamlSummary

/**
 * api.md §F.2 `GET /identity-providers/{public_id}`: el detalle de un
 * proveedor `oidc`. `authorization_endpoint` es común a los dos
 * protocolos (`datos.md §G.0.3` desviación 2).
 */
export interface IdentityProviderOidcDetail extends IdentityProviderOidcSummary {
  authorization_endpoint: string
  discovery_url: string
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

/**
 * api.md §G.2: el detalle de un proveedor `saml`. `metadata_xml`
 * **solo** trae contenido cuando `metadata_source = 'xml'` — si no, es
 * `null` (es el único caso en que el administrador lo pegó y puede
 * querer revisarlo).
 */
export interface IdentityProviderSamlDetail extends IdentityProviderSamlSummary {
  authorization_endpoint: string
  metadata_source: SsoSamlMetadataSource
  metadata_url: string | null
  metadata_xml: string | null
  name_id_format: SsoSamlNameIdFormat
  email_attribute: string | null
  sign_authn_requests: boolean
  metadata_fetched_at: string | null
  metadata_failed_at: string | null
  certificates: IdentityProviderCertificate[]
}

/** api.md §F.2/§G.2: unión discriminada por `protocol`, como `IdentityProviderSummary`. */
export type IdentityProviderDetail = IdentityProviderOidcDetail | IdentityProviderSamlDetail

/** api.md §F.2 `GET /identity-providers`: colección paginada. */
export interface IdentityProvidersPage {
  data: IdentityProviderSummary[]
  meta: PageMeta
}

/** api.md §F.3: cuerpo de alta de un proveedor OIDC. */
export interface IdentityProviderOidcInput {
  protocol: 'oidc'
  display_name: string
  discovery_url: string
  client_id: string
  email_claim?: SsoEmailClaim
  claims_source?: SsoClaimsSource
  scopes?: string[]
  allowed_email_domains?: string[]
  provisioning_mode?: SsoProvisioningMode
}

/**
 * api.md §G.2: cuerpo de alta de un proveedor SAML. `metadata_url` **o**
 * `metadata_xml`, nunca los dos ni ninguno — el formulario decide cuál
 * de los dos manda, según `metadata_source`.
 */
export interface IdentityProviderSamlInput {
  protocol: 'saml'
  display_name: string
  metadata_url?: string
  metadata_xml?: string
  email_attribute?: string
  allowed_email_domains?: string[]
  provisioning_mode?: SsoProvisioningMode
  sign_authn_requests?: boolean
}

/** api.md §F.3/§G.2: cuerpo de alta, discriminado por `protocol`. */
export type IdentityProviderInput = IdentityProviderOidcInput | IdentityProviderSamlInput

/**
 * api.md §F.3/§G.2: cuerpo de edición — cualquier subconjunto de los
 * campos propios del protocolo del proveedor, más `is_enabled`.
 * **`protocol` nunca va aquí** (`RN-AUTH-114`, `CA-AUTH-316`): un `PATCH`
 * que lo trae responde `422`, así que este tipo, deliberadamente, no lo
 * admite.
 */
export type IdentityProviderUpdateInput = Partial<
  Omit<IdentityProviderOidcInput, 'protocol'> & Omit<IdentityProviderSamlInput, 'protocol'>
> & {
  is_enabled?: boolean
}

/** api.md §G.3 `GET .../metadata` con `Accept: application/json` — los valores para copiar en la pantalla. */
export interface SamlSpMetadata {
  entity_id: string
  assertion_consumer_service_url: string
  name_id_format: string
  certificate: string | null
}
