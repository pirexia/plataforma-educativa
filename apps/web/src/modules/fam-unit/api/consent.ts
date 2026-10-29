/**
 * REQ-FAM-UNIT (paso 1.14-d, `api.md §2.7`). Consentimiento de imagen: estado vigente calculado, histórico de
 * decisiones por alumno e informe (CSV en cola sobre `data_exports`, `ADR-054`/`ADR-055`). Esta entrega no registra
 * decisiones (lo hace el tutor por el portal, `1.22`, o el centro por API).
 */
import { apiFetch } from '@/api/client'
import type { CursorPage, ImageConsent, ImageConsentDecision, Paginated, PublicId } from '../types'
import { buildQuery } from './query'

export interface ListImageConsentsParams {
  family_unit?: PublicId
  student_person?: PublicId
  level?: string
  state?: string
  sort?: string
  page?: number
  per_page?: number
}

/** `GET /image-consents` (`consentimiento_imagen.leer`, ámbito). */
export function listImageConsents(
  params: ListImageConsentsParams = {},
): Promise<Paginated<ImageConsent>> {
  return apiFetch<Paginated<ImageConsent>>(`/image-consents${buildQuery({ ...params })}`)
}

/** `GET /image-consent-decisions` (cursor): `student_person` es obligatorio. */
export function listImageConsentDecisions(params: {
  student_person: PublicId
  level?: string
  cursor?: string
  limit?: number
}): Promise<CursorPage<ImageConsentDecision>> {
  return apiFetch<CursorPage<ImageConsentDecision>>(
    `/image-consent-decisions${buildQuery({ ...params })}`,
  )
}

/**
 * `POST /image-consents/exports` (`consentimiento_imagen.exportar`): paridad de filtros con el listado, sin `q`
 * (`ADR-054 §9`). Los filtros del componente (`a,b`) pasan a las listas JSON del cuerpo (`ADR-054 §8.2`).
 */
export function exportImageConsents(
  filters: Record<string, string>,
): Promise<{ public_id: PublicId }> {
  const body: Record<string, string[]> = {}

  for (const key of ['family_unit', 'student_person', 'level', 'state'] as const) {
    const values = (filters[key] ?? '').split(',').filter((value) => value !== '')

    if (values.length > 0) {
      body[key] = values
    }
  }

  return apiFetch<{ public_id: PublicId }>('/image-consents/exports', {
    method: 'POST',
    body: JSON.stringify(body),
  })
}
