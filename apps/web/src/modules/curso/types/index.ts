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
