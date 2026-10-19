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

/** `RN-ACAD-100`: vocabulario cerrado; enumerado extensible en la lectura (`ADR-038 §7.3`). */
export type FacilityType = 'aula' | 'recurso'

/**
 * `docs/modulos/REQ-ACAD/api.md §12.1` (1.12-a). Espacio del centro: **sin**
 * `academic_year` (`OPEN-ACAD-33`); `name` es texto simple, no traducible.
 */
export interface Facility {
  public_id: PublicId
  facility_type: FacilityType | (string & {})
  code: string
  name: string
  created_at: string
  updated_at: string
}

/** `RN-ACAD-110`: `ordinaria` en `1.12-a`; `especial` llega con `1.12-b`. */
export type FrameType = 'ordinaria' | 'especial'

/**
 * `docs/modulos/REQ-ACAD/api.md §12.1`. Franja de un marco: día ISO (1 = lunes … 7 = domingo)
 * y hora civil del centro `HH:MM` (24 h, sin zona, `ADR-065`).
 */
export interface TimeSlot {
  public_id: PublicId
  weekday: number
  starts_at: string
  ends_at: string
}

/** `docs/modulos/REQ-ACAD/api.md §12.1`. `slots` ordenadas por día y hora; `valid_*` son `null` en `ordinaria`. */
export interface TimetableFrame {
  public_id: PublicId
  academic_year: PublicId
  education_level: PublicId
  frame_type: FrameType | (string & {})
  /** Resuelto en servidor (`ADR-062 §5.3`); solo lectura. */
  name: string
  name_translations: Translations
  valid_from: string | null
  valid_to: string | null
  slots: TimeSlot[]
  created_at: string
  updated_at: string
}

/** `docs/modulos/REQ-ACAD/api.md §12.1`. Usuario del centro a quien se asigna docencia: solo `public_id` y nombre visible. */
export interface StaffSummary {
  public_id: PublicId
  name: string
}

/** `api.md §12.1` (`RN-ACAD-93`, `-95`). Asignación docente, inmutable: se crea y se borra. */
export interface TeachingAssignment {
  public_id: PublicId
  academic_year: PublicId
  teacher: StaffSummary | null
  group: PublicId
  subject: PublicId
  subgroup: PublicId | null
  created_at: string
  updated_at: string
}

/** `api.md §12.1`. Resumen de grupo, subgrupo, asignatura y aula dentro de una sesión. */
export interface SessionRef {
  public_id: PublicId
  code: string
  name: string
}

/** `api.md §12.1`, `RN-ACAD-102`: `deleted` marca un aula dada de baja de un curso cerrado. */
export interface SessionFacility extends SessionRef {
  deleted: boolean
}

/** `api.md §12.1`. Sesión con los resúmenes que pintan la rejilla en una sola llamada. */
export interface TimetableSession {
  public_id: PublicId
  academic_year: PublicId
  frame: PublicId
  slot: TimeSlot
  teaching_assignment: PublicId
  teacher: StaffSummary | null
  group: SessionRef
  subgroup: SessionRef | null
  subject: SessionRef
  facility: SessionFacility | null
  created_at: string
  updated_at: string
}

/** `RN-ACAD-123`: `params.conflicts` de `422 timetable_conflict`. */
export interface TimetableConflict {
  kind: 'teacher' | 'facility' | 'group' | (string & {})
  session: PublicId
}
