import { describe, it, expect, vi, afterEach } from 'vitest'
import { apiFetch, apiFetchWithStatus, ApiError } from './client'

// REQ-AUTH (1.3), funcional.md §C.4.9: la sesión restringida por el muro
// de MFA. `router` se mockea porque `client.ts` la importa dinámicamente
// solo para este caso — el resto de tests no la necesitan.
const pushMock = vi.hoisted(() => vi.fn())
vi.mock('@/router', () => ({
  default: {
    currentRoute: { value: { name: 'home' } },
    push: pushMock,
  },
}))

// `docs/adr/ADR-053 §6`: `client.ts` importa `@/session/useSession` de
// forma dinámica ante cualquier 403 que no sea el muro de MFA. Se mockea
// por el mismo motivo que `@/router` arriba — este fichero prueba
// `client.ts` en aislamiento, no la recarga real de sesión (que tiene su
// propia suite en `src/session/useSession.spec.ts`).
const reloadSessionAfterForbiddenMock = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/session/useSession', () => ({
  reloadSessionAfterForbidden: reloadSessionAfterForbiddenMock,
}))

describe('apiFetch', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('devuelve el cuerpo JSON cuando la respuesta es correcta', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        json: async () => ({ status: 'ok' }),
      }),
    )

    await expect(apiFetch('/health')).resolves.toEqual({ status: 'ok' })
  })

  it('lanza ApiError con el estado cuando la API responde con error', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: false,
        status: 500,
        json: async () => ({ message: 'boom' }),
      }),
    )

    await expect(apiFetch('/health')).rejects.toMatchObject({
      status: 500,
    })
  })

  it('lanza ApiError cuando falla la red', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('network down')))

    const error = await apiFetch('/health').catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as InstanceType<typeof ApiError>).status).toBe(0)
  })
})

describe('apiFetchWithStatus', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    pushMock.mockClear()
    reloadSessionAfterForbiddenMock.mockClear()
  })

  it('conserva el código de estado de una respuesta correcta (api.md §C.2: 200 frente a 202)', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: true,
        status: 202,
        json: async () => ({ public_id: '01J', method: 'totp' }),
      }),
    )

    await expect(apiFetchWithStatus('/auth/session')).resolves.toEqual({
      status: 202,
      body: { public_id: '01J', method: 'totp' },
    })
  })

  it('redirige al muro de MFA ante un 403 urn:pge:error:mfa-enrollment-required (funcional.md §C.4.9)', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: false,
        status: 403,
        json: async () => ({ type: 'urn:pge:error:mfa-enrollment-required' }),
        headers: new Headers(),
      }),
    )

    await expect(apiFetch('/some-endpoint')).rejects.toMatchObject({ status: 403 })
    // La redirección se dispara de forma asíncrona (import dinámico de `@/router`).
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(pushMock).toHaveBeenCalledWith({ name: 'mfa-enrollment-wall' })
  })

  it('no redirige ante un 403 de otro tipo, pero pide recargar la sesión (ADR-053 §6)', async () => {
    const body = { type: 'urn:pge:error:forbidden' }
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: false,
        status: 403,
        json: async () => body,
        headers: new Headers(),
      }),
    )

    await expect(apiFetch('/some-endpoint')).rejects.toMatchObject({ status: 403 })
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(pushMock).not.toHaveBeenCalled()
    expect(reloadSessionAfterForbiddenMock).toHaveBeenCalledWith(body)
  })

  it('también pide recargar la sesión ante un 403 module-disabled (ADR-053 §6)', async () => {
    const body = { type: 'urn:pge:error:module-disabled', detail: 'Módulo no contratado.' }
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: false,
        status: 403,
        json: async () => body,
        headers: new Headers(),
      }),
    )

    await expect(apiFetch('/some-endpoint')).rejects.toMatchObject({ status: 403 })
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(reloadSessionAfterForbiddenMock).toHaveBeenCalledWith(body)
  })
})
