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
 * `XSRF-TOKEN` no es `httpOnly`: se lee del `document.cookie` del propio
 * navegador, nunca de una cabecera de respuesta ni de almacenamiento
 * propio (RN-AUTH-28: prohibido guardar nada de sesión en
 * `localStorage`/`sessionStorage`).
 */
function readXsrfCookie(): string | null {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)

  return match ? decodeURIComponent(match[1]) : null
}

export async function apiFetch<T>(path: string, init: RequestInit = {}): Promise<T> {
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
    throw new ApiError(
      `La API respondió ${response.status}`,
      response.status,
      body,
      response.headers,
    )
  }

  return body as T
}
