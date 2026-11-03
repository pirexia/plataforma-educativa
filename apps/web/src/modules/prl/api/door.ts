/**
 * `REQ-PRL` (paso 1.14b-c2 T1, `docs/modulos/REQ-PRL/api.md §8.4`-`§8.7`). Lectura de la puerta: búsqueda de alumno,
 * lista de puerta y alertas, en la forma exacta de `apps/api/openapi/paths/prl.yaml`. La interfaz oculta, el servidor
 * decide (`INV-002`).
 *
 * Lecturas que escriben `read` en la auditoría y gastan el cupo de `prl` (`DP-11`, `RN-FAM-UNIT-93`):
 * `getDoorPickupList`, `getPickupAlert` (abierta) y `listStudentMovements`/`getStudentMovement`. Solo se llaman por una
 * acción explícita del usuario (`funcional.md §23.2` UI-2): nunca al montar sin ella, ni por sondeo ni por *prefetch*.
 * Las lecturas con datos de personas van con `cache: 'no-store'` (UI-3).
 */
import { apiFetch } from '@/api/client'
import type {
  CursorPaginated,
  DoorPickupList,
  DoorStudentList,
  Paginated,
  PickupAlert,
  PickupAlertCreate,
  PickupAlertStatus,
  PickupAlertUpdate,
  PublicId,
  StudentMovement,
  StudentMovementCreate,
} from '../types'
import { buildQuery, encode } from './query'

const NO_STORE: RequestInit = { cache: 'no-store' }

export interface ListPickupAlertsParams {
  status?: PickupAlertStatus | string
  /** Fecha civil del centro (`AAAA-MM-DD`). */
  date?: string
  student_person?: PublicId
  page?: number
  per_page?: number
}

/**
 * `GET /door/students?q=` (`entrega_menor.leer`): de 2 a 100 caracteres, máximo 20 resultados. `signal` cancela la
 * petición anterior cuando el término cambia (la respuesta obsoleta no debe pisar a la nueva).
 */
export function searchDoorStudents(q: string, signal?: AbortSignal): Promise<DoorStudentList> {
  return apiFetch<DoorStudentList>(`/door/students${buildQuery({ q })}`, { ...NO_STORE, signal })
}

/** `GET /door/students/{studentPersonPublicId}/pickup-list` (`entrega_menor.leer`): **auditada** (`puerta`) y con cupo. */
export function getDoorPickupList(studentPersonPublicId: PublicId): Promise<DoorPickupList> {
  return apiFetch<DoorPickupList>(
    `/door/students/${encode(studentPersonPublicId)}/pickup-list`,
    NO_STORE,
  )
}

export interface ListStudentMovementsParams {
  student_person?: PublicId
  /** Fecha civil del centro (`AAAA-MM-DD`). */
  date?: string
  direction?: string
  off_schedule?: boolean
  cursor?: string
  limit?: number
}

/** `GET /student-movements` (`entrega_menor.leer`): cursor opaco; **auditada** y con cupo (`DP-11`). */
export function listStudentMovements(
  params: ListStudentMovementsParams = {},
): Promise<CursorPaginated<StudentMovement>> {
  return apiFetch<CursorPaginated<StudentMovement>>(
    `/student-movements${buildQuery({ ...params })}`,
    NO_STORE,
  )
}

/** `GET /student-movements/{publicId}` (`entrega_menor.leer`): **auditada** y con cupo (`DP-11`). */
export function getStudentMovement(publicId: PublicId): Promise<StudentMovement> {
  return apiFetch<StudentMovement>(`/student-movements/${encode(publicId)}`, NO_STORE)
}

/** `GET /pickup-alerts` (`alerta_entrega.leer`): página de 25, `raised_at` descendente. No lleva teléfonos ni protocolo. */
export function listPickupAlerts(
  params: ListPickupAlertsParams = {},
): Promise<Paginated<PickupAlert>> {
  return apiFetch<Paginated<PickupAlert>>(`/pickup-alerts${buildQuery({ ...params })}`, NO_STORE)
}

/** `GET /pickup-alerts/{publicId}` (`alerta_entrega.leer`): abierta ⇒ protocolo y teléfonos, **auditada** y con cupo. */
export function getPickupAlert(publicId: PublicId): Promise<PickupAlert> {
  return apiFetch<PickupAlert>(`/pickup-alerts/${encode(publicId)}`, NO_STORE)
}

/**
 * `POST /student-movements` (`entrega_menor.crear`, `RN-PRL-09`, `DP-4`). `idempotencyKey`: una por intento de envío,
 * reutilizada en los reintentos del mismo cuerpo (`funcional.md §23.5.3`). Un `201` con `Idempotency-Replayed: true`
 * es el mismo resultado original y se trata como éxito normal.
 */
export function createStudentMovement(
  body: StudentMovementCreate,
  idempotencyKey: string,
): Promise<StudentMovement> {
  return apiFetch<StudentMovement>('/student-movements', {
    method: 'POST',
    headers: { 'Idempotency-Key': idempotencyKey },
    body: JSON.stringify(body),
  })
}

/** `POST /pickup-alerts` (`alerta_entrega.crear`): **escribe `read`** y gasta cupo (`DP-11`); devuelve protocolo y teléfonos. */
export function createPickupAlert(
  body: PickupAlertCreate,
  idempotencyKey: string,
): Promise<PickupAlert> {
  return apiFetch<PickupAlert>('/pickup-alerts', {
    method: 'POST',
    headers: { 'Idempotency-Key': idempotencyKey },
    body: JSON.stringify(body),
  })
}

/**
 * `PATCH /pickup-alerts/{publicId}` (`alerta_entrega.actualizar`, `DP-6`): `principal_contacted_at` y/o cierre con
 * `resolution`. **Sobre una alerta abierta escribe `read` y gasta cupo** (`DP-11`): un `PATCH` por acción del usuario.
 */
export function updatePickupAlert(
  publicId: PublicId,
  body: PickupAlertUpdate,
): Promise<PickupAlert> {
  return apiFetch<PickupAlert>(`/pickup-alerts/${encode(publicId)}`, {
    method: 'PATCH',
    body: JSON.stringify(body),
  })
}
