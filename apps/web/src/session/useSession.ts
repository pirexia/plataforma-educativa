/**
 * `docs/modulos/REQ-CORE/funcional.md §12.3.4`, `docs/adr/ADR-053 §6`.
 * Estado de sesión de la SPA: singleton de ámbito de módulo (`shallowRef`
 * a nivel de fichero), mismo patrón que `useTenantBranding`
 * (`docs/design-system.md §7.1`, `ADR-052`) — sin Pinia. **Nunca** se
 * guarda nada de esto en `localStorage`/`sessionStorage` (`RN-AUTH-28`,
 * `CA-CORE-151`).
 *
 * Única fuente: `GET /me` (`core/api`). `apiFetch` es genérico y no sabe
 * de sesión: es este módulo el que decide, a partir de cada `ApiError`,
 * si toca vaciar el estado (`401`), marcar el muro de MFA
 * (`403 mfa-enrollment-required`) o recargar `/me` (cualquier otro `403`,
 * `ADR-053 §6`) — `src/api/client.ts` solo avisa mediante una importación
 * dinámica (evita el ciclo `client → session → core/api → client`).
 */
import { shallowRef, triggerRef } from 'vue'
import { ApiError } from '@/api/client'
import { getMe, updateMe } from '@/modules/core/api'
import type { User } from '@/modules/core/types'

export type SessionStatus = 'idle' | 'loading' | 'ready' | 'anonymous' | 'mfa-required' | 'error'

export interface SessionErrorInfo {
  status: number
  requestId?: string
  retryAfterSeconds?: number
}

const MFA_ENROLLMENT_REQUIRED_TYPE = 'urn:pge:error:mfa-enrollment-required'
const MODULE_DISABLED_TYPE = 'urn:pge:error:module-disabled'

const user = shallowRef<User | null>(null)
const status = shallowRef<SessionStatus>('idle')
const error = shallowRef<SessionErrorInfo | null>(null)
/**
 * `ADR-053 §6`, restricción: si la recarga la disparó un
 * `module-disabled` y la ruta actual deja de estar permitida tras ella, la
 * vista conserva este mensaje hasta la siguiente navegación. `null` si no
 * aplica. El *router* lo limpia en cada `beforeEach` (`src/router/index.ts`).
 */
const moduleUnavailableDetail = shallowRef<string | null>(null)

let inFlight: Promise<void> | null = null

function bodyType(body: unknown): string | null {
  if (typeof body === 'object' && body !== null && 'type' in body) {
    const value = (body as { type?: unknown }).type
    return typeof value === 'string' ? value : null
  }
  return null
}

function bodyDetail(body: unknown): string | undefined {
  if (typeof body === 'object' && body !== null && 'detail' in body) {
    const value = (body as { detail?: unknown }).detail
    return typeof value === 'string' ? value : undefined
  }
  return undefined
}

function bodyRequestId(body: unknown): string | undefined {
  if (typeof body === 'object' && body !== null && 'request_id' in body) {
    const value = (body as { request_id?: unknown }).request_id
    return typeof value === 'string' ? value : undefined
  }
  return undefined
}

function toErrorInfo(err: unknown): SessionErrorInfo {
  if (err instanceof ApiError) {
    const requestId = bodyRequestId(err.body)
    const retryAfter = err.headers.get('Retry-After')
    return {
      status: err.status,
      requestId,
      retryAfterSeconds:
        retryAfter !== null && !Number.isNaN(Number(retryAfter)) ? Number(retryAfter) : undefined,
    }
  }
  return { status: 0 }
}

async function fetchMe(): Promise<void> {
  status.value = 'loading'

  try {
    const me = await getMe()
    user.value = me
    status.value = 'ready'
    error.value = null
  } catch (err) {
    if (err instanceof ApiError && err.status === 401) {
      user.value = null
      status.value = 'anonymous'
      error.value = null
      return
    }

    if (
      err instanceof ApiError &&
      err.status === 403 &&
      bodyType(err.body) === MFA_ENROLLMENT_REQUIRED_TYPE
    ) {
      user.value = null
      status.value = 'mfa-required'
      error.value = null
      return
    }

    status.value = 'error'
    error.value = toErrorInfo(err)
  }
}

/**
 * Llamada por el *guard* al entrar por primera vez en una ruta `app`/`bare`
 * (`funcional.md §12.3.1`). Si ya hay un estado resuelto (`ready`,
 * `anonymous`, `mfa-required`, `error`), no repite la petición: solo
 * `reloadSession()` fuerza una nueva.
 */
export function ensureSessionLoaded(): Promise<void> {
  if (status.value !== 'idle' && status.value !== 'loading') {
    return Promise.resolve()
  }

  if (!inFlight) {
    inFlight = fetchMe().finally(() => {
      inFlight = null
    })
  }

  return inFlight
}

/**
 * `RN-CORE-26`, `ADR-053 §6`: recarga deduplicada — varias llamadas
 * concurrentes comparten la misma petición en vuelo.
 */
export function reloadSession(): Promise<void> {
  if (!inFlight) {
    inFlight = fetchMe().finally(() => {
      inFlight = null
    })
  }

  return inFlight
}

/**
 * Invocado por `src/api/client.ts` ante cualquier `403` que no sea
 * `mfa-enrollment-required` (`ADR-053 §6`). Dispara la recarga
 * deduplicada y, si el cuerpo es `module-disabled`, aplica la restricción
 * de §6: si tras recargar la ruta actual ya no está permitida, deja el
 * mensaje del servidor en `moduleUnavailableDetail` hasta la siguiente
 * navegación. La comprobación de "¿sigue permitida la ruta actual?" la
 * hace quien llama (el router o la vista), no este módulo — aquí solo se
 * guarda el `detail` a la espera; `src/router/index.ts` decide si aplica.
 */
export async function reloadSessionAfterForbidden(body: unknown): Promise<void> {
  const moduleDisabled = bodyType(body) === MODULE_DISABLED_TYPE
  const detail = moduleDisabled ? (bodyDetail(body) ?? '') : null

  await reloadSession()

  if (moduleDisabled) {
    moduleUnavailableDetail.value = detail
  }
}

export function setModuleUnavailableDetail(detail: string | null): void {
  moduleUnavailableDetail.value = detail
}

export function clearModuleUnavailableDetail(): void {
  moduleUnavailableDetail.value = null
}

/** `RN-CORE-26`: aplica la respuesta de un `PATCH /me` correcto sin una segunda petición. */
export async function updateSessionLocale(locale: string): Promise<User> {
  const updated = await updateMe({ person: { locale } })
  user.value = updated
  triggerRef(user)
  return updated
}

/** `RN-CORE-27`: vacía el estado aunque `DELETE /auth/session` falle. */
export function clearSession(): void {
  user.value = null
  status.value = 'anonymous'
  error.value = null
  moduleUnavailableDetail.value = null
}

export function useSession() {
  return {
    user,
    status,
    error,
    moduleUnavailableDetail,
    ensureSessionLoaded,
    reloadSession,
    reloadSessionAfterForbidden,
    clearModuleUnavailableDetail,
    updateSessionLocale,
    clearSession,
  }
}
