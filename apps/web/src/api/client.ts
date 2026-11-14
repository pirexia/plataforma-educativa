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
 * `docs/adr/ADR-067-registro-de-muros-de-sesion.md §5.11` (antes, solo el muro de MFA, REQ-AUTH (1.3) funcional.md
 * §C.4.9): la sesión restringida por un muro (alta de MFA, aceptación de las condiciones...) responde un `403` cuyo
 * `type` identifica al muro en cualquier endpoint que no esté en su lista exenta. Es el único `403` que la SPA trata
 * de forma especial: el resto de errores los interpreta cada pantalla, pero este no puede esperar a que una
 * pantalla nueva sepa de él — sin redirigir aquí, el usuario se queda mirando un error en vez de la pantalla de la que
 * «no se puede salir sin cumplir la condición». Los muros los declara cada *shell* (`sessionWalls`); este cliente no
 * conoce ninguno.
 */
const ERROR_TYPE_PREFIX = 'urn:pge:error:'

function bodyErrorType(body: unknown): string | null {
  if (typeof body === 'object' && body !== null && 'type' in body) {
    const type = (body as { type?: unknown }).type

    return typeof type === 'string' && type.startsWith(ERROR_TYPE_PREFIX) ? type : null
  }

  return null
}

/**
 * Lleva al usuario a la pantalla del muro cuyo `403` acaba de llegar y dice si lo era. Importación dinámica y no
 * estática a propósito: `client.ts` es infraestructura genérica (fuera de `src/modules`) y `src/router` importa en
 * cascada las vistas de todos los módulos — una importación estática aquí crearía un ciclo en tiempo de carga del
 * módulo. La dinámica solo se resuelve cuando de verdad hace falta.
 *
 * Devuelve `false` si el `type` no es el de un muro registrado (o no hay router, p. ej. en un test unitario de este
 * cliente), para que el `403` siga el camino de `ADR-053 §6`.
 */
async function redirectToSessionWall(body: unknown): Promise<boolean> {
  const type = bodyErrorType(body)

  if (type === null) {
    return false
  }

  try {
    const { findSessionWallByErrorType } = await import('@/navigation/registry')
    const wall = findSessionWallByErrorType(type)

    if (wall === undefined) {
      return false
    }

    const { default: router } = await import('@/router')

    if (router.currentRoute.value.name !== wall.route) {
      await router.push({ name: wall.route })
    }

    return true
  } catch {
    // Entorno sin router (p.ej. un test unitario de este cliente): no hay
    // nada razonable que hacer, y no debe impedir que el ApiError original
    // llegue a quien hizo la llamada.
    return false
  }
}

/**
 * `docs/modulos/REQ-CORE/funcional.md §12.3.4`, `CA-CORE-092`: cualquier
 * petición (que no sea `GET /me`, cuyo propio `401` ya gestiona
 * `useSession`/el *guard* con el destino correcto) que reciba `401`
 * vacía el estado de sesión y navega **una sola vez** a `/entrar`, aunque
 * fallen varias peticiones a la vez (deduplicada con una promesa
 * compartida, mismo patrón que `reloadSession`).
 */
let unauthorizedInFlight: Promise<void> | null = null

export async function handleUnauthorized(): Promise<void> {
  if (!unauthorizedInFlight) {
    unauthorizedInFlight = doHandleUnauthorized().finally(() => {
      unauthorizedInFlight = null
    })
  }

  return unauthorizedInFlight
}

async function doHandleUnauthorized(): Promise<void> {
  try {
    const [{ clearSession }, { default: router }] = await Promise.all([
      import('@/session/useSession'),
      import('@/router'),
    ])

    clearSession()

    if (router.currentRoute.value.name === 'login') {
      return
    }

    const target = router.currentRoute.value.fullPath

    await router.push({ name: 'login', query: { redirect: target } })
  } catch {
    // Entorno sin router/sesión (p.ej. un test unitario aislado de este
    // cliente): no hay nada razonable que hacer.
  }
}

/**
 * `docs/modulos/REQ-CORE/funcional.md §12.3.4`,
 * `docs/adr/ADR-053-registro-de-navegacion-y-bloques-del-panel.md §6`:
 * cualquier `403` que no sea el de un muro de sesión registrado
 * (`ADR-067 §5.11`) recarga `GET /me` (deduplicada) — es la prueba de que los permisos en memoria están
 * desfasados. Importación dinámica por el mismo motivo que la anterior:
 * `src/session` importa `core/api`, que importa este fichero.
 */
async function notifySessionOfForbidden(body: unknown): Promise<void> {
  try {
    const { reloadSessionAfterForbidden } = await import('@/session/useSession')

    void reloadSessionAfterForbidden(body)
  } catch {
    // Entorno sin módulo de sesión (p.ej. un test unitario aislado de este
    // cliente): no hay nada razonable que hacer.
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
    if (response.status === 403) {
      void redirectToSessionWall(body).then((wasWall) => {
        if (!wasWall) {
          void notifySessionOfForbidden(body)
        }
      })
    } else if (response.status === 401 && path !== '/me') {
      void handleUnauthorized()
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

/**
 * Como `apiFetch`, pero para un recurso cuya negociación de contenido no
 * es JSON (REQ-AUTH-004, 1.4c, api.md §G.3: `GET .../metadata` con
 * `Accept: application/samlmetadata+xml` — el documento que el
 * administrador tiene que descargar y subir a su IdP). `apiFetch` fija
 * siempre `Accept: application/problem+json, application/json`, así que
 * no sirve para pedir esta forma. El cuerpo de error de esta API siempre
 * es JSON, firme o no la petición, así que el `catch` del `!response.ok`
 * es seguro.
 */
export async function apiFetchText(path: string, accept: string): Promise<string> {
  let response: Response

  try {
    response = await fetch(`${baseUrl}${path}`, {
      credentials: 'include',
      headers: { Accept: accept },
    })
  } catch (cause) {
    throw new ApiError('No se pudo contactar con la API', 0, cause)
  }

  if (!response.ok) {
    const body = await response.json().catch(() => null)

    throw new ApiError(
      `La API respondió ${response.status}`,
      response.status,
      body,
      response.headers,
    )
  }

  return response.text()
}
