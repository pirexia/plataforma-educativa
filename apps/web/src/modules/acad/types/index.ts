/**
 * REQ-ACAD (paso 1.11-b). Tipos de los recursos del módulo, en la forma exacta
 * en que la API los entrega (`ADR-038 §3`: snake_case, sin mapeo). Coherentes con
 * `apps/api/openapi/paths/acad.yaml` y `docs/modulos/REQ-ACAD/api.md §1`, que son
 * la fuente de verdad (`INV-006`). Los tipos genéricos de la API se reexportan de
 * `core` (superficie pública, `AR-11`).
 */
import type { PublicId } from '@/modules/core/types'

export type {
  ApiProblemBody,
  Paginated,
  PageMeta,
  ProblemErrorEntry,
  PublicId,
} from '@/modules/core/types'

/** `ADR-021`/`ADR-062 §2`: vocabulario cerrado de claves de `*_translations`. */
export type TranslationLocale = 'es-ES' | 'en' | 'de' | 'fr'

export type Translations = Partial<Record<TranslationLocale, string>>

/** `RN-ACAD-10`: vocabulario cerrado; enumerado extensible en la lectura (`ADR-038 §7.3`). */
export type StageType = 'infantil' | 'primaria' | 'eso' | 'bachillerato' | 'fp' | 'universidad'

/** `RN-ACAD-11`. */
export type Regime = 'publico' | 'privado' | 'concertado'

export interface EducationLevel {
  public_id: PublicId
  academic_year: PublicId
  code: string
  /** Resuelto en servidor (`ADR-062 §5.3`); solo lectura. */
  name: string
  name_translations: Translations
  stage: StageType | (string & {})
  regime: Regime | (string & {})
  position: number
  created_at: string
  updated_at: string
}

export interface Course {
  public_id: PublicId
  academic_year: PublicId
  education_level: PublicId
  code: string
  name: string
  name_translations: Translations
  position: number
  created_at: string
  updated_at: string
}

export interface Group {
  public_id: PublicId
  academic_year: PublicId
  course: PublicId
  code: string
  /** Texto simple, no traducible y escribible (`RN-ACAD-13`). */
  name: string
  position: number
  created_at: string
  updated_at: string
}

export interface Subgroup {
  public_id: PublicId
  academic_year: PublicId
  group: PublicId
  code: string
  name: string
  position: number
  created_at: string
  updated_at: string
}

export interface Subject {
  public_id: PublicId
  academic_year: PublicId
  course: PublicId
  code: string
  name: string
  name_translations: Translations
  position: number
  created_at: string
  updated_at: string
}
