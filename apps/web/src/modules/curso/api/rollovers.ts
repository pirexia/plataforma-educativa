/**
 * REQ-CURSO/api.md §2.1 (1.12b, `REQ-CURSO-002`): los siete *endpoints* de la apertura del curso siguiente.
 * `#385`: todo identificador que entra en una ruta se codifica con `encodeURIComponent`.
 */
import { apiFetch } from '@/api/client'
import type { Paginated, PublicId, Rollover, RolloverElement, RolloverPreview } from '../types'

export interface RolloverSelection {
  /** `public_id` del curso destino (en `planificacion`). */
  academic_year: PublicId
  /** `public_id` del curso origen. */
  source: PublicId
  /** Códigos del catálogo, sin los de paquete. */
  elements: string[]
  /** La casilla «Copiar la asignación docente» (`RN-CURSO-82`). */
  copy_teaching_assignments: boolean
}

/** `apertura_curso_academico.crear`: el catálogo de los módulos contratados, con `allowed` por elemento. */
export function listRolloverElements(): Promise<{ data: RolloverElement[] }> {
  return apiFetch<{ data: RolloverElement[] }>('/academic-year-rollovers/elements')
}

/** Simulación (*dry-run*, `RN-CURSO-65`): `200` también con `executable: false`. Sin efectos. */
export function previewRollover(selection: RolloverSelection): Promise<RolloverPreview> {
  const search = new URLSearchParams({
    academic_year: selection.academic_year,
    source: selection.source,
    elements: selection.elements.join(','),
    copy_teaching_assignments: selection.copy_teaching_assignments ? 'true' : 'false',
  })

  return apiFetch<RolloverPreview>(`/academic-year-rollovers/preview?${search.toString()}`)
}

/** Solicitud de la copia (`RN-CURSO-68`): `202` con la copia en `pendiente`. */
export function requestRollover(selection: RolloverSelection): Promise<Rollover> {
  return apiFetch<Rollover>('/academic-year-rollovers', {
    method: 'POST',
    body: JSON.stringify(selection),
  })
}

export interface ListRolloversParams {
  /** Destino; omitido ⇒ curso activo (convención de `api.md §3`). */
  academic_year?: PublicId
  source?: PublicId
  /** `ADR-038 §5.2`: varios estados separados por comas. */
  status?: string[]
  sort?: 'created_at' | '-created_at'
  page?: number
  per_page?: number
}

export function listRollovers(params: ListRolloversParams = {}): Promise<Paginated<Rollover>> {
  const search = new URLSearchParams()

  if (params.academic_year) {
    search.set('academic_year', params.academic_year)
  }

  if (params.source) {
    search.set('source', params.source)
  }

  if (params.status && params.status.length > 0) {
    search.set('status', params.status.join(','))
  }

  for (const key of ['sort', 'page', 'per_page'] as const) {
    const value = params[key]

    if (value !== undefined) {
      search.set(key, String(value))
    }
  }

  const query = search.toString()

  return apiFetch<Paginated<Rollover>>(`/academic-year-rollovers${query ? `?${query}` : ''}`)
}

export function getRollover(publicId: PublicId): Promise<Rollover> {
  return apiFetch<Rollover>(`/academic-year-rollovers/${encodeURIComponent(publicId)}`)
}

/** `apertura_curso_academico.eliminar`, sin cuerpo (`RN-CURSO-74`): `202`, la copia pasa a `revirtiendo`. */
export function revertRollover(publicId: PublicId): Promise<Rollover> {
  return apiFetch<Rollover>(`/academic-year-rollovers/${encodeURIComponent(publicId)}/revert`, {
    method: 'POST',
  })
}

/** `apertura_curso_academico.eliminar`, sin cuerpo (`RN-CURSO-85`): cancela una copia huérfana. */
export function cancelRollover(publicId: PublicId): Promise<Rollover> {
  return apiFetch<Rollover>(`/academic-year-rollovers/${encodeURIComponent(publicId)}/cancel`, {
    method: 'POST',
  })
}
