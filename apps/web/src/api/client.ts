/**
 * Cliente HTTP mínimo. Cookie de sesión httpOnly (ADR-025): las peticiones
 * autenticadas van con `credentials: 'include'`.
 *
 * REQ-AUTH (1.2), funcional.md §4.7, RN-AUTH-29: CSRF en toda escritura,
 * incluidos los endpoints anónimos. `XSRF-TOKEN` es legible por
 * JavaScript a propósito (no `httpOnly`): es el valor que se reenvía en
 * `X-XSRF-TOKEN`, mismo patrón que Laravel/Sanctum, sin la dependencia
 * (`api.md §2`, `CLAUDE.md §1`).
 */

export class ApiError extends Error {
  readonly status: number
  readonly body: unknown
  /**
   * REQ-AUTH (1.2), api.md §9.2: `429`/`503` llevan `Retry-After`. Antes
   * de este módulo ningún consumidor necesitaba cabeceras de respuesta,
   * así que `ApiError` no las exponía; se añaden aquí (framework
   * compartido, no específico de un módulo) en vez de duplicar
   * `apiFetch` dentro de `modules/auth`.
   */
  readonly headers: Headers

  constructor(message: string, status: number, body: unknown, headers: Headers = new Headers()) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.body = body
    this.headers = headers
  }
}

const baseUrl = import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api/v1'

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS'])

/**
 * REQ-AUTH (1.3), funcional.md §C.4.9, api.md §C.1.1: la sesión restringida
 * del muro de MFA responde este `type` en cualquier endpoint que no esté
 * en la lista blanca. Es el único `403` de todo el catálogo que la SPA
 * trata de forma especial — el resto de errores los interpreta cada
 * pantalla, pero este no puede esperar a que 1.8 construya un
 * interceptor genérico: sin redirigir aquí, cualquier pantalla nueva que
 * no sepa de este `type` deja al usuario mirando un error en vez de la
 * pantalla de la que "no se puede salir sin completar el registro".
 */
const MFA_ENROLLMENT_REQUIRED_TYPE = 'urn:pge:error:mfa-enrollment-required'
const MFA_ENROLLMENT_WALL_ROUTE_NAME = 'mfa-enrollment-wall'

function isMfaEnrollmentRequiredBody(body: unknown): boolean {
  return (
    typeof body === 'object' &&
    body !== null &&
    'type' in body &&
    (body as { type?: unknown }).type === MFA_ENROLLMENT_REQUIRED_TYPE
  )
}

/**
 * Importación dinámica y no estática a propósito: `client.ts` es
 * infraestructura genérica (fuera de `src/modules`) y `src/router` importa
 * en cascada las vistas de todos los módulos — una importación estática
 * aquí crearía un ciclo en tiempo de carga del módulo. La dinámica solo se
 * resuelve cuando de verdad hace falta redirigir.
 */
async function redirectToMfaEnrollmentWall(): Promise<void> {
  try {
    const { default: router } = await import('@/router')

    if (router.currentRoute.value.name !== MFA_ENROLLMENT_WALL_ROUTE_NAME) {
      await router.push({ name: MFA_ENROLLMENT_WALL_ROUTE_NAME })
    }
  } catch {
    // Entorno sin router (p.ej. un test unitario de este cliente): no hay
    // nada razonable que hacer, y no debe impedir que el ApiError original
    // llegue a quien hizo la llamada.
  }
}

/**
 * `XSRF-TOKEN` no es `httpOnly`: se lee del `document.cookie` del propio
 * navegador, nunca de una cabecera de respuesta ni de almacenamiento
 * propio (RN-AUTH-28: prohibido guardar nada de sesión en
 * `localStorage`/`sessionStorage`).
 */
function readXsrfCookie(): string | null {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)

  return match ? decodeURIComponent(match[1]) : null
}

export interface ApiResponseEnvelope<T> {
  status: number
  body: T
}

/**
 * Como `apiFetch`, pero conserva el código de estado de una respuesta
 * correcta. Hace falta para `POST /auth/session` (REQ-AUTH 1.3,
 * api.md §C.2): `200` y `202` son dos recursos de forma distinta bajo el
 * mismo endpoint, y `apiFetch` a secas no deja distinguirlos.
 */
export async function apiFetchWithStatus<T>(
  path: string,
  init: RequestInit = {},
): Promise<ApiResponseEnvelope<T>> {
  let response: Response

  const isFormData = init.body instanceof FormData
  const method = (init.method ?? 'GET').toUpperCase()

  const headers: HeadersInit = {
    Accept: 'application/problem+json, application/json',
    ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
    ...init.headers,
  }

  if (!SAFE_METHODS.has(method)) {
    const token = readXsrfCookie()

    if (token !== null) {
      ;(headers as Record<string, string>)['X-XSRF-TOKEN'] = token
    }
  }

  try {
    response = await fetch(`${baseUrl}${path}`, {
      ...init,
      credentials: 'include',
      headers,
    })
  } catch (cause) {
    throw new ApiError('No se pudo contactar con la API', 0, cause)
  }

  const body = await response.json().catch(() => null)

  if (!response.ok) {
    if (response.status === 403 && isMfaEnrollmentRequiredBody(body)) {
      void redirectToMfaEnrollmentWall()
    }

    throw new ApiError(
      `La API respondió ${response.status}`,
      response.status,
      body,
      response.headers,
    )
  }

  return { status: response.status, body: body as T }
}

export async function apiFetch<T>(path: string, init: RequestInit = {}): Promise<T> {
  const { body } = await apiFetchWithStatus<T>(path, init)

  return body
}
