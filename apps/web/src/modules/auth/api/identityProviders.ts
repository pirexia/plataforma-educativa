/**
 * REQ-AUTH-004 (1.4b), ampliado por `1.4c` (`api.md §G.2`-`§G.5`).
 * Cliente de los endpoints de administración del catálogo. Autoservicio
 * del centro (`ADR-043 §8.3`): permiso `proveedor_identidad.*`,
 * verificado por el servidor en cada llamada (INV-002) — este cliente no
 * repite esa comprobación.
 */
import { apiFetch, apiFetchText } from '@/api/client'
import { buildQuery } from '@/modules/core/api'
import type {
  IdentityProviderCertificate,
  IdentityProviderDetail,
  IdentityProviderInput,
  IdentityProviderSecret,
  IdentityProvidersPage,
  IdentityProviderUpdateInput,
  PublicId,
  SamlSpMetadata,
} from '../types'

/** api.md §F.2 `GET /identity-providers`. */
export function getIdentityProvidersCatalog(
  params: { page?: number; per_page?: number } = {},
): Promise<IdentityProvidersPage> {
  const query = buildQuery({ page: params.page, per_page: params.per_page })

  return apiFetch<IdentityProvidersPage>(`/identity-providers${query}`)
}

/** api.md §F.2 `GET /identity-providers/{public_id}`. */
export function getIdentityProviderDetail(publicId: PublicId): Promise<IdentityProviderDetail> {
  return apiFetch<IdentityProviderDetail>(`/identity-providers/${publicId}`)
}

/**
 * api.md §F.3 `POST /identity-providers`. Efecto síncrono: valida el
 * documento de descubrimiento antes de responder — puede tardar más que
 * una escritura ordinaria (funcional.md §F.4.1).
 */
export function createIdentityProvider(
  payload: IdentityProviderInput,
): Promise<IdentityProviderDetail> {
  return apiFetch<IdentityProviderDetail>('/identity-providers', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

/** api.md §F.3 `PATCH /identity-providers/{public_id}`. */
export function updateIdentityProvider(
  publicId: PublicId,
  payload: IdentityProviderUpdateInput,
): Promise<IdentityProviderDetail> {
  return apiFetch<IdentityProviderDetail>(`/identity-providers/${publicId}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

/** api.md §F.3 `DELETE /identity-providers/{public_id}`. Borrado lógico. */
export function deleteIdentityProvider(publicId: PublicId): Promise<void> {
  return apiFetch<void>(`/identity-providers/${publicId}`, { method: 'DELETE' })
}

/**
 * api.md §F.4 `POST /identity-providers/{public_id}/secrets`. La
 * respuesta nunca incluye el valor cargado (RN-AUTH-112).
 */
export function createIdentityProviderSecret(
  publicId: PublicId,
  payload: { client_secret: string; expires_at?: string },
): Promise<IdentityProviderSecret> {
  return apiFetch<IdentityProviderSecret>(`/identity-providers/${publicId}/secrets`, {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

/** api.md §F.4 `DELETE /identity-providers/{public_id}/secrets/{secret_public_id}`. */
export function deleteIdentityProviderSecret(
  publicId: PublicId,
  secretPublicId: PublicId,
): Promise<void> {
  return apiFetch<void>(`/identity-providers/${publicId}/secrets/${secretPublicId}`, {
    method: 'DELETE',
  })
}

/**
 * api.md §F.5 `POST /identity-providers/{public_id}/discovery-refreshes`.
 * Síncrono, no encolado (INV-012 no lo exige aquí).
 */
export function refreshIdentityProviderDiscovery(
  publicId: PublicId,
): Promise<IdentityProviderDetail> {
  return apiFetch<IdentityProviderDetail>(`/identity-providers/${publicId}/discovery-refreshes`, {
    method: 'POST',
    body: JSON.stringify({}),
  })
}

/**
 * api.md §G.3 `GET /identity-providers/{public_id}/metadata`. Nuestros
 * metadatos de SP en forma JSON — los valores en texto para copiar en la
 * pantalla (`funcional.md §G.9`). `apiFetch` fija `Accept:
 * application/problem+json, application/json`, sin
 * `application/samlmetadata+xml`, así que el servidor toma la rama JSON
 * (`IdentityProviderMetadataController::show()`).
 */
export function getIdentityProviderSpMetadata(publicId: PublicId): Promise<SamlSpMetadata> {
  return apiFetch<SamlSpMetadata>(`/identity-providers/${publicId}/metadata`)
}

/**
 * api.md §G.3, para el botón de descarga: el documento XML tal como lo
 * genera el servidor — el que hay que subir al IdP del centro (`§G.3.1`:
 * este endpoint no es anónimo, no hay URL pública que el IdP pueda
 * obtener solo).
 */
export function downloadIdentityProviderSpMetadataXml(publicId: PublicId): Promise<string> {
  return apiFetchText(`/identity-providers/${publicId}/metadata`, 'application/samlmetadata+xml')
}

/**
 * api.md §G.4 `POST /identity-providers/{public_id}/metadata-refreshes`.
 * Hermano exacto de `refreshIdentityProviderDiscovery`. Síncrono.
 */
export function refreshIdentityProviderMetadata(
  publicId: PublicId,
): Promise<IdentityProviderDetail> {
  return apiFetch<IdentityProviderDetail>(`/identity-providers/${publicId}/metadata-refreshes`, {
    method: 'POST',
    body: JSON.stringify({}),
  })
}

/**
 * api.md §G.5 `POST /identity-providers/{public_id}/certificates`. Solo
 * `certificate` (PEM): `not_before`/`not_after` se extraen del propio
 * certificado en el servidor, nunca se aceptan del cliente
 * (`RN-AUTH-126`).
 */
export function createIdentityProviderCertificate(
  publicId: PublicId,
  payload: { certificate: string },
): Promise<IdentityProviderCertificate> {
  return apiFetch<IdentityProviderCertificate>(`/identity-providers/${publicId}/certificates`, {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

/**
 * api.md §G.5 `DELETE /identity-providers/{public_id}/certificates/{certificate_public_id}`.
 * Retira el certificado (`retired_at`) y borrado lógico. No revoca nada
 * en el IdP del centro — la pantalla tiene que decirlo.
 */
export function deleteIdentityProviderCertificate(
  publicId: PublicId,
  certificatePublicId: PublicId,
): Promise<void> {
  return apiFetch<void>(`/identity-providers/${publicId}/certificates/${certificatePublicId}`, {
    method: 'DELETE',
  })
}
