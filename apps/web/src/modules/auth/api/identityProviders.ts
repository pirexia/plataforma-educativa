/**
 * REQ-AUTH-004 (1.4b). Cliente de los ocho endpoints de administración
 * del catálogo (`api.md §F.2-§F.5`). Autoservicio del centro (`ADR-043
 * §8.3`): permiso `proveedor_identidad.*`, verificado por el servidor en
 * cada llamada (INV-002) — este cliente no repite esa comprobación.
 */
import { apiFetch } from '@/api/client'
import { buildQuery } from '@/modules/core/api'
import type {
  IdentityProviderDetail,
  IdentityProviderInput,
  IdentityProviderSecret,
  IdentityProvidersPage,
  IdentityProviderUpdateInput,
  PublicId,
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
