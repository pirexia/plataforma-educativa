/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.5.2`, `§23.7` (`1.14b-c2` T1, solo lectura), `REQ-PRL-004`:
 * `CA-PRL-016` (una sola petición al entrar, título sin nombre), `CA-PRL-023` (texto además de icono y `alt` no vacío),
 * `CA-PRL-025` (cabecera con nombre solo si viene de la búsqueda; `history.state` sin nombre). Datos ficticios.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { setLocale } from '@/i18n'
import * as prlApi from '../api'
import { useDoorContext } from '../composables/useDoorContext'
import { buttonByText, doorList, mountView, pickupEntry, stubMatchMedia } from '../testSupport'

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
const { default: DoorStudentView } = await import('./DoorStudentView.vue')

const wrappers: VueWrapper[] = []

async function open(url = '/conserjeria/alumnos/P1') {
  const mounted = await mountView('prl-door-student', DoorStudentView, url)

  wrappers.push(mounted.wrapper)

  return mounted
}

function text(): string {
  return document.body.textContent ?? ''
}

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  window.localStorage.clear()
  window.sessionStorage.clear()
  useDoorContext().clear()
  vi.mocked(prlApi.getDoorPickupList).mockReset()
  vi.mocked(prlApi.getDoorPickupList).mockResolvedValue(
    doorList([
      pickupEntry(),
      pickupEntry({
        person: 'PE2',
        display_name: 'Iván Ejemplo Soto',
        bases: [
          { basis: 'tercero', relationship: 'abuelo' },
          { basis: 'puntual', relationship: 'otro' },
        ],
        has_photo: false,
        photo_url: null,
        contact_phone: null,
        document: null,
      }),
    ]) as never,
  )
  session.__setPermissions(['entrega_menor.leer', 'entrega_menor.crear', 'alerta_entrega.crear'])
})

afterEach(() => {
  vi.useRealTimers()
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-PRL-016: una sola lectura auditada al entrar en la lista de puerta (UI-2)', () => {
  it('hay exactamente una petición a la lista de puerta del alumno y ninguna más sin acción del usuario', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'setInterval', 'clearTimeout', 'clearInterval'] })
    await open()

    expect(prlApi.getDoorPickupList).toHaveBeenCalledTimes(1)
    expect(prlApi.getDoorPickupList).toHaveBeenCalledWith('P1')

    await vi.advanceTimersByTimeAsync(10 * 60 * 1000)
    await flushPromises()

    expect(prlApi.getDoorPickupList).toHaveBeenCalledTimes(1)

    buttonByText('Volver a cargar la lista')!.click()
    await flushPromises()

    expect(prlApi.getDoorPickupList).toHaveBeenCalledTimes(2)
  })

  it('document.title no contiene el nombre del alumno aunque venga de la búsqueda', async () => {
    useDoorContext().remember('P1', {
      displayName: 'Lucas Ejemplo Ruiz',
      familyUnitCode: 'UF-000123',
    })
    await open()

    expect(document.title).not.toContain('Lucas')
    expect(window.localStorage.length + window.sessionStorage.length).toBe(0)
  })

  it('es de solo lectura: ninguna acción de entrega ni de alerta aunque haya permisos de escritura', async () => {
    await open()

    const buttons = [...document.body.querySelectorAll('button')].map((b) => b.textContent?.trim())

    expect(buttons).toEqual(['Volver a cargar la lista'])
    expect(text()).not.toContain('Entregar a')
    expect(text()).not.toContain('Quien se presenta no está en la lista')
  })

  it('mientras carga no se puede pedir otra lectura (botón deshabilitado)', async () => {
    vi.mocked(prlApi.getDoorPickupList).mockImplementation(
      (() => new Promise(() => undefined)) as never,
    )
    await open()

    expect(buttonByText('Volver a cargar la lista')!.disabled).toBe(true)
  })

  it('una respuesta tardía de otro alumno no pisa a la del alumno actual', async () => {
    const resolvers: Array<() => void> = []

    vi.mocked(prlApi.getDoorPickupList).mockImplementation(
      (() =>
        new Promise((resolve) => {
          const index = resolvers.length

          resolvers.push(() =>
            resolve(
              doorList([
                pickupEntry({ display_name: index === 0 ? 'Antigua Ejemplo' : 'Nueva Ejemplo' }),
              ]),
            ),
          )
        })) as never,
    )

    const { router } = await open()

    await router.push('/conserjeria/alumnos/P2')
    await flushPromises()
    resolvers[1]!()
    await flushPromises()
    resolvers[0]!()
    await flushPromises()

    expect(prlApi.getDoorPickupList).toHaveBeenCalledTimes(2)
    expect(text()).toContain('Nueva Ejemplo')
    expect(text()).not.toContain('Antigua Ejemplo')
  })
})

describe('CA-PRL-025: cabecera de la lista de puerta (OPEN-FAM-UNIT-58 A)', () => {
  it('desde la búsqueda muestra el nombre y el código de unidad', async () => {
    useDoorContext().remember('P1', {
      displayName: 'Lucas Ejemplo Ruiz',
      familyUnitCode: 'UF-000123',
    })
    await open()

    expect(document.body.querySelector('h1')?.textContent).toContain('Lucas Ejemplo Ruiz')
    expect(text()).toContain('Unidad UF-000123')
  })

  it('por URL directa o tras recargar dice solo «Lista de recogida» y history.state no tiene el nombre', async () => {
    useDoorContext().remember('P1', {
      displayName: 'Lucas Ejemplo Ruiz',
      familyUnitCode: 'UF-000123',
    })
    useDoorContext().clear() // recarga: la memoria del módulo se pierde
    await open()

    expect(document.body.querySelector('h1')?.textContent?.trim()).toBe('Lista de recogida')
    expect(text()).not.toContain('Lucas Ejemplo Ruiz')
    expect(JSON.stringify(window.history.state ?? null)).not.toContain('Lucas')
  })
})

describe('CA-PRL-023: estados por texto e icono, y alt de toda fotografía (WCAG 1.4.1, 1.1.1)', () => {
  it('las bases y «Sin fotografía» llevan texto; toda imagen tiene alt no vacío con el nombre', async () => {
    await open()

    expect(text()).toContain('Tutor/a')
    expect(text()).toContain('Autorizado/a')
    expect(text()).toContain('Solo hoy')
    expect(text()).toContain('Sin fotografía')
    expect(text()).toContain('Verifica con el documento.')

    const images = [...document.body.querySelectorAll('img')]

    expect(images).toHaveLength(1)
    expect(images[0]!.getAttribute('alt')).toBe('Fotografía de Rosa Ejemplo Vidal')
    expect(images[0]!.getAttribute('referrerpolicy')).toBe('no-referrer')
    expect(document.body.querySelectorAll('svg[aria-hidden="true"]').length).toBeGreaterThan(0)
  })

  it('el documento llega enmascarado y el teléfono es un enlace tel:', async () => {
    await open()

    expect(text()).toContain('•••••123X')
    expect(document.body.querySelector('a[href="tel:600000001"]')).not.toBeNull()
  })

  it('una imagen que falla se sustituye por «Fotografía no disponible» y «Volver a cargar la lista»', async () => {
    await open()
    document.body.querySelector('img')!.dispatchEvent(new Event('error'))
    await flushPromises()

    expect(document.body.querySelector('img')).toBeNull()
    expect(text()).toContain('Fotografía no disponible')
    expect(document.body.querySelectorAll('button')).toHaveLength(2)
  })

  it('el aviso de registro y la norma de entrega aparecen en la lista', async () => {
    await open()

    expect(text()).toContain('Esta consulta queda registrada con tu usuario, la fecha y la hora.')
    expect(text()).toContain('No entregues al alumno a nadie que no esté en esta lista.')
    expect(text()).toContain('Lista calculada a las')
  })
})

describe('§23.5.2: estados de la lista de puerta', () => {
  it('lista vacía: texto de seguridad', async () => {
    vi.mocked(prlApi.getDoorPickupList).mockResolvedValue(doorList([]) as never)
    await open()

    expect(text()).toContain('Nadie puede recoger a este alumno en este momento.')
  })

  it('404, 403 y 429 se pintan sin reintento automático', async () => {
    vi.mocked(prlApi.getDoorPickupList).mockRejectedValueOnce(new ApiError('x', 404, {}))
    await open()
    expect(text()).toContain('No se encuentra este alumno.')

    vi.mocked(prlApi.getDoorPickupList).mockRejectedValueOnce(new ApiError('x', 403, {}))
    buttonByText('Volver a cargar la lista')!.click()
    await flushPromises()
    expect(text()).toContain('No tienes permiso para ver esta información.')

    vi.mocked(prlApi.getDoorPickupList).mockRejectedValueOnce(
      new ApiError('x', 429, {}, new Headers({ 'Retry-After': '30' })),
    )
    buttonByText('Volver a cargar la lista')!.click()
    await flushPromises()
    expect(prlApi.getDoorPickupList).toHaveBeenCalledTimes(3)
    expect(document.body.querySelector('[role="alert"]')).not.toBeNull()
  })
})
