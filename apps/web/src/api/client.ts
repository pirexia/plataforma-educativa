/**
 * Cliente HTTP mínimo. Cookie de sesión httpOnly (ADR-025): las peticiones
 * autenticadas van con `credentials: 'include'`. El manejo de CSRF llega
 * con REQ-AUTH (paso 1.2); por ahora este cliente solo cubre errores de
 * red y de la API.
 */

export class ApiError extends Error {
  readonly status: number
  readonly body: unknown

  constructor(message: string, status: number, body: unknown) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.body = body
  }
}

const baseUrl = import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api/v1'

export async function apiFetch<T>(path: string, init: RequestInit = {}): Promise<T> {
  let response: Response

  const isFormData = init.body instanceof FormData

  const headers: HeadersInit = {
    Accept: 'application/problem+json, application/json',
    ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
    ...init.headers,
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
    throw new ApiError(`La API respondió ${response.status}`, response.status, body)
  }

  return body as T
}
