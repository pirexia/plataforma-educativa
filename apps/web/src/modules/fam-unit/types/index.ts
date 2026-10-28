/**
 * REQ-FAM-UNIT (paso 1.14-c). Tipos del módulo, en la forma exacta en que la API los entrega (`ADR-038 §3`: snake_case,
 * sin mapeo a camelCase). Coherentes con `apps/api/openapi/paths/fam-unit.yaml` (`api.md §1.7`, `§1.8`, `§2.8`).
 */

export type PublicId = string

/** `api.md §1.7`: el texto es contenido del centro, ya resuelto por el servidor en el idioma del usuario (`ADR-062`). */
export interface TermsVersion {
  public_id: PublicId
  kind: 'condiciones_uso' | 'consentimiento_imagen_web' | 'consentimiento_imagen_redes'
  version_number: number
  title: string
  body: string
  status: 'borrador' | 'publicada' | 'retirada'
  requires_reacceptance: boolean
  published_at: string | null
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
  shown_locale: 'es-ES' | 'en' | 'de' | 'fr'
  accepted_at: string
}
