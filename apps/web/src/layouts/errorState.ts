/**
 * `docs/modulos/REQ-CORE/funcional.md §12.6`, `ADR-038 §6`. Única función
 * de correspondencia entre una respuesta de la API y el estado que se
 * pinta. `null` significa «ningún estado de aplicación»: `401` (el *guard*
 * ya redirige a `/entrar`) y `403 mfa-enrollment-required` (el *guard* ya
 * lleva al muro) — ninguno de los dos se pinta como error de vista.
 */
import { ApiError } from '@/api/client'

export type ShellErrorKind =
  'offline' | 'forbidden' | 'module-disabled' | 'not-found' | 'too-many-requests' | 'unexpected'

export interface ShellErrorState {
  kind: ShellErrorKind
  requestId?: string
  /** Solo con `kind === 'too-many-requests'` y cabecera `Retry-After` presente. */
  retryAfterSeconds?: number
  /** Solo con `kind === 'module-disabled'`: el `detail` traducido del servidor (`RMOD-009`). */
  detail?: string
}

const MFA_ENROLLMENT_REQUIRED_TYPE = 'urn:pge:error:mfa-enrollment-required'
const MODULE_DISABLED_TYPE = 'urn:pge:error:module-disabled'

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

export function resolveErrorState(err: unknown): ShellErrorState | null {
  if (!(err instanceof ApiError)) {
    return { kind: 'unexpected' }
  }

  const { status, body, headers } = err
  const requestId = bodyRequestId(body)

  if (status === 0) {
    return { kind: 'offline' }
  }

  if (status === 401) {
    return null
  }

  if (status === 403) {
    if (bodyType(body) === MFA_ENROLLMENT_REQUIRED_TYPE) {
      return null
    }

    if (bodyType(body) === MODULE_DISABLED_TYPE) {
      return { kind: 'module-disabled', detail: bodyDetail(body), requestId }
    }

    return { kind: 'forbidden', requestId }
  }

  if (status === 404) {
    return { kind: 'not-found', requestId }
  }

  if (status === 429) {
    const retryAfter = headers.get('Retry-After')
    const retryAfterSeconds =
      retryAfter !== null && !Number.isNaN(Number(retryAfter)) ? Number(retryAfter) : undefined

    return { kind: 'too-many-requests', retryAfterSeconds, requestId }
  }

  if (status >= 500) {
    return { kind: 'unexpected', requestId }
  }

  // Cualquier otro código (4xx no listado): tratado como inesperado antes
  // que silencioso (`ADR-038 §7.3`: un valor no anticipado no debe
  // romper, pero tampoco desaparecer).
  return { kind: 'unexpected', requestId }
}

/**
 * Conversión desde `SessionErrorInfo` (`src/session/useSession.ts`), que
 * ya extrajo lo que necesitaba de la `ApiError` original — el estado de
 * sesión no guarda la excepción completa. Misma correspondencia de
 * `§12.6`, sin necesitar la instancia de `ApiError`.
 */
export function sessionErrorToShellError(info: {
  status: number
  requestId?: string
  retryAfterSeconds?: number
}): ShellErrorState {
  if (info.status === 0) {
    return { kind: 'offline' }
  }

  if (info.status === 429) {
    return {
      kind: 'too-many-requests',
      retryAfterSeconds: info.retryAfterSeconds,
      requestId: info.requestId,
    }
  }

  return { kind: 'unexpected', requestId: info.requestId }
}
