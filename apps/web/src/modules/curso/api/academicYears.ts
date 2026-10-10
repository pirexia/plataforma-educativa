/**
 * REQ-CURSO/api.md §2 (paso 1.10): los seis endpoints de cursos. Sin
 * `DELETE`, sin transición a `archivado` y sin reapertura (no existen en 1.10).
 *
 * `#385`: todo identificador que entra en una ruta se codifica con
 * `encodeURIComponent`. Un `public_id` es un ULID (alfabeto cerrado), pero el
 * cliente no debe depender de eso: un valor con `/`, `?` o `#` no puede alterar
 * la ruta que se pide.
 */
import { apiFetch } from '@/api/client'
import type { AcademicYear, AcademicYearStatus, Paginated, PublicId } from '../types'

export interface ListAcademicYearsParams {
  /** `ADR-038 §5.2`: varios estados separados por comas. */
  status?: string[]
  /** Lista blanca: `starts_on`, `-starts_on`, `code`, `-code` (por defecto `-starts_on`). */
  sort?: string
  page?: number
  per_page?: number
}

export function listAcademicYears(
  params: ListAcademicYearsParams = {},
): Promise<Paginated<AcademicYear>> {
  const search = new URLSearchParams()

  if (params.status && params.status.length > 0) {
    search.set('status', params.status.join(','))
  }

  for (const key of ['sort', 'page', 'per_page'] as const) {
    const value = params[key]

    if (value !== undefined && value !== '') {
      search.set(key, String(value))
    }
  }

  const query = search.toString()

  return apiFetch<Paginated<AcademicYear>>(`/academic-years${query ? `?${query}` : ''}`)
}

export function getAcademicYear(publicId: PublicId): Promise<AcademicYear> {
  return apiFetch<AcademicYear>(`/academic-years/${encodeURIComponent(publicId)}`)
}

/**
 * `OPEN-CURSO-15`: autoservicio, sin permiso. Sin curso activo responde `404`
 * con `errors.academic_year[0].code = curso.no_active_year`, un estado legítimo
 * (`funcional.md §4.4` paso 5) que el llamador trata por su `code`.
 */
export function getCurrentAcademicYear(): Promise<AcademicYear> {
  return apiFetch<AcademicYear>('/academic-years/current')
}

export interface AcademicYearPayload {
  code: string
  starts_on: string
  ends_on: string
}

/** `RN-CURSO-03`: se crea siempre en `planificacion`; `status` no es campo de entrada. */
export function createAcademicYear(payload: AcademicYearPayload): Promise<AcademicYear> {
  return apiFetch<AcademicYear>('/academic-years', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

/** `ADR-038 §9.2`: solo las claves modificadas. Solo en `planificacion` (`RN-CURSO-06`). */
export function updateAcademicYear(
  publicId: PublicId,
  payload: Partial<AcademicYearPayload>,
): Promise<AcademicYear> {
  return apiFetch<AcademicYear>(`/academic-years/${encodeURIComponent(publicId)}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

/** `planificacion → activo` y `activo → cerrado` (`RN-CURSO-10`/`-12`). */
export function transitionAcademicYear(
  publicId: PublicId,
  status: Extract<AcademicYearStatus, 'activo' | 'cerrado'>,
): Promise<AcademicYear> {
  return apiFetch<AcademicYear>(`/academic-years/${encodeURIComponent(publicId)}/status`, {
    method: 'POST',
    body: JSON.stringify({ status }),
  })
}
