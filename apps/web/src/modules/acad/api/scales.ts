/**
 * REQ-ACAD/api.md §11 (paso 1.11d-b): los cinco endpoints de `/grading-scales`.
 * Permisos `configuracion_academica.*` (`RN-ACAD-50`). `name` y `label` son de
 * solo lectura (`RN-ACAD-13`): nunca se envían; se escribe `*_translations`.
 * Los decimales viajan como **cadena** (`RN-ACAD-75`); `decimals` es entero.
 * Los valores solo se escriben dentro de la escala (`RN-ACAD-80`): `values` es,
 * en un `PATCH`, el conjunto completo resultante (`RN-ACAD-88`).
 */
import { apiFetch } from '@/api/client'
import type { GradingScale, Paginated, PublicId, ScaleType, Translations } from '../types'

/** `api.md §11.5`: lista blanca `code`, `-code`. */
export interface ListGradingScalesParams {
  /** `public_id`; omitido ⇒ curso activo en el servidor. */
  academic_year?: PublicId
  scale_type?: string[]
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

function scalePath(publicId?: PublicId): string {
  return publicId === undefined
    ? '/grading-scales'
    : `/grading-scales/${encodeURIComponent(publicId)}`
}

export function listGradingScales(
  params: ListGradingScalesParams = {},
): Promise<Paginated<GradingScale>> {
  return apiFetch<Paginated<GradingScale>>(`${scalePath()}${query(params)}`)
}

export function getGradingScale(publicId: PublicId): Promise<GradingScale> {
  return apiFetch<GradingScale>(scalePath(publicId))
}

/** Elemento de `values` en el alta (`GradingScaleValueCreate`). `position` no se envía (`RN-ACAD-83`). */
export interface GradingScaleValueCreate {
  code: string
  label_translations: Translations
  is_passing?: boolean
  range_min?: string
  range_max?: string
}

/** Elemento de `values` en un `PATCH` (`GradingScaleValuePatch`, `RN-ACAD-88`). */
export interface GradingScaleValuePatch {
  public_id?: PublicId
  code?: string
  label_translations?: Record<string, string | null>
  is_passing?: boolean
  range_min?: string
  range_max?: string
}

export interface GradingScaleCreate {
  /** Explícito y obligatorio: la escala es raíz (`RN-ACAD-51`). */
  academic_year: PublicId
  code: string
  name_translations: Translations
  scale_type: ScaleType
  min_value?: string
  max_value?: string
  decimals?: number
  passing_min_value?: string
  values?: GradingScaleValueCreate[]
}

/** `ADR-038 §9.2`: solo las claves modificadas; `scale_type` no se edita (`RN-ACAD-70`). */
export interface GradingScaleUpdate {
  code?: string
  name_translations?: Record<string, string | null>
  min_value?: string
  max_value?: string
  decimals?: number
  passing_min_value?: string
  values?: GradingScaleValuePatch[]
}

export function createGradingScale(payload: GradingScaleCreate): Promise<GradingScale> {
  return apiFetch<GradingScale>(scalePath(), { method: 'POST', body: JSON.stringify(payload) })
}

export function updateGradingScale(
  publicId: PublicId,
  payload: GradingScaleUpdate,
): Promise<GradingScale> {
  return apiFetch<GradingScale>(scalePath(publicId), {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function deleteGradingScale(publicId: PublicId): Promise<void> {
  return apiFetch<void>(scalePath(publicId), { method: 'DELETE' })
}
