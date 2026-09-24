/**
 * `docs/modulos/REQ-CORE/funcional.md §12.3.4`, `§12.11`
 * (`CA-CORE-092`, `CA-CORE-097`, `CA-CORE-098`, `CA-CORE-099`,
 * `CA-CORE-151`), `docs/adr/ADR-053 §6`.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const getMe = vi.fn()
const updateMe = vi.fn()
vi.mock('@/modules/core/api', () => ({
  getMe: (...args: unknown[]) => getMe(...args),
  updateMe: (...args: unknown[]) => updateMe(...args),
}))

/**
 * `vi.resetModules()` crea un nuevo grafo de módulos, incluido
 * `@/api/client`: la clase `ApiError` que use un test para construir el
 * error simulado tiene que salir de **ese mismo grafo**, o
 * `err instanceof ApiError` falla dentro de `useSession.ts` (dos clases
 * distintas con el mismo nombre). Por eso `freshModule()` devuelve también
 * el `ApiError` fresco, en vez de importarlo una sola vez arriba.
 */
async function freshModule() {
  vi.resetModules()

  const [session, client] = await Promise.all([import('./useSession'), import('@/api/client')])

  return { ...session, ApiError: client.ApiError }
}

function makeUser(overrides: Record<string, unknown> = {}) {
  return {
    public_id: '01J-user',
    email: 'ana@example.com',
    status: 'activo',
    person: {
      public_id: '01J-person',
      given_name: 'Ana',
      family_name_1: 'García',
      family_name_2: null,
      contact_email: null,
      contact_phone: null,
      document_type: null,
      document_number: null,
      birth_date: null,
      locale: 'es-ES',
    },
    roles: [],
    permissions: [],
    email_verified_at: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    deleted_at: null,
    ...overrides,
  }
}

beforeEach(() => {
  getMe.mockReset()
  updateMe.mockReset()
  localStorage.clear()
  sessionStorage.clear()
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('ensureSessionLoaded — CA-CORE-092/093/094', () => {
  it('pide /me una sola vez aunque se llame varias veces antes de resolver', async () => {
    let resolveFetch: (value: unknown) => void = () => {}
    getMe.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveFetch = resolve
        }),
    )

    const { ensureSessionLoaded, useSession } = await freshModule()

    const p1 = ensureSessionLoaded()
    const p2 = ensureSessionLoaded()
    const p3 = ensureSessionLoaded()

    resolveFetch(makeUser())
    await Promise.all([p1, p2, p3])

    expect(getMe).toHaveBeenCalledTimes(1)
    expect(useSession().status.value).toBe('ready')
  })

  it('200 deja status ready y el usuario en memoria', async () => {
    getMe.mockResolvedValue(makeUser({ permissions: ['modulo.leer'] }))

    const { ensureSessionLoaded, useSession } = await freshModule()
    await ensureSessionLoaded()

    const { status, user } = useSession()
    expect(status.value).toBe('ready')
    expect(user.value?.permissions).toEqual(['modulo.leer'])
  })

  it('401 deja status anonymous y el usuario vacío', async () => {
    const { ensureSessionLoaded, useSession, ApiError } = await freshModule()
    getMe.mockRejectedValue(new ApiError('401', 401, { type: 'urn:pge:error:unauthenticated' }))

    await ensureSessionLoaded()

    const { status, user } = useSession()
    expect(status.value).toBe('anonymous')
    expect(user.value).toBeNull()
  })

  it('403 mfa-enrollment-required deja status mfa-required', async () => {
    const { ensureSessionLoaded, useSession, ApiError } = await freshModule()
    getMe.mockRejectedValue(
      new ApiError('403', 403, { type: 'urn:pge:error:mfa-enrollment-required' }),
    )

    await ensureSessionLoaded()

    expect(useSession().status.value).toBe('mfa-required')
  })

  it('error de red/5xx deja status error con la información de la respuesta', async () => {
    const { ensureSessionLoaded, useSession, ApiError } = await freshModule()
    const headers = new Headers({ 'Retry-After': '30' })
    getMe.mockRejectedValue(new ApiError('503', 503, { request_id: 'req-1' }, headers))

    await ensureSessionLoaded()

    const { status, error } = useSession()
    expect(status.value).toBe('error')
    expect(error.value).toMatchObject({ status: 503, retryAfterSeconds: 30 })
  })

  it('no vuelve a pedir /me si ya está resuelto (RN-CORE-26)', async () => {
    getMe.mockResolvedValue(makeUser())

    const { ensureSessionLoaded } = await freshModule()
    await ensureSessionLoaded()
    await ensureSessionLoaded()
    await ensureSessionLoaded()

    expect(getMe).toHaveBeenCalledTimes(1)
  })
})

