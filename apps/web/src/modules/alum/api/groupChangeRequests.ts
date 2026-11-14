/**
 * REQ-ALUM (paso 1.15-d, T2a; `docs/modulos/REQ-ALUM/api.md §3`). Solicitudes de cambio de grupo. Sin
 * `Idempotency-Key` (`RN-ALUM-27`): el reintento es `422 request_already_pending` o `409 request_closed`.
 */
import { apiFetch } from '@/api/client'
import type {
  GroupChangeCreate,
  GroupChangeDecision,
  GroupChangeRequest,
  Paginated,
  PublicId,
} from '../types'
import { buildQuery, encode } from './query'

export interface ListGroupChangeRequestsParams {
  /** Obligatorio para el servidor (`api.md §3`). */
  academic_year?: PublicId
  status?: string
  group?: PublicId
  page?: number
  per_page?: number
}

/** `GET /group-change-requests` (`matricula.leer`, ámbito; `unidad_familiar` ⇒ lista vacía). */
export function listGroupChangeRequests(
  params: ListGroupChangeRequestsParams = {},
): Promise<Paginated<GroupChangeRequest>> {
  return apiFetch<Paginated<GroupChangeRequest>>(
    `/group-change-requests${buildQuery({ ...params })}`,
  )
}

/** `GET /group-change-requests/{id}` (`matricula.leer`, ámbito). */
export function getGroupChangeRequest(publicId: PublicId): Promise<GroupChangeRequest> {
  return apiFetch<GroupChangeRequest>(`/group-change-requests/${encode(publicId)}`)
}

/** `POST /group-change-requests` (`matricula.actualizar`): `201`. */
export function createGroupChangeRequest(body: GroupChangeCreate): Promise<GroupChangeRequest> {
  return apiFetch<GroupChangeRequest>('/group-change-requests', {
    method: 'POST',
    body: JSON.stringify(body),
  })
}

function decide(
  publicId: PublicId,
  action: 'approval' | 'rejection' | 'cancellation',
  body: GroupChangeDecision,
): Promise<GroupChangeRequest> {
  return apiFetch<GroupChangeRequest>(`/group-change-requests/${encode(publicId)}/${action}`, {
    method: 'POST',
    body: JSON.stringify(body),
  })
}

/** `POST …/approval` (`matricula.aprobar`): `decision_note` opcional; `403` si es el solicitante (`RN-ALUM-44`). */
export function approveGroupChangeRequest(
  publicId: PublicId,
  body: GroupChangeDecision = {},
): Promise<GroupChangeRequest> {
  return decide(publicId, 'approval', body)
}

/** `POST …/rejection` (`matricula.aprobar`): `decision_note` obligatoria. */
export function rejectGroupChangeRequest(
  publicId: PublicId,
  body: GroupChangeDecision,
): Promise<GroupChangeRequest> {
  return decide(publicId, 'rejection', body)
}

/** `POST …/cancellation` (`matricula.actualizar`): solo `pendiente`. */
export function cancelGroupChangeRequest(
  publicId: PublicId,
  body: GroupChangeDecision = {},
): Promise<GroupChangeRequest> {
  return decide(publicId, 'cancellation', body)
}
