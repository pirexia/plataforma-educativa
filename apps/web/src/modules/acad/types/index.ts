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

/** `RN-ACAD-55`: vocabulario cerrado; enumerado extensible en la lectura (`ADR-038 §7.3`). */
export type PeriodType = 'trimestre' | 'cuatrimestre' | 'semestre'

/** `docs/modulos/REQ-ACAD/api.md §11.1`. Fechas civiles `AAAA-MM-DD`; los períodos no tienen `code`. */
export interface AcademicPeriod {
  public_id: PublicId
  academic_year: PublicId
  education_level: PublicId
  period_type: PeriodType | (string & {})
  position: number
  /** Resuelto en servidor (`ADR-062 §5.3`); solo lectura. */
  name: string
  name_translations: Translations
  starts_on: string
  ends_on: string
  created_at: string
  updated_at: string
}

/** `RN-ACAD-70`: vocabulario cerrado; enumerado extensible en la lectura (`ADR-038 §7.3`). */
export type ScaleType = 'numerica' | 'cualitativa' | 'mixta'

/**
 * `docs/modulos/REQ-ACAD/api.md §11.2`. Valor de una cualitativa o tramo de una mixta.
 * `range_min`/`range_max` son **cadenas** con `decimals` cifras (`RN-ACAD-75`): nunca `number`.
 * `is_passing` es el guardado en la cualitativa y derivado, de solo lectura, en la mixta.
 */
export interface GradingScaleValue {
  public_id: PublicId
  code: string
  position: number
  /** Resuelto en servidor (`ADR-062 §5.3`); solo lectura. */
  label: string
  label_translations: Translations
  is_passing: boolean | null
  range_min: string | null
  range_max: string | null
}

/** `docs/modulos/REQ-ACAD/api.md §11.2`. Los campos numéricos son `null` en la cualitativa. */
export interface GradingScale {
  public_id: PublicId
  academic_year: PublicId
  code: string
  name: string
  name_translations: Translations
  scale_type: ScaleType | (string & {})
  min_value: string | null
  max_value: string | null
  decimals: number | null
  passing_min_value: string | null
  values: GradingScaleValue[]
  created_at: string
  updated_at: string
}
