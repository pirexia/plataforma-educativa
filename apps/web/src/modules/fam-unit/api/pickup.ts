/**
 * REQ-FAM-UNIT (paso 1.14b-c1, `api.md §8.1` a `§8.3`). Personas autorizadas a recoger, autorizaciones y lista vigente,
 * en la forma exacta de `apps/api/openapi/paths/fam-unit.yaml` (rutas `pickup-*`). Permisos de cada ruta:
 * `permisos.md §8`; la interfaz oculta, el servidor decide (`INV-002`).
 *
 * Lecturas que escriben `read` en la auditoría y gastan el cupo de 300/h (`RN-FAM-UNIT-92`/`-93`): `getPickupList`,
 * `findPickupPerson` (con coincidencia), `getPickupPerson` y `getPickupPhotoUrl`. Solo se llaman por una acción
 * explícita del usuario (`funcional.md §23.2` UI-2): nunca al montar, ni por sondeo ni por *prefetch*.
 */
import { apiFetch } from '@/api/client'
import type {
  CreatePickupAuthorizationBody,
  CreatePickupPersonBody,
  Paginated,
  PickupAuthorization,
  PickupAuthorizationKind,
  PickupList,
  PickupPerson,
  PickupPhotoUrl,
  PublicId,
  UpdatePickupPersonBody,
} from '../types'
import { buildQuery, encode } from './query'

export interface ListPickupAuthorizationsParams {
  /** Uno o varios `public_id` de persona de alumno separados por coma. */
  student_person?: string
  person?: string
  kind?: PickupAuthorizationKind
  /** `true` ⇒ solo vigentes ahora; `false` ⇒ solo no vigentes. */
  effective?: boolean
  /** `valid_from` (defecto, descendente) o `created_at`, con prefijo `-` para descendente. */
  sort?: string
  page?: number
  per_page?: number
}

/** `GET /pickup-lists/{studentPersonPublicId}` (`autorizado_recogida.leer`, ámbito): **auditada** y con cupo (`429`). */
export function getPickupList(studentPersonPublicId: PublicId): Promise<PickupList> {
  return apiFetch<PickupList>(`/pickup-lists/${encode(studentPersonPublicId)}`)
}

/** `GET /pickup-authorizations` (`autorizado_recogida.leer`, ámbito). No escribe `read`. */
export function listPickupAuthorizations(
  params: ListPickupAuthorizationsParams = {},
): Promise<Paginated<PickupAuthorization>> {
  return apiFetch<Paginated<PickupAuthorization>>(
    `/pickup-authorizations${buildQuery({ ...params })}`,
  )
}

/** `GET /pickup-authorizations/{publicId}` (`autorizado_recogida.leer`, ámbito). */
export function getPickupAuthorization(publicId: PublicId): Promise<PickupAuthorization> {
  return apiFetch<PickupAuthorization>(`/pickup-authorizations/${encode(publicId)}`)
}

/** `POST /pickup-authorizations` (`autorizado_recogida.crear`): `201`. */
export function createPickupAuthorization(
  body: CreatePickupAuthorizationBody,
): Promise<PickupAuthorization> {
  return apiFetch<PickupAuthorization>('/pickup-authorizations', {
    method: 'POST',
    body: JSON.stringify(body),
  })
}

/** `PATCH /pickup-authorizations/{publicId}` (`autorizado_recogida.actualizar`): solo `valid_until` de una `permanente`. */
export function updatePickupAuthorizationValidity(
  publicId: PublicId,
  validUntil: string | null,
): Promise<PickupAuthorization> {
  return apiFetch<PickupAuthorization>(`/pickup-authorizations/${encode(publicId)}`, {
    method: 'PATCH',
    body: JSON.stringify({ valid_until: validUntil }),
  })
}

/** `POST /pickup-authorizations/{publicId}/withdrawal` (`autorizado_recogida.eliminar`): irreversible (`RN-FAM-UNIT-87`). */
export function withdrawPickupAuthorization(publicId: PublicId): Promise<PickupAuthorization> {
  return apiFetch<PickupAuthorization>(`/pickup-authorizations/${encode(publicId)}/withdrawal`, {
    method: 'POST',
  })
}

/**
 * `GET /pickup-persons?document_type=&document_number=` (`autorizado_recogida.actualizar`): colección de 0 o 1; con
 * coincidencia, **auditada** y con cubo de frecuencia propio (`429`).
 */
export function findPickupPerson(
  documentType: string,
  documentNumber: string,
): Promise<{ data: PickupPerson[] }> {
  return apiFetch<{ data: PickupPerson[] }>(
    `/pickup-persons${buildQuery({ document_type: documentType, document_number: documentNumber })}`,
  )
}

/** `POST /pickup-persons` (`autorizado_recogida.crear`): `201`, o `200` con `reused = true` (`RN-FAM-UNIT-89`). */
export function createPickupPerson(body: CreatePickupPersonBody): Promise<PickupPerson> {
  return apiFetch<PickupPerson>('/pickup-persons', { method: 'POST', body: JSON.stringify(body) })
}

/** `GET /pickup-persons/{personPublicId}` (`autorizado_recogida.actualizar`): documento completo, **auditada**. */
export function getPickupPerson(personPublicId: PublicId): Promise<PickupPerson> {
  return apiFetch<PickupPerson>(`/pickup-persons/${encode(personPublicId)}`)
}

/** `PATCH /pickup-persons/{personPublicId}` (`autorizado_recogida.actualizar`): el documento no es editable (`422`). */
export function updatePickupPerson(
  personPublicId: PublicId,
  body: UpdatePickupPersonBody,
): Promise<PickupPerson> {
  return apiFetch<PickupPerson>(`/pickup-persons/${encode(personPublicId)}`, {
    method: 'PATCH',
    body: JSON.stringify(body),
  })
}

/**
 * `PUT /pickup-persons/{personPublicId}/photo` (`autorizado_recogida.actualizar`): `multipart/form-data` con el campo
 * `file`. Sin `Content-Type` a mano: `apiFetch` deja que el navegador fije el `boundary` cuando el cuerpo es `FormData`.
 */
export function putPickupPersonPhoto(personPublicId: PublicId, file: File): Promise<PickupPerson> {
  const form = new FormData()

  form.append('file', file)

  return apiFetch<PickupPerson>(`/pickup-persons/${encode(personPublicId)}/photo`, {
    method: 'PUT',
    body: form,
  })
}

/** `GET /pickup-persons/{personPublicId}/photo` (`autorizado_recogida.leer`, ámbito): URL firmada de 5 minutos, **auditada**. */
export function getPickupPhotoUrl(personPublicId: PublicId): Promise<PickupPhotoUrl> {
  return apiFetch<PickupPhotoUrl>(`/pickup-persons/${encode(personPublicId)}/photo`)
}

/** `DELETE /pickup-persons/{personPublicId}/photo` (`autorizado_recogida.actualizar`): `204`. */
export function deletePickupPersonPhoto(personPublicId: PublicId): Promise<null> {
  return apiFetch<null>(`/pickup-persons/${encode(personPublicId)}/photo`, { method: 'DELETE' })
}