describe('reloadSession — CA-CORE-098', () => {
  it('tres llamadas concurrentes producen una sola petición', async () => {
    getMe.mockResolvedValue(makeUser())

    const { reloadSession } = await freshModule()

    await Promise.all([reloadSession(), reloadSession(), reloadSession()])

    expect(getMe).toHaveBeenCalledTimes(1)
  })

  it('reloadSessionAfterForbidden dedupe tres 403 genéricos concurrentes en una sola recarga', async () => {
    getMe.mockResolvedValue(makeUser())

    const { reloadSessionAfterForbidden } = await freshModule()

    const body = { type: 'urn:pge:error:forbidden' }
    await Promise.all([
      reloadSessionAfterForbidden(body),
      reloadSessionAfterForbidden(body),
      reloadSessionAfterForbidden(body),
    ])

    expect(getMe).toHaveBeenCalledTimes(1)
  })

  it('reloadSessionAfterForbidden dedupe tres 403 module-disabled concurrentes en una sola recarga', async () => {
    getMe.mockResolvedValue(makeUser())

    const { reloadSessionAfterForbidden } = await freshModule()

    const body = { type: 'urn:pge:error:module-disabled', detail: 'Módulo no disponible.' }
    await Promise.all([
      reloadSessionAfterForbidden(body),
      reloadSessionAfterForbidden(body),
      reloadSessionAfterForbidden(body),
    ])

    expect(getMe).toHaveBeenCalledTimes(1)
  })
})

describe('reloadSessionAfterForbidden — CA-CORE-099, ADR-053 §6', () => {
  it('con module-disabled, deja el detail del servidor en moduleUnavailableDetail', async () => {
    getMe.mockResolvedValue(makeUser())

    const { reloadSessionAfterForbidden, useSession } = await freshModule()

    await reloadSessionAfterForbidden({
      type: 'urn:pge:error:module-disabled',
      detail: 'El centro no tiene este módulo contratado.',
    })

    expect(useSession().moduleUnavailableDetail.value).toBe(
      'El centro no tiene este módulo contratado.',
    )
  })

  it('con un 403 genérico, no toca moduleUnavailableDetail', async () => {
    getMe.mockResolvedValue(makeUser())

    const { reloadSessionAfterForbidden, useSession } = await freshModule()

    await reloadSessionAfterForbidden({ type: 'urn:pge:error:forbidden' })

    expect(useSession().moduleUnavailableDetail.value).toBeNull()
  })

  it('clearModuleUnavailableDetail lo vacía (lo limpia el router en cada navegación)', async () => {
    getMe.mockResolvedValue(makeUser())

    const { reloadSessionAfterForbidden, clearModuleUnavailableDetail, useSession } =
      await freshModule()

    await reloadSessionAfterForbidden({ type: 'urn:pge:error:module-disabled', detail: 'x' })
    expect(useSession().moduleUnavailableDetail.value).toBe('x')

    clearModuleUnavailableDetail()
    expect(useSession().moduleUnavailableDetail.value).toBeNull()
  })
})

describe('updateSessionLocale — RN-CORE-26', () => {
  it('aplica la respuesta del propio PATCH, sin una segunda petición a GET /me', async () => {
    getMe.mockResolvedValue(makeUser())
    updateMe.mockResolvedValue(makeUser({ person: { ...makeUser().person, locale: 'fr' } }))

    const { ensureSessionLoaded, updateSessionLocale, useSession } = await freshModule()
    await ensureSessionLoaded()

    await updateSessionLocale('fr')

    expect(getMe).toHaveBeenCalledTimes(1)
    expect(updateMe).toHaveBeenCalledWith({ person: { locale: 'fr' } })
    expect(useSession().user.value?.person.locale).toBe('fr')
  })
})

describe('clearSession — RN-CORE-27', () => {
  it('vacía el usuario y deja status anonymous aunque no haya habido fetch previo', async () => {
    const { clearSession, useSession } = await freshModule()

    clearSession()

    const { user, status } = useSession()
    expect(user.value).toBeNull()
    expect(status.value).toBe('anonymous')
  })
})

describe('CA-CORE-151: nunca toca localStorage ni sessionStorage', () => {
  it('un ciclo completo (cargar, actualizar idioma, recargar por 403, cerrar sesión) no escribe ninguna clave', async () => {
    getMe.mockResolvedValue(makeUser())
    updateMe.mockResolvedValue(makeUser())

    const localSetSpy = vi.spyOn(Storage.prototype, 'setItem')

    const { ensureSessionLoaded, updateSessionLocale, reloadSessionAfterForbidden, clearSession } =
      await freshModule()

    await ensureSessionLoaded()
    await updateSessionLocale('en')
    await reloadSessionAfterForbidden({ type: 'urn:pge:error:forbidden' })
    clearSession()

    expect(localSetSpy).not.toHaveBeenCalled()
    expect(localStorage.length).toBe(0)
    expect(sessionStorage.length).toBe(0)
  })
})
