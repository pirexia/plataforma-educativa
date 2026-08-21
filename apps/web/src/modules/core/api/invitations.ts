import { apiFetch } from '@/api/client'
import { buildQuery } from './shared'
import type { Invitation, InvitationStatus, Paginated, PublicId } from '../types'

export interface ListInvitationsParams {
  status?: InvitationStatus
  page?: number
  per_page?: number
}

export function listInvitations(
  params: ListInvitationsParams = {},
): Promise<Paginated<Invitation>> {
  const query = buildQuery({ status: params.status, page: params.page, per_page: params.per_page })

  return apiFetch<Paginated<Invitation>>(`/invitations${query}`)
}

/** Emite o reemite. Revoca la invitación viva anterior (RN-CORE-09). */
export function issueInvitation(userPublicId: PublicId): Promise<Invitation> {
  return apiFetch<Invitation>(`/users/${userPublicId}/invitations`, { method: 'POST' })
}

export function revokeInvitation(publicId: PublicId): Promise<void> {
  return apiFetch<void>(`/invitations/${publicId}`, { method: 'DELETE' })
}
