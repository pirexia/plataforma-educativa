/**
 * REQ-ACAD/api.md §11 (paso 1.11d-a): los cinco endpoints de
 * `/academic-periods`. Permisos `configuracion_academica.*` (`RN-ACAD-50`).
 * `name` es de solo lectura (`RN-ACAD-13`): nunca se envía; se escribe
 * `name_translations`. En el alta, `academic_year` **no** se envía (el curso es
 * el de la etapa, `RN-ACAD-51`); en el listado de la ficha de etapa se envía
 * **siempre** junto a `education_level` (`§4.8.1`, `CA-ACAD-103`).
 */
import { apiFetch } from '@/api/client'
import type { AcademicPeriod, Paginated, PeriodType, PublicId, Translations } from '../types'

/** `api.md §11.3`: lista blanca `starts_on`, `-starts_on`, `position`, `-position`. */
export interface ListAcademicPeriodsParams {
  /** `public_id`; omitido ⇒ curso activo en el servidor. La ficha de etapa lo envía siempre. */
  academic_year?: PublicId
  education_level?: PublicId[]
  period_type?: string[]
  sort?: string
  page?: number
  per_page?: number
}

function query(params: object): string {
  const search = new URLSearchParams()

  for (const [key, value] of Object.entries(params)) {
    if (Array.isArray(value)) {
      if (value.length > 0) {
        search.set(key, value.join(','))
      }
    } else if (value !== undefined && value !== '') {
      search.set(key, String(value))
    }
  }

  const text = search.toString()

  return text ? `?${text}` : ''
}

function periodPath(publicId?: PublicId): string {
  return publicId === undefined
    ? '/academic-periods'
    : `/academic-periods/${encodeURIComponent(publicId)}`
}

export function listAcademicPeriods(
  params: ListAcademicPeriodsParams = {},
): Promise<Paginated<AcademicPeriod>> {
  return apiFetch<Paginated<AcademicPeriod>>(`${periodPath()}${query(params)}`)
}

export function getAcademicPeriod(publicId: PublicId): Promise<AcademicPeriod> {
  return apiFetch<AcademicPeriod>(periodPath(publicId))
}

export interface AcademicPeriodCreate {
  /** La etapa; el curso académico es el suyo (`RN-ACAD-51`): `academic_year` no se envía. */
  education_level: PublicId
  period_type: PeriodType
  name_translations: Translations
  starts_on: string
  ends_on: string
  position?: number
}

export function createAcademicPeriod(payload: AcademicPeriodCreate): Promise<AcademicPeriod> {
  return apiFetch<AcademicPeriod>(periodPath(), { method: 'POST', body: JSON.stringify(payload) })
}

/** `ADR-038 §9.2`: solo las claves modificadas; `name_translations` se fusiona por idioma. */
export interface AcademicPeriodUpdate {
  period_type?: PeriodType
  name_translations?: Record<string, string | null>
  starts_on?: string
  ends_on?: string
  position?: number
}

export function updateAcademicPeriod(
  publicId: PublicId,
  payload: AcademicPeriodUpdate,
): Promise<AcademicPeriod> {
  return apiFetch<AcademicPeriod>(periodPath(publicId), {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function deleteAcademicPeriod(publicId: PublicId): Promise<void> {
  return apiFetch<void>(periodPath(publicId), { method: 'DELETE' })
}
