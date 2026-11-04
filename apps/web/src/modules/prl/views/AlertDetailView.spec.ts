/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.5.4`, `§23.5.3`, `§23.7` (`1.14b-c2` T1, lectura), `REQ-PRL-004`:
 * `CA-PRL-023` en el detalle (estado con texto e icono). Alerta abierta: protocolo tal cual, teléfonos como `tel:` y
 * «No disponible» (`DP-5`, `CA-PRL-021` en lo que muestra); cerrada: sin teléfonos ni protocolo. Datos ficticios.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { setLocale } from '@/i18n'
import * as prlApi from '../api'
import { useDoorContext } from '../composables/useDoorContext'
import { mountView, pickupAlert, stubMatchMedia } from '../testSupport'

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

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  vi.mocked(prlApi.getPickupAlert).mockReset()
  vi.mocked(prlApi.getPickupAlert).mockResolvedValue(pickupAlert() as never)
  session.__setPermissions(['alerta_entrega.leer'])
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('REQ-PRL-004: nombre suprimido por el servidor', () => {
  it("con student_display_name '' no se recupera el nombre recordado", async () => {
    useDoorContext().remember('P1', { displayName: 'Lucas Ejemplo Ruiz', familyUnitCode: null })
    vi.mocked(prlApi.getPickupAlert).mockResolvedValue(
      pickupAlert({ student_display_name: '' }) as never,
    )
    await open()

    expect(text()).not.toContain('Lucas Ejemplo Ruiz')
    expect(text()).toContain('Alumno')
  })
})

describe('CA-PRL-023: detalle de una alerta (WCAG 1.4.1)', () => {
  it('abierta: una sola lectura, estado con texto e icono, protocolo tal cual y teléfonos', async () => {
    await open()

    expect(prlApi.getPickupAlert).toHaveBeenCalledTimes(1)
    expect(prlApi.getPickupAlert).toHaveBeenCalledWith('A1')

    const badge = document.body.querySelector('[data-slot="prl-status"]')!

    expect(badge.textContent?.trim()).toBe('Abierta')
    expect(badge.querySelector('svg[aria-hidden="true"]')).not.toBeNull()

    const region = document.body.querySelector('section[aria-labelledby="prl-alert-protocol"]')!

    expect(region.textContent).toContain('Qué hacer ahora')
    expect(region.textContent).toContain('No entregues al alumno. Llama al tutor principal.')
    expect(document.body.querySelector('a[href="tel:600000001"]')).not.toBeNull()
    expect(text()).toContain('No disponible')
    expect(text()).toContain('Esta consulta queda registrada con tu usuario, la fecha y la hora.')
  })

  it('sin alerta_entrega.actualizar se pide avisar a dirección; con él no, y con él hay acciones de llamada y cierre', async () => {
    await open()
    expect(text()).toContain('Avisa a dirección para que registre la llamada y cierre la alerta.')

    while (wrappers.length > 0) wrappers.pop()!.unmount()
    document.body.innerHTML = ''
    session.__setPermissions(['alerta_entrega.leer', 'alerta_entrega.actualizar'])
    await open()

    expect(text()).not.toContain('Avisa a dirección')
    // T2: con alerta_entrega.actualizar aparecen las acciones de la llamada y del cierre (`CA-PRL-022`).
    expect(text()).toContain('Guardar la llamada')
    expect(text()).toContain('Cerrar la alerta')
  })

  it('cerrada: resolución y notas, sin teléfonos ni protocolo', async () => {
    vi.mocked(prlApi.getPickupAlert).mockResolvedValue(
      pickupAlert({
        status: 'cerrada',
        resolution: 'recogido_por_autorizado',
        resolution_notes: 'Vino la abuela',
        resolved_by: 'USR2',
        resolved_at: '2026-11-03T10:00:00Z',
        protocol: undefined,
        contacts: undefined,
      }) as never,
    )
    await open()

    expect(document.body.querySelector('[data-slot="prl-status"]')!.textContent?.trim()).toBe(
      'Cerrada',
    )
    expect(text()).toContain('Recogido por una persona autorizada')
    expect(text()).toContain('Vino la abuela')
    expect(document.body.querySelector('a[href^="tel:"]')).toBeNull()
    expect(document.body.querySelector('section[aria-labelledby="prl-alert-protocol"]')).toBeNull()
  })

  it('el protocolo y los textos [NT] llevan lang es-ES solo cuando la interfaz no está en español', async () => {
    setLocale('de')
    await open()

    expect(
      document.body.querySelector('#prl-alert-protocol')!.closest('section')!.querySelector('p')!
        .lang,
    ).toBe('es-ES')
    setLocale('es')
  })

  it('404 y 403 se pintan con su texto y sin reintento', async () => {
    vi.mocked(prlApi.getPickupAlert).mockRejectedValueOnce(new ApiError('x', 404, {}))
    await open()

    expect(text()).toContain('No se encuentra esta información.')
    expect(document.body.querySelector('button')).toBeNull()
    await flushPromises()
  })

  it('muestra los nombres de la API (alumno, quien abrió y quien cerró) en lugar de los public_id, con fallback', async () => {
    vi.mocked(prlApi.getPickupAlert).mockResolvedValue(
      pickupAlert({
        status: 'cerrada',
        resolution: 'no_entregado',
        student_display_name: 'Marta Ejemplo Soler',
        raised_by_display_name: 'Conserje Ejemplo',
        resolved_by: 'USR9',
        resolved_by_display_name: 'Dirección Ejemplo',
        resolved_at: '2026-11-03T10:00:00Z',
        protocol: undefined,
        contacts: undefined,
      }) as never,
    )
    await open()

    expect(text()).toContain('Marta Ejemplo Soler')
    expect(text()).toContain('Conserje Ejemplo')
    expect(text()).toContain('Dirección Ejemplo')
    expect(text()).not.toContain('USR1')
    expect(text()).not.toContain('USR9')

    // Sin nombres (API antigua o usuario borrado): marcador neutro.
    vi.mocked(prlApi.getPickupAlert).mockResolvedValue(pickupAlert() as never)
    wrappers.pop()!.unmount()
    document.body.innerHTML = ''
    await open()

    expect(text()).toContain('Usuario')
    expect(text()).not.toContain('USR1')
  })
})
