/**
 * REQ-FAM-UNIT (pasos 1.14-c y 1.14-d, `api.md §2.8`). Autoservicio del muro de condiciones y gestión de las versiones
 * de los textos legales (`/terms-versions`, permiso `texto_legal.*`) y consulta de aceptaciones.
 */
import { apiFetch } from '@/api/client'
import type {
  CursorPage,
  MyTerms,
  Paginated,
  PublicId,
  TermsAcceptance,
  TermsVersion,
  Translations,
} from '../types'
import { buildQuery, encode } from './query'

/** `GET /me/terms`: exento del muro de condiciones (L3). Sin permiso: por identidad. */
export function getMyTerms(): Promise<MyTerms> {
  return apiFetch<MyTerms>('/me/terms')
}

/** `POST /me/terms-acceptances` (`201`, o `200` si ya existía, `RN-FAM-UNIT-64`). */
export function acceptMyTerms(termsVersion: PublicId): Promise<TermsAcceptance> {
  return apiFetch<TermsAcceptance>('/me/terms-acceptances', {
    method: 'POST',
    body: JSON.stringify({ terms_version: termsVersion }),
  })
}

export interface ListTermsVersionsParams {
  kind?: string
  status?: string
  sort?: string
  page?: number
  per_page?: number
}

/** `GET /terms-versions` (`texto_legal.leer`). */
export function listTermsVersions(
  params: ListTermsVersionsParams = {},
): Promise<Paginated<TermsVersion>> {
  return apiFetch<Paginated<TermsVersion>>(`/terms-versions${buildQuery({ ...params })}`)
}

export function getTermsVersion(publicId: PublicId): Promise<TermsVersion> {
  return apiFetch<TermsVersion>(`/terms-versions/${encode(publicId)}`)
}

/** `POST /terms-versions` (`texto_legal.crear`): nace en `borrador`. `kind` es inmutable tras el alta. */
export function createTermsVersion(body: {
  kind: string
  title_translations: Translations
  body_translations: Translations
}): Promise<TermsVersion> {
  return apiFetch<TermsVersion>('/terms-versions', { method: 'POST', body: JSON.stringify(body) })
}

/** `PATCH /terms-versions/{id}` (`texto_legal.actualizar`, solo `borrador`): fusión por idioma (`ADR-062 §5.2`). */
export function updateTermsVersion(
  publicId: PublicId,
  body: {
    title_translations?: Record<string, string | null>
    body_translations?: Record<string, string | null>
  },
): Promise<TermsVersion> {
  return apiFetch<TermsVersion>(`/terms-versions/${encode(publicId)}`, {
    method: 'PATCH',
    body: JSON.stringify(body),
  })
}

/** `DELETE /terms-versions/{id}` (`texto_legal.eliminar`, solo `borrador`). */
export function deleteTermsVersion(publicId: PublicId): Promise<void> {
  return apiFetch<void>(`/terms-versions/${encode(publicId)}`, { method: 'DELETE' })
}

/**
 * `POST /terms-versions/{id}/publish` (`texto_legal.publicar`). `requires_reacceptance` solo en `condiciones_uso`: en
 * un texto de imagen se omite (`api.md §2.8`).
 */
export function publishTermsVersion(
  publicId: PublicId,
  requiresReacceptance?: boolean,
): Promise<TermsVersion> {
  return apiFetch<TermsVersion>(`/terms-versions/${encode(publicId)}/publish`, {
    method: 'POST',
    body: JSON.stringify(
      requiresReacceptance === undefined ? {} : { requires_reacceptance: requiresReacceptance },
    ),
  })
}

/** `GET /terms-acceptances` (`aceptacion_condiciones.leer`, cursor; `D-32`). */
export function listTermsAcceptances(
  params: { terms_version?: PublicId; user?: PublicId; cursor?: string; limit?: number } = {},
): Promise<CursorPage<TermsAcceptance>> {
  return apiFetch<CursorPage<TermsAcceptance>>(`/terms-acceptances${buildQuery({ ...params })}`)
}
