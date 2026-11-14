/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.5.1`, `§23.2` UI-3 (`1.14b-c2` T1), `REQ-PRL-004`: `CA-PRL-015`.
 * Búsqueda de alumno: 2 a 100 caracteres, espera de 300 ms, cancelación de la petición anterior, respuestas obsoletas
 * ignoradas y el término y el nombre del alumno fuera de la URL, de `history.state`, del título y del almacenamiento.
 * Datos ficticios (`REQ-SEED-005`).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { setLocale } from '@/i18n'
import * as prlApi from '../api'
import { doorStudent, fill, mountView, stubMatchMedia } from '../testSupport'

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
const { default: DoorSearchView } = await import('./DoorSearchView.vue')

const wrappers: VueWrapper[] = []
const INPUT = '#prl-door-search'

async function open() {
  const mounted = await mountView('prl-door', DoorSearchView, '/conserjeria')

  wrappers.push(mounted.wrapper)

  return mounted
}

async function type(value: string): Promise<void> {
  await fill(INPUT, value)
}

async function wait(ms: number): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms)
  await flushPromises()
}

function text(): string {
  return document.body.textContent ?? ''
}

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  window.localStorage.clear()
  window.sessionStorage.clear()
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  vi.mocked(prlApi.searchDoorStudents).mockReset()
  vi.mocked(prlApi.searchDoorStudents).mockResolvedValue({ data: [doorStudent()] } as never)
  session.__setPermissions(['entrega_menor.leer'])
})

afterEach(() => {
  vi.useRealTimers()
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-PRL-015: búsqueda de alumno de conserjería (§23.5.1)', () => {
  it('con 0 o 1 carácter no hay petición y se ve la ayuda; con 2 o más y 300 ms hay una', async () => {
    await open()

    expect(text()).toContain('Escribe al menos 2 letras.')
    expect(document.body.querySelector('label[for="prl-door-search"]')?.textContent).toContain(
      'Buscar alumno por nombre o apellidos',
    )

    await type('L')
    await wait(1000)

    expect(prlApi.searchDoorStudents).not.toHaveBeenCalled()
    expect(text()).toContain('Escribe al menos 2 letras.')

    await type('Lu')
    await wait(299)

    expect(prlApi.searchDoorStudents).not.toHaveBeenCalled()

    await wait(1)

    expect(prlApi.searchDoorStudents).toHaveBeenCalledTimes(1)
    expect(vi.mocked(prlApi.searchDoorStudents).mock.calls[0]![0]).toBe('Lu')
    expect(text()).toContain('Lucas Ejemplo Ruiz')
    expect(text()).toContain('Unidad UF-000123')
    expect(text()).toContain('1 alumno')
  })

  it('el campo admite como máximo 100 caracteres', async () => {
    await open()

    expect(document.body.querySelector<HTMLInputElement>(INPUT)!.maxLength).toBe(100)
  })

  it('una nueva pulsación cancela la petición en vuelo y su respuesta tardía no pisa a la nueva', async () => {
    const resolvers: Array<(value: unknown) => void> = []
    const signals: AbortSignal[] = []

    vi.mocked(prlApi.searchDoorStudents).mockImplementation(((q: string, signal?: AbortSignal) => {
      signals.push(signal!)

      return new Promise((resolve) => {
        resolvers.push(() =>
          resolve({ data: [doorStudent({ student_person: q, display_name: `Resultado ${q}` })] }),
        )
      })
    }) as never)

    await open()
    await type('Lu')
    await wait(300)
    await type('Luc')
    await wait(300)

    expect(prlApi.searchDoorStudents).toHaveBeenCalledTimes(2)
    expect(signals[0]!.aborted).toBe(true)
    expect(signals[1]!.aborted).toBe(false)

    // La respuesta de «Luc» llega primero; la de «Lu», tarde.
    resolvers[1]!(null)
    await flushPromises()
    resolvers[0]!(null)
    await flushPromises()

    expect(text()).toContain('Resultado Luc')
    expect(text()).not.toContain('Resultado Lu ')
    expect(document.body.querySelectorAll('ul li a')).toHaveLength(1)
  })

  it('al borrar el término por debajo del mínimo se descarta la respuesta pendiente', async () => {
    let resolveLate: (value: unknown) => void = () => undefined

    vi.mocked(prlApi.searchDoorStudents).mockImplementation(
      () => new Promise((resolve) => (resolveLate = resolve)) as never,
    )
    await open()
    await type('Lu')
    await wait(300)
    await type('L')
    resolveLate({ data: [doorStudent()] })
    await flushPromises()

    expect(text()).not.toContain('Lucas Ejemplo Ruiz')
  })

  it('un resultado vacío muestra su estado', async () => {
    vi.mocked(prlApi.searchDoorStudents).mockResolvedValue({ data: [] } as never)
    await open()
    await type('Zz')
    await wait(300)

    expect(text()).toContain('No hay ningún alumno con ese nombre.')
  })

  it('un 422 pinta el mensaje del servidor y un 403 su estado sin reintento', async () => {
    vi.mocked(prlApi.searchDoorStudents).mockRejectedValueOnce(
      new ApiError('x', 422, {
        errors: { q: [{ code: 'x', message: 'El término no es válido.' }] },
      }),
    )
    await open()
    await type('Lu')
    await wait(300)

    expect(text()).toContain('El término no es válido.')

    vi.mocked(prlApi.searchDoorStudents).mockRejectedValueOnce(new ApiError('x', 403, {}))
    await type('Luc')
    await wait(300)

    expect(text()).toContain('No tienes permiso para ver esta información.')
    expect(document.body.querySelector('[role="alert"] button')).toBeNull()
  })

  it('el término y el nombre elegido no aparecen en la URL, history.state, document.title ni el almacenamiento', async () => {
    const { router } = await open()

    await type('Lucas')
    await wait(300)

    const link = document.body.querySelector<HTMLAnchorElement>('ul li a')!

    expect(link.getAttribute('href')).toBe('/conserjeria/alumnos/P1')

    link.click()
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('prl-door-student')

    const everything = [
      router.currentRoute.value.fullPath,
      JSON.stringify(window.history.state ?? null),
      document.title,
      JSON.stringify({ ...window.localStorage }),
      JSON.stringify({ ...window.sessionStorage }),
    ].join('|')

    expect(everything).not.toContain('Lucas')
    expect(everything).not.toContain('Ejemplo')
    expect(router.currentRoute.value.query).toEqual({})
  })

  it('el enlace a «Alertas de entrega» solo con alerta_entrega.leer', async () => {
    await open()
    expect(text()).not.toContain('Alertas de entrega')

    while (wrappers.length > 0) wrappers.pop()!.unmount()
    document.body.innerHTML = ''
    session.__setPermissions(['entrega_menor.leer', 'alerta_entrega.leer'])
    await open()

    expect(text()).toContain('Alertas de entrega')
  })
})
