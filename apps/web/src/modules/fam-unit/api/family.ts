/**
 * REQ-FAM-UNIT (paso 1.14-d, `api.md §2.1` a `§2.5`). Unidades familiares, tutores, alumnado, vínculos y revocación,
 * en la forma exacta de `apps/api/openapi/paths/fam-unit.yaml`. Permisos de cada ruta: `permisos.md §2`.
 */
import { apiFetch } from '@/api/client'
import type {
  FamilyUnit,
  FamilyUnitStudent,
  Guardian,
  GuardianAccessLevel,
  GuardianRelationship,
  GuardianStudentLink,
  Paginated,
  PublicId,
} from '../types'
import { buildQuery, encode } from './query'

export interface ListFamilyUnitsParams {
  q?: string
  has_principal?: string
  sort?: string
  page?: number
  per_page?: number
}

/** `GET /family-units` (`familia.leer`, ámbito). */
export function listFamilyUnits(
  params: ListFamilyUnitsParams = {},
): Promise<Paginated<FamilyUnit>> {
  return apiFetch<Paginated<FamilyUnit>>(`/family-units${buildQuery({ ...params })}`)
}

export function getFamilyUnit(publicId: PublicId): Promise<FamilyUnit> {
  return apiFetch<FamilyUnit>(`/family-units/${encode(publicId)}`)
}

/** `POST /family-units` (`familia.crear`): sin `code` lo genera el sistema (`OPEN-FAM-UNIT-06`). */
export function createFamilyUnit(body: { code?: string }): Promise<FamilyUnit> {
  return apiFetch<FamilyUnit>('/family-units', { method: 'POST', body: JSON.stringify(body) })
}

/** `PATCH /family-units/{id}` (`familia.actualizar`): aquí solo el tutor principal. */
export function setPrincipalGuardian(
  publicId: PublicId,
  principalGuardian: PublicId | null,
): Promise<FamilyUnit> {
  return apiFetch<FamilyUnit>(`/family-units/${encode(publicId)}`, {
    method: 'PATCH',
    body: JSON.stringify({ principal_guardian: principalGuardian }),
  })
}

/** `GET /guardians?family_unit=` (`familia.leer`, ámbito). */
export function listGuardians(
  params: { family_unit?: PublicId; page?: number; per_page?: number } = {},
): Promise<Paginated<Guardian>> {
  return apiFetch<Paginated<Guardian>>(`/guardians${buildQuery({ ...params })}`)
}

/** `POST /guardians` (`familia.crear`): `user` es el `public_id` del **usuario**, no de la persona. */
export function createGuardian(body: { user: PublicId; family_unit: PublicId }): Promise<Guardian> {
  return apiFetch<Guardian>('/guardians', { method: 'POST', body: JSON.stringify(body) })
}

/** `DELETE /guardians/{id}` (`familia.eliminar`): `200 { removed_links, principal_cleared }` (`RN-FAM-UNIT-12`). */
export function deleteGuardian(
  publicId: PublicId,
): Promise<{ removed_links: number; principal_cleared: boolean }> {
  return apiFetch(`/guardians/${encode(publicId)}`, { method: 'DELETE' })
}

/** `GET /family-units/{id}/students` (`familia.leer`): colección sin paginar. */
export function listFamilyUnitStudents(
  familyUnit: PublicId,
): Promise<{ data: FamilyUnitStudent[] }> {
  return apiFetch(`/family-units/${encode(familyUnit)}/students`)
}

/** `POST /family-units/{id}/students` (`familia.actualizar`). */
export function addFamilyUnitStudent(
  familyUnit: PublicId,
  studentPerson: PublicId,
): Promise<FamilyUnitStudent> {
  return apiFetch<FamilyUnitStudent>(`/family-units/${encode(familyUnit)}/students`, {
    method: 'POST',
    body: JSON.stringify({ student_person: studentPerson }),
  })
}

/** `DELETE /family-units/{id}/students/{membership}` (`familia.actualizar`): `200 { remaining_links }`. */
export function removeFamilyUnitStudent(
  familyUnit: PublicId,
  membership: PublicId,
): Promise<{ remaining_links: number }> {
  return apiFetch(`/family-units/${encode(familyUnit)}/students/${encode(membership)}`, {
    method: 'DELETE',
  })
}

/** `GET /guardian-links` (`vinculo_tutor.leer`, ámbito). `status=revocado` exige además `revocacion_tutor.leer`. */
export function listGuardianLinks(
  params: { family_unit?: PublicId; status?: string; page?: number; per_page?: number } = {},
): Promise<Paginated<GuardianStudentLink>> {
  return apiFetch<Paginated<GuardianStudentLink>>(`/guardian-links${buildQuery({ ...params })}`)
}

export interface CreateGuardianLinkBody {
  guardian: PublicId
  student_person: PublicId
  relationship: GuardianRelationship
  is_legal_guardian: boolean
  access_level: GuardianAccessLevel
  restricted_categories: string[]
  is_emergency_contact: boolean
}

/** `POST /guardian-links` (`vinculo_tutor.crear`). */
export function createGuardianLink(body: CreateGuardianLinkBody): Promise<GuardianStudentLink> {
  return apiFetch<GuardianStudentLink>('/guardian-links', {
    method: 'POST',
    body: JSON.stringify(body),
  })
}

/** `POST /guardian-links/{id}/revocation` (`revocacion_tutor.crear`). Sin texto libre a propósito (`RN-FAM-UNIT-36`). */
export function revokeGuardianLink(
  publicId: PublicId,
  reason: string,
): Promise<GuardianStudentLink> {
  return apiFetch<GuardianStudentLink>(`/guardian-links/${encode(publicId)}/revocation`, {
    method: 'POST',
    body: JSON.stringify({ reason }),
  })
}

/** `DELETE /guardian-links/{id}/revocation` (`revocacion_tutor.eliminar`). */
export function liftGuardianLinkRevocation(publicId: PublicId): Promise<GuardianStudentLink> {
  return apiFetch<GuardianStudentLink>(`/guardian-links/${encode(publicId)}/revocation`, {
    method: 'DELETE',
  })
}
