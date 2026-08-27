/**
 * REQ-AUTH (paso 1.2). Tipos del módulo, en la forma exacta en que la API
 * los entrega (ADR-038 §3: snake_case, sin mapeo a camelCase). Coherentes
 * con `apps/api/openapi/paths/auth.yaml` y con
 * `App\Support\Api\UserProfilePresenter`, que es la fuente de verdad
 * (INV-006).
 *
 * INV-007: un módulo no importa código interno de otro. `PublicId` y la
 * forma de `problem+json` ya existen en `modules/core/types`, pero ese
 * fichero no forma parte de la superficie pública de `REQ-CORE`
 * (`core/api/index.ts` no lo reexporta) — así que se duplican aquí, no se
 * importan. Son alias de valor sin lógica: la duplicación no genera
 * deriva real entre módulos.
 */

export type PublicId = string

/**
 * api.md §2, funcional.md §4.2 punto 6.6: el mismo recurso que `GET /me`
 * de `REQ-CORE`, ensamblado por `App\Support\Api\UserProfilePresenter` —
 * compartido, no interno de `REQ-CORE` (`INV-007`).
 */
export interface SessionUser {
  public_id: PublicId
  email: string
  status: 'pendiente' | 'activo' | 'inactivo'
  person: {
    public_id: PublicId
    given_name: string
    family_name_1: string
    family_name_2: string | null
    contact_email: string | null
    contact_phone: string | null
    locale: string
  }
  roles: { public_id: PublicId; code: string; name: string }[]
  permissions: string[]
  /**
   * REQ-AUTH (1.3), api.md §C.6: bloque añadido de forma aditiva por
   * `UserProfilePresenter`. Opcional en el tipo porque `ADR-038 §7.3`
   * garantiza que un cliente antiguo puede ignorarlo, pero el servidor lo
   * envía siempre desde 1.3.
   */
  mfa?: MfaBlock
}

/**
 * REQ-AUTH (1.3), api.md §C.5, §C.8.5. `email`/`sms` viajan como valores
 * de forma válidos aunque el servidor los rechace en 1.3 (`§C.1`): el
 * cliente no puede asumir que solo existirá `totp`.
 */
export type MfaMethod = 'totp' | 'email' | 'sms'

/**
 * api.md §C.4, §C.6. El mismo bloque que lleva `GET /me` y el `200` de
 * `POST /auth/session` — sostiene los avisos "en cada acceso" del plazo
 * de gracia (funcional.md §C.4.8) sin endpoint ni correo dedicados.
 */
export interface MfaBlock {
  enrolled: boolean
  obligated: boolean
  enforced: boolean
  grace_deadline_at: string | null
  days_remaining: number | null
}

/**
 * api.md §C.2: cuerpo del `202` de `POST /auth/session`, y el mismo
 * recurso que devuelve `POST /auth/mfa-challenges` al cambiar de método o
 * reenviar (sin `destination_masked` en 1.3: no hay método de entrega
 * dado de alta, `§C.3`).
 */
export interface MfaChallenge {
  public_id: PublicId
  method: MfaMethod
  available_methods: MfaMethod[]
  expires_at: string
  has_unused_recovery_codes: boolean
  destination_masked?: string | null
}

/** api.md §C.4 `GET /auth/mfa`: un factor confirmado. Un alta a medias nunca aparece aquí (RN-AUTH-59). */
export interface MfaFactorSummary {
  public_id: PublicId
  method: MfaMethod
  confirmed_at: string
  last_used_at: string | null
  is_preferred: boolean
}

/** api.md §C.4 `GET /auth/mfa`: mi estado de MFA completo. */
export interface MfaStatus {
  factors: MfaFactorSummary[]
  unused_recovery_codes_count: number
  mfa: MfaBlock
}

/** api.md §C.4 `POST /auth/mfa-enrollments`, respuesta `201`. */
export interface MfaEnrollment {
  public_id: PublicId
  method: MfaMethod
  secret: string
  otpauth_uri: string
  expires_at: string
}

/**
 * api.md §C.4 `POST /auth/mfa-factors`, respuesta `201`.
 * `recovery_codes` solo viene informado si este era el primer factor
 * confirmado del usuario (funcional.md §C.4.3) — es la única vez que
 * salen del servidor.
 */
export interface MfaFactorConfirmation {
  factor: { public_id: PublicId; method: MfaMethod; confirmed_at: string }
  recovery_codes: string[] | null
}

/** ADR-038 §6.3. `message` ya viene traducido por el servidor. */
export interface AuthProblemErrorEntry {
  code: string
  message: string
  params?: Record<string, unknown>
}

/** ADR-038 §6. Forma de `application/problem+json`. */
export interface AuthProblemBody {
  type: string
  title: string
  status: number
  detail?: string
  instance?: string
  request_id?: string
  errors?: Record<string, AuthProblemErrorEntry[]>
}
