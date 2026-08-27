/**
 * REQ-AUTH-003 (1.3b, pieza 3): cliente de los endpoints de
 * administración de MFA que consume `/administracion/mfa`
 * (funcional.md §D.9.1, api.md §D.5.1). **Ningún endpoint nuevo**: los
 * cuatro que siguen ya existían al terminar la pieza 2 —dos de 1.3
 * (`mfa-compliance`, `mfa-resets`) y tres de 1.3b (`mfa-exemptions`,
 * de los que este fichero usa los tres).
 */
import { apiFetch } from '@/api/client'
import { buildQuery, joinList } from '@/modules/core/api'
import type {
  MfaComplianceFilterState,
  MfaComplianceSummary,
  MfaComplianceUsersPage,
  MfaExemption,
  MfaExemptionsPage,
  MfaExemptionState,
  PublicId,
} from '../types'

/**
 * api.md §C.1 `GET /mfa-compliance`. Sin `mfaRequired`, el estado real
 * del rol; con él, la hipótesis (`preview: true`) que no escribe nada
 * (CA-AUTH-136) — es el mismo endpoint que alimenta el conmutador de
 * `mfa_required` con su vista previa de impacto.
 */
export function getMfaCompliance(params: {
  role: PublicId
  mfaRequired?: boolean
}): Promise<MfaComplianceSummary> {
  const query = buildQuery({ role: params.role, mfa_required: params.mfaRequired })

  return apiFetch<MfaComplianceSummary>(`/mfa-compliance${query}`)
}

/** api.md §C.1 `GET /mfa-compliance/users`: listado individualizado, paginado. */
export function getMfaComplianceUsers(
  params: { state?: MfaComplianceFilterState[]; page?: number; per_page?: number } = {},
): Promise<MfaComplianceUsersPage> {
  const query = buildQuery({
    state: joinList(params.state),
    page: params.page,
    per_page: params.per_page,
  })

  return apiFetch<MfaComplianceUsersPage>(`/mfa-compliance/users${query}`)
}

/**
 * api.md §C.5 `POST /mfa-resets`. `204` sin cuerpo: borra los factores
 * del usuario, cierra sus sesiones y reabre la obligación con plazo
 * completo si procede.
 */
export function createMfaReset(payload: { user: PublicId; reason: string }): Promise<void> {
  return apiFetch<void>('/mfa-resets', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

/** api.md §D.4 `POST /mfa-exemptions`. Concede una excepción temporal nominal. */
export function createMfaExemption(payload: {
  user: PublicId
  reason: string
  expires_at: string
}): Promise<MfaExemption> {
  return apiFetch<MfaExemption>('/mfa-exemptions', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

/** api.md §D.4 `GET /mfa-exemptions`: las vivas primero, paginado. */
export function listMfaExemptions(
  params: { state?: MfaExemptionState[]; user?: PublicId; page?: number } = {},
): Promise<MfaExemptionsPage> {
  const query = buildQuery({ state: joinList(params.state), user: params.user, page: params.page })

  return apiFetch<MfaExemptionsPage>(`/mfa-exemptions${query}`)
}

/** api.md §D.4 `DELETE /mfa-exemptions/{public_id}`. Sin cuerpo (§D.4: no hay reautenticación que pedir). */
export function revokeMfaExemption(publicId: PublicId): Promise<void> {
  return apiFetch<void>(`/mfa-exemptions/${publicId}`, {
    method: 'DELETE',
  })
}
