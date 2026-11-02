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

// --- Personas autorizadas a recoger (`1.14b-a`, `api.md §8.1`-`§8.3`; interfaz de `1.14b-c1`) -------------------------

/** `api.md §8.1`. Enumerados extensibles en la lectura (`ADR-038 §7.3`): un código nuevo llega y se muestra en crudo. */
export type PickupRelationship = 'abuelo' | 'personal_apoyo' | 'otra_familia' | 'otro'

export type PickupAuthorizationKind = 'permanente' | 'puntual'

export type PickupChannel = 'presencial' | 'telefono'

export type PickupBasisKind = 'tutor' | 'tercero' | 'puntual'

/** `D-68`: sin `revocacion_tutor.leer`, toda causa llega como `no_vigente`. */
export type PickupIneffectiveReason =
  'sin_foto' | 'fuera_de_vigencia' | 'retirada' | 'persona_excluida' | 'no_vigente'

/** Documento enmascarado de la lista (`RN-FAM-UNIT-90`, `D-63`): solo los cuatro últimos caracteres a la vista. */
export interface PickupMaskedDocument {
  type: string
  masked: string
}

/** Documento completo: solo en la ficha de la persona, con `autorizado_recogida.actualizar`. */
export interface PickupFullDocument {
  type: string
  number: string
}

export interface PickupListBasis {
  basis: PickupBasisKind | string
  relationship: string
  guardian_link?: PublicId
  authorization?: PublicId
  valid_from?: string | null
  valid_until?: string | null
}

/** `api.md §8.1`: una persona de la lista calculada de un alumno; sin `public_id` propio. */
export interface PickupListEntry {
  person: PublicId
  display_name: string
  bases: PickupListBasis[]
  has_photo: boolean
  /** URL firmada de 5 minutos: solo en memoria del componente (UI-3). */
  photo_url: string | null
  photo_url_expires_at: string | null
  contact_phone: string | null
  document: PickupMaskedDocument | null
}

export interface PickupList {
  data: PickupListEntry[]
  meta: { as_of: string }
}

/** `api.md §8.1`. Nunca `ip_address` ni `user_agent`. */
export interface PickupAuthorization {
  public_id: PublicId
  student_person: PublicId
  person: { public_id: PublicId; display_name: string }
  relationship: PickupRelationship | string
  kind: PickupAuthorizationKind | string
  valid_from: string
  valid_until: string | null
  /** Solo con ámbito `todos` (`D-53`): si no, se omite. */
  reason?: string | null
  authorized_by: PublicId
  terms_version: { public_id: PublicId; kind: string; version_number: number }
  channel: PickupChannel | string
  authorized_at: string
  recorded_by: PublicId
  is_effective: boolean
  ineffective_reason: PickupIneffectiveReason | string | null
  withdrawn_at: string | null
  created_at: string
}

/** `api.md §8.1`: `reused` y `data_mismatch` solo en la respuesta de `POST /pickup-persons`. */
export interface PickupPerson {
  person: PublicId
  display_name?: string
  document?: PickupFullDocument | null
  contact_phone?: string | null
  has_photo?: boolean
  photo_updated_at?: string | null
  reused?: boolean
  data_mismatch?: boolean
}

export interface PickupPhotoUrl {
  url: string
  expires_at: string
}

export interface CreatePickupPersonBody {
  given_name: string
  family_name_1: string
  family_name_2?: string | null
  document_type?: string | null
  document_number?: string | null
  contact_phone?: string | null
}

/** `PATCH /pickup-persons/{id}`: al menos un campo; el documento no se edita (`422`). */
export interface UpdatePickupPersonBody {
  given_name?: string
  family_name_1?: string
  family_name_2?: string | null
  contact_phone?: string | null
}

export interface CreatePickupAuthorizationBody {
  student_person: PublicId
  person: PublicId
  relationship: PickupRelationship | string
  kind: PickupAuthorizationKind | string
  valid_from: string
  valid_until?: string | null
  reason?: string | null
  authorized_by: PublicId
  terms_version: PublicId
  channel: PickupChannel | string
  authorized_at?: string
}
