/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.5.4`, `§23.9` (`1.14b-c2` T2), `REQ-PRL-004`: `CA-PRL-022` (registrar la
 * llamada y cerrar una alerta; un `PATCH` por acción del usuario, `DP-6`, `DP-11`). Datos ficticios.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { setLocale } from '@/i18n'
import * as prlApi from '../api'
import { buttonByText, fill, mountView, pickupAlert, stubMatchMedia } from '../testSupport'

vi.mock('../api')
vi.mock('@/session/useSession', async () => {
  const { shallowRef } = await import('vue')
  const user = shallowRef<{ public_id: string; permissions: string[] } | null>(null)

  return {
    useSession: () => ({ user }),
    __setPermissions: (permissions: string[]) => {
      user.value = { public_id: 'ME', permissions }
    },
  }
})

const session = (await import('@/session/useSession')) as unknown as {
  __setPermissions: (permissions: string[]) => void
}
const { default: AlertDetailView } = await import('./AlertDetailView.vue')

const wrappers: VueWrapper[] = []

async function open() {
  const mounted = await mountView('prl-alert-detail', AlertDetailView, '/conserjeria/alertas/A1')

  wrappers.push(mounted.wrapper)

  return mounted
}

function text(): string {
  return document.body.textContent ?? ''
}

async function click(target: HTMLElement | null | undefined): Promise<void> {
  if (!target) throw new Error('No existe el elemento')

  target.click()
  await flushPromises()
}

function closed() {
  return pickupAlert({
    status: 'cerrada',
    resolution: 'no_entregado',
    resolution_notes: 'Se avisó al tutor.',
    resolved_by: 'ME',
    resolved_at: '2026-11-03T09:20:00Z',
    protocol: undefined,
    contacts: undefined,
  })
}

function problem(
  status: number,
  body: Record<string, unknown>,
  headers: Record<string, string> = {},
) {
  return new ApiError(`HTTP ${status}`, status, body, new Headers(headers))
}

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  vi.mocked(prlApi.getPickupAlert).mockReset()
  vi.mocked(prlApi.getPickupAlert).mockResolvedValue(pickupAlert() as never)
  vi.mocked(prlApi.updatePickupAlert).mockReset()
  session.__setPermissions(['alerta_entrega.leer', 'alerta_entrega.actualizar'])
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-PRL-022: registrar la llamada y cerrar la alerta (CA-PRL-008, DP-6, DP-11)', () => {
  it('guardar la llamada envía un PATCH con solo principal_contacted_at, y ninguno por cambio de campo', async () => {
    vi.mocked(prlApi.updatePickupAlert).mockResolvedValue(
      pickupAlert({ principal_contacted_at: '2026-11-03T09:10:00Z' }) as never,
    )
    await open()

    await fill('#prl-alert-contacted-at', '2026-11-03T10:10')
    await fill('#prl-alert-resolution-notes', 'borrador')
    await click(document.body.querySelector('#prl-alert-resolution-no_entregado'))

    expect(prlApi.updatePickupAlert).not.toHaveBeenCalled()

    await click(buttonByText('Guardar la llamada'))

    expect(prlApi.updatePickupAlert).toHaveBeenCalledTimes(1)

    const [id, body] = vi.mocked(prlApi.updatePickupAlert).mock.calls[0]!

    expect(id).toBe('A1')
    expect(Object.keys(body as object)).toEqual(['principal_contacted_at'])
    expect(
      new Date((body as { principal_contacted_at: string }).principal_contacted_at).getTime(),
    ).not.toBeNaN()
    // Sigue abierta, con su protocolo y teléfonos.
    expect(text()).toContain('Qué hacer ahora')
    expect(document.body.querySelector('a[href="tel:600000001"]')).not.toBeNull()
  })

  it('cerrar pide confirmación sin enviar nada; tras confirmar, un PATCH con la resolución y el detalle queda cerrado sin teléfonos ni protocolo', async () => {
    vi.mocked(prlApi.updatePickupAlert).mockResolvedValue(closed() as never)
    await open()

    await click(document.body.querySelector('#prl-alert-resolution-no_entregado'))
    await fill('#prl-alert-resolution-notes', 'Se avisó al tutor.')
    await click(buttonByText('Cerrar la alerta'))

    expect(text()).toContain('Una alerta cerrada no se puede reabrir.')
    expect(prlApi.updatePickupAlert).not.toHaveBeenCalled()

    const confirm = [
      ...document.body.querySelectorAll<HTMLButtonElement>('[data-slot="confirm-dialog"] button'),
    ].find((button) => button.textContent?.trim() === 'Cerrar la alerta')

    await click(confirm)

    expect(prlApi.updatePickupAlert).toHaveBeenCalledTimes(1)
    expect(vi.mocked(prlApi.updatePickupAlert).mock.calls[0]).toEqual([
      'A1',
      { resolution: 'no_entregado', resolution_notes: 'Se avisó al tutor.' },
    ])
    expect(text()).toContain('Cerrada')
    expect(text()).toContain('No entregado')
    expect(text()).not.toContain('Qué hacer ahora')
    expect(document.body.querySelector('a[href^="tel:"]')).toBeNull()
    expect(buttonByText('Guardar la llamada')).toBeUndefined()
  })

  it('cancelar la confirmación no envía nada; sin resolución elegida tampoco', async () => {
    await open()
    await click(buttonByText('Cerrar la alerta'))

    expect(prlApi.updatePickupAlert).not.toHaveBeenCalled()
    expect(text()).toContain('Elige la resolución.')

    await click(document.body.querySelector('#prl-alert-resolution-otro'))
    await click(buttonByText('Cerrar la alerta'))
    await click(document.body.querySelector('[data-slot="confirm-dialog"] button') as HTMLElement)

    expect(prlApi.updatePickupAlert).not.toHaveBeenCalled()
  })

  it('409 alert.closed se muestra y ofrece recargar (acción explícita, una lectura más)', async () => {
    vi.mocked(prlApi.updatePickupAlert).mockRejectedValueOnce(
      problem(409, { detail: 'La alerta ya está cerrada.' }),
    )
    await open()
    await click(buttonByText('Guardar la llamada'))

    expect(text()).toContain('La alerta ya está cerrada.')
    expect(prlApi.getPickupAlert).toHaveBeenCalledTimes(1)

    vi.mocked(prlApi.getPickupAlert).mockResolvedValue(closed() as never)
    await click(buttonByText('Recargar la alerta'))

    expect(prlApi.getPickupAlert).toHaveBeenCalledTimes(2)
    expect(text()).toContain('Cerrada')
  })

  it('429 se muestra sin reintentar; 422 de campo, junto al campo', async () => {
    vi.mocked(prlApi.updatePickupAlert).mockRejectedValueOnce(
      problem(429, { detail: 'Demasiadas consultas.' }, { 'Retry-After': '60' }),
    )
    await open()
    await click(buttonByText('Guardar la llamada'))

    expect(text()).toContain('Demasiadas consultas.')
    expect(prlApi.updatePickupAlert).toHaveBeenCalledTimes(1)

    vi.mocked(prlApi.updatePickupAlert).mockRejectedValueOnce(
      problem(422, {
        errors: {
          principal_contacted_at: [
            { code: 'prl.validation.contact_time_out_of_range', message: 'La hora no es válida.' },
          ],
        },
      }),
    )
    await click(buttonByText('Guardar la llamada'))

    expect(document.body.querySelector('#prl-alert-contacted-errors')!.textContent).toContain(
      'La hora no es válida.',
    )
  })

  it('el botón se bloquea mientras el PATCH está en curso (sin doble envío)', async () => {
    let resolve: (value: unknown) => void = () => undefined

    vi.mocked(prlApi.updatePickupAlert).mockImplementation(
      (() => new Promise((r) => (resolve = r))) as never,
    )
    await open()
    await click(buttonByText('Guardar la llamada'))

    expect(buttonByText('Guardar la llamada')!.disabled).toBe(true)

    buttonByText('Guardar la llamada')!.click()
    await flushPromises()

    expect(prlApi.updatePickupAlert).toHaveBeenCalledTimes(1)

    resolve(pickupAlert())
    await flushPromises()
  })

  it('sin alerta_entrega.actualizar no hay llamada ni cierre; con una alerta cerrada tampoco', async () => {
    session.__setPermissions(['alerta_entrega.leer'])
    await open()

    expect(buttonByText('Guardar la llamada')).toBeUndefined()
    expect(buttonByText('Cerrar la alerta')).toBeUndefined()

    while (wrappers.length > 0) wrappers.pop()!.unmount()
    document.body.innerHTML = ''
    session.__setPermissions(['alerta_entrega.leer', 'alerta_entrega.actualizar'])
    vi.mocked(prlApi.getPickupAlert).mockResolvedValue(closed() as never)
    await open()

    expect(buttonByText('Guardar la llamada')).toBeUndefined()
  })
})
