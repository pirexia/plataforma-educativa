/**
 * REQ-CURSO (paso 1.10). Tipos de los recursos del módulo, en la forma exacta en
 * que la API los entrega (`ADR-038 §3`: snake_case, sin mapeo). Coherentes con
 * `apps/api/openapi/paths/curso.yaml` y `components.yaml#/components/schemas/AcademicYear`,
 * que es la fuente de verdad (`INV-006`). Los tipos genéricos de la API se
 * reexportan de `core` (superficie pública, `AR-11`).
 */
import type { PublicId } from '@/modules/core/types'

export type {
  ApiProblemBody,
  Paginated,
  PageMeta,
  ProblemErrorEntry,
  PublicId,
} from '@/modules/core/types'

/**
 * `api.md §1`: enumerado **extensible** (`ADR-038 §7.3`). `cerrado` y `archivado`
 * son de solo lectura (`RN-CURSO-20`); un valor no anticipado se trata como solo
 * lectura y se muestra con su código.
 */
export type AcademicYearStatus = 'planificacion' | 'activo' | 'cerrado' | 'archivado'

export interface AcademicYear {
  public_id: PublicId
  code: string
  /** Fecha civil `AAAA-MM-DD`, sin hora ni zona. */
  starts_on: string
  ends_on: string
  status: AcademicYearStatus | (string & {})
  created_at: string
  updated_at: string
}

/**
 * REQ-CURSO-002 (1.12b): apertura del curso siguiente (*rollover*). Formas exactas de
 * `docs/modulos/REQ-CURSO/api.md §2.1` y de `apps/api/openapi/components.yaml`
 * (`AcademicYearRollover*`), que es la fuente de verdad (`INV-006`).
 */

/** Enumerado **extensible** (`ADR-038 §7.3`): un valor no anticipado se muestra con su código. */
export type RolloverStatus =
  'pendiente' | 'en_curso' | 'completado' | 'fallido' | 'revirtiendo' | 'revertido'

/** Un elemento del catálogo (`RN-CURSO-62`). `bundle` es `null` o `teaching_assignments` (`RN-CURSO-82`). */
export interface RolloverElement {
  code: string
  module: string
  label: string
  requires: string[]
  allowed: boolean
  bundle: string | null
}

/** Una incidencia con su mensaje ya traducido por el servidor (`blocking[]`, `errors.rollover[]`, `failure.errors[]`). */
export interface RolloverIssue {
  code: string
  message: string
  params: Record<string, unknown>
  element: string | null
}

/** Omisión o ajuste agrupado por motivo, con la etiqueta traducida por el módulo dueño. */
export interface RolloverReason {
  reason: string
  label: string
  count: number
}

export interface RolloverPreviewElement {
  code: string
  label: string
  to_copy: number
  skipped: RolloverReason[]
  adjusted: RolloverReason[]
  date_range: { starts_on: string; ends_on: string } | null
  blocking: RolloverIssue[]
}

export interface RolloverPreview {
  academic_year: Pick<AcademicYear, 'public_id' | 'code' | 'status' | 'starts_on' | 'ends_on'>
  source: Pick<AcademicYear, 'public_id' | 'code' | 'status' | 'starts_on' | 'ends_on'>
  date_offset_days: number
  copy_teaching_assignments: boolean
  elements: RolloverPreviewElement[]
  blocking: RolloverIssue[]
  executable: boolean
}

export interface RolloverFailure {
  code: string
  errors: RolloverIssue[]
}

export interface Rollover {
  public_id: PublicId
  /** `public_id` del curso destino. */
  academic_year: PublicId
  /** `public_id` del curso origen. */
  source_academic_year: PublicId
  status: RolloverStatus | (string & {})
  elements: string[]
  copy_teaching_assignments: boolean
  date_offset_days: number | null
  result: {
    elements: {
      code: string
      copied: number
      skipped: RolloverReason[]
      adjusted: RolloverReason[]
    }[]
  } | null
  failure: RolloverFailure | null
  revert_failure: RolloverFailure | null
  revertible: boolean
  /** A partir de este instante se puede cancelar una copia huérfana (`RN-CURSO-85`); `null` si no está en vuelo. */
  cancellable_at: string | null
  started_at: string | null
  finished_at: string | null
  reverted_at: string | null
  created_at: string
  updated_at: string
}
