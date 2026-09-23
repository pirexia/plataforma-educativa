/**
 * `docs/modulos/REQ-CORE/funcional.md §12.6`, `CA-CORE-140`.
 */
import { describe, expect, it } from 'vitest'
import { ApiError } from '@/api/client'
import { resolveErrorState } from './errorState'

describe('resolveErrorState — CA-CORE-140', () => {
  it('sin respuesta (status 0) -> offline', () => {
    expect(resolveErrorState(new ApiError('network', 0, null))).toEqual({ kind: 'offline' })
  })

  it('401 -> null (lo resuelve el guard, no un estado de vista)', () => {
    expect(
      resolveErrorState(new ApiError('401', 401, { type: 'urn:pge:error:unauthenticated' })),
    ).toBeNull()
  })

  it('403 mfa-enrollment-required -> null (lo resuelve el muro)', () => {
    expect(
      resolveErrorState(
        new ApiError('403', 403, { type: 'urn:pge:error:mfa-enrollment-required' }),
      ),
    ).toBeNull()
  })

  it('403 module-disabled -> module-disabled con el detail del servidor', () => {
    const state = resolveErrorState(
      new ApiError('403', 403, {
        type: 'urn:pge:error:module-disabled',
        detail: 'El centro no tiene este módulo contratado.',
      }),
    )

    expect(state).toMatchObject({
      kind: 'module-disabled',
      detail: 'El centro no tiene este módulo contratado.',
    })
  })

  it('otro 403 -> forbidden', () => {
    expect(
      resolveErrorState(new ApiError('403', 403, { type: 'urn:pge:error:forbidden' })),
    ).toMatchObject({
      kind: 'forbidden',
    })
  })

  it('404 -> not-found', () => {
    expect(
      resolveErrorState(new ApiError('404', 404, { type: 'urn:pge:error:not-found' })),
    ).toMatchObject({
      kind: 'not-found',
    })
  })

  it('429 con Retry-After: 30 -> too-many-requests con 30 segundos', () => {
    const headers = new Headers({ 'Retry-After': '30' })
    const state = resolveErrorState(
      new ApiError('429', 429, { type: 'urn:pge:error:too-many-requests' }, headers),
    )

    expect(state).toMatchObject({ kind: 'too-many-requests', retryAfterSeconds: 30 })
  })

  it('429 sin Retry-After -> too-many-requests sin segundos', () => {
    const state = resolveErrorState(
      new ApiError('429', 429, { type: 'urn:pge:error:too-many-requests' }),
    )

    expect(state).toMatchObject({ kind: 'too-many-requests', retryAfterSeconds: undefined })
  })

  it('5xx -> unexpected, con request_id si viene', () => {
    const state = resolveErrorState(new ApiError('503', 503, { request_id: 'req-1' }))

    expect(state).toMatchObject({ kind: 'unexpected', requestId: 'req-1' })
  })

  it('un error que no es ApiError -> unexpected', () => {
    expect(resolveErrorState(new Error('boom'))).toEqual({ kind: 'unexpected' })
  })
})
