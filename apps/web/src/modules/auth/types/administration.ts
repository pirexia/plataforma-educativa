/**
 * REQ-AUTH-003 (1.3b, pieza 3): tipos de las cuatro áreas de
 * `/administracion/mfa` (funcional.md §D.9.1). Ningún endpoint nuevo
 * (api.md §D.5.1) — estos tipos describen exactamente lo que ya
 * devuelven `GET /mfa-compliance`, `GET /mfa-compliance/users` y los
 * tres de `/mfa-exemptions`, entregados en las piezas 1.3 y 1.3b/2.
 *
 * `PageMeta`/paginación: mismo patrón que `modules/core/types`, pero
 * **duplicado a propósito**, no importado — ese fichero de `REQ-CORE` no
 * forma parte de su superficie pública (`core/api/index.ts` no lo
 * reexporta), mismo criterio que `types/index.ts` ya documenta para
 * `PublicId`/`problem+json` (INV-007).
 */
import type { MfaMethod, PublicId } from './index'

/**
 * Duplicado deliberado de `Role` de `modules/core/types` (mismo criterio
 * que `PublicId`/`problem+json` arriba, `INV-007`): solo los cuatro
 * campos que la pantalla de administración necesita del rol
 * (`PATCH /roles/{public_id}` sigue acotado a `mfa_required`, `api.md
 * §C.6`).
 */
export interface AdminRoleOption {
  public_id: PublicId
  code: string
  name: string
  mfa_required: boolean
}

export interface PageMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

/** api.md §C.1 `GET /mfa-compliance`. */
export interface MfaComplianceSummary {
  role: { public_id: PublicId; code: string }
  mfa_required: boolean
  preview: boolean
  users_total: number
  users_enrolled: number
  users_obligated: number
  users_in_grace: number
  users_enforced: number
  users_exempt: number
}

/** api.md §C.1: los cinco valores válidos como filtro de `GET /mfa-compliance/users`. */
export type MfaComplianceFilterState =
  'obligated' | 'enrolled' | 'pending' | 'past_deadline' | 'exempt'

/** api.md §C.1: los cuatro valores posibles de una fila — `obligated` es alias de filtro, nunca un valor de fila. */
export type MfaComplianceRowState = 'enrolled' | 'pending' | 'past_deadline' | 'exempt'

export interface MfaComplianceUserSummary {
  public_id: PublicId
  given_name: string
  family_name_1: string
  family_name_2: string | null
  email: string
}

export interface MfaComplianceUserEntry {
  user: MfaComplianceUserSummary
  state: MfaComplianceRowState
  grace_deadline_at: string | null
  enrolled_methods: MfaMethod[]
  required_by_roles: string[]
}

export interface MfaComplianceUsersPage {
  data: MfaComplianceUserEntry[]
  meta: PageMeta
}

/** api.md §D.4: los tres valores derivados de `state` en `/mfa-exemptions`. */
export type MfaExemptionState = 'live' | 'expired' | 'revoked'

export interface MfaExemptionActor {
  public_id: PublicId
  given_name: string
  family_name_1: string
}

/** api.md §D.4: recurso común a los tres endpoints de `/mfa-exemptions`. */
export interface MfaExemption {
  public_id: PublicId
  user: MfaComplianceUserSummary
  reason: string
  expires_at: string
  state: MfaExemptionState
  granted_by: MfaExemptionActor
  granted_at: string
  revoked_by: MfaExemptionActor | null
  revoked_at: string | null
}

export interface MfaExemptionsPage {
  data: MfaExemption[]
  meta: PageMeta
}
