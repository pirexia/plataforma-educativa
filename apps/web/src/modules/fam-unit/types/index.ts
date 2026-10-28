/**
 * REQ-FAM-UNIT (pasos 1.14-c y 1.14-d). Tipos del módulo, en la forma exacta en que la API los entrega (`ADR-038 §3`:
 * snake_case, sin mapeo a camelCase). Coherentes con `apps/api/openapi/paths/fam-unit.yaml` (`api.md §1`, `§2`).
 */
import type { Paginated, PublicId } from '@/modules/core/types'

export type { Paginated, PublicId }

/** `ADR-021`/`ADR-062 §2`: vocabulario cerrado de claves de `*_translations`. */
export type TranslationLocale = 'es-ES' | 'en' | 'de' | 'fr'

export type Translations = Partial<Record<TranslationLocale, string>>

/** `api.md §1.7`. */
export type TermsKind =
  'condiciones_uso' | 'consentimiento_imagen_web' | 'consentimiento_imagen_redes'

export type TermsStatus = 'borrador' | 'publicada' | 'retirada'

/** `api.md §1.7`: el texto es contenido del centro, ya resuelto por el servidor en el idioma del usuario (`ADR-062`). */
export interface TermsVersion {
  public_id: PublicId
  kind: TermsKind
  version_number: number
  title: string
  title_translations?: Translations
  body: string
  body_translations?: Translations
  status: TermsStatus
  requires_reacceptance: boolean
  published_at: string | null
  created_at?: string
  updated_at?: string
}

/** `api.md §2.8`, `GET /me/terms`: la versión pendiente del usuario, o `null` si no tiene ninguna. */
export interface MyTerms {
  required: boolean
  version: TermsVersion | null
}

/** `api.md §1.8`. */
export interface TermsAcceptance {
  public_id: PublicId
  user: PublicId | null
  terms_version: PublicId | null
  version_number: number | null
  shown_locale: TranslationLocale
  accepted_at: string
}

/** `api.md §1.1`. */
export interface FamilyUnit {
  public_id: PublicId
  code: string
  principal_guardian: PublicId | null
  guardians_count: number
  students_count: number
  created_at: string
  updated_at: string
}

/** `api.md §1.2`. */
export interface Guardian {
  public_id: PublicId
  family_unit: PublicId
  user: PublicId
  display_name: string
  is_principal: boolean
  created_at: string
  updated_at: string
}

/** `api.md §1.3`. */
export interface FamilyUnitStudent {
  public_id: PublicId
  family_unit: PublicId
  student_person: PublicId
  display_name: string
  created_at: string
}

export type GuardianRelationship =
  'padre' | 'madre' | 'tutor_legal' | 'abuelo' | 'apoderado' | 'otro'

export type GuardianAccessLevel = 'lectura' | 'lectura_escritura'

export type GuardianLinkStatus = 'activo' | 'revocado'

/** `api.md §1.4`; los enumerados son extensibles en la lectura (`ADR-038 §7.3`). */
export interface GuardianLinkRevocation {
  reason: string
  revoked_at: string
  revoked_by: PublicId | null
}

export interface GuardianStudentLink {
  public_id: PublicId
  guardian: PublicId
  student_person: PublicId
  relationship: string
  is_legal_guardian: boolean
  legal_guardian_verified_at: string | null
  access_level: string
  restricted_categories: string[]
  is_emergency_contact: boolean
  status: GuardianLinkStatus | string
  /** Solo con `revocacion_tutor.leer`: si no, el campo se omite. */
  revocation?: GuardianLinkRevocation | null
  created_at: string
  updated_at: string
}

export type ImageConsentLevel = 'web' | 'redes_sociales'

export type ImageConsentState = 'autorizo' | 'no_autorizo' | 'pendiente'

/** `api.md §1.5`: representación calculada, sin `public_id` (se identifica por alumno y nivel). */
export interface ImageConsent {
  student_person: PublicId
  display_name: string
  family_unit: PublicId
  level: ImageConsentLevel | string
  state: ImageConsentState | string
  last_decision_at: string | null
}

/** `api.md §1.6`. */
export interface ImageConsentDecision {
  public_id: PublicId
  student_person: PublicId
  level: ImageConsentLevel | string
  decision: string
  guardian: PublicId
  recorded_by: PublicId
  channel: string
  consent_text_version: { public_id: PublicId; kind: string; version_number: number } | null
  shown_locale: string | null
  occurred_at: string
}

/** `ADR-038 §4.4`. */
export interface CursorPage<T> {
  data: T[]
  meta: { next_cursor: string | null; has_more: boolean }
}
