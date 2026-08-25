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
