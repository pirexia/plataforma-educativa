/**
 * `docs/modulos/REQ-CORE/funcional.md §14.13.5` (1.9f): `SessionsView`
 * migrada al componente de tabla. `CA-CORE-294`, `CA-CORE-258`,
 * `CA-CORE-186` (nombres accesibles, WCAG 2.4.6), `REQ-AUTH-005`.
 * No existía ningún test de esta vista (verificado por búsqueda antes de
 * migrarla, §14.13.1).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'

const listSessions = vi.fn()
const revokeSession = vi.fn()
const revokeOtherSessions = vi.fn()

vi.mock('../api', () => ({
  listSessions: (...args: unknown[]) => listSessions(...args),
  revokeSession: (...args: unknown[]) => revokeSession(...args),
  revokeOtherSessions: (...args: unknown[]) => revokeOtherSessions(...args),
}))

const { default: SessionsView } = await import('./SessionsView.vue')

function session(id: string, overrides: Record<string, unknown> = {}) {
  return {
    public_id: id,
    current: false,
    started_at: '2026-10-02T08:00:00Z',
    last_activity_at: '2026-10-03T09:00:00Z',
    ip_address: '203.0.113.7',
    client: { browser: 'Chrome', platform: 'Windows', device_type: 'escritorio' },
    location: null,
    device_known: true,
    ...overrides,
  }
}

function page(data: unknown[], meta: Record<string, number> = {}) {
  return {
    data,
    meta: { current_page: 1, per_page: 25, total: data.length, last_page: 1, ...meta },
  }
}

const TWO = () =>
  page([
    session('s-current', { current: true, started_at: '2026-10-03T10:15:00Z' }),
    session('s-other'),
  ])

const wrappers: VueWrapper[] = []
let router: Router

async function mountView() {
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/entrar', name: 'login', component: { template: '<div/>' } },
      { path: '/cuenta/sesiones', name: 'sessions', component: SessionsView },
    ],
  })
  await router.push('/cuenta/sesiones')
  await router.isReady()

  const wrapper = mount(SessionsView, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return wrapper
}

function dialog(): Element | null {
  return document.body.querySelector('[role="alertdialog"]')
}

function confirmButton(): HTMLButtonElement {
  return [...dialog()!.querySelectorAll('button')].find(
    (button) => button.textContent?.trim() !== 'Cancelar',
  ) as HTMLButtonElement
}

async function click(el: Element): Promise<void> {
  el.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await flushPromises()
}

function rowButtons(): HTMLButtonElement[] {
  return [...document.body.querySelectorAll<HTMLButtonElement>('tbody button')]
}

function othersButton(): HTMLButtonElement {
  return [...document.body.querySelectorAll('button')].find(
    (button) => button.textContent?.trim() === 'Cerrar todas las demás sesiones',
  ) as HTMLButtonElement
}

beforeEach(() => {
  listSessions.mockReset()
  revokeSession.mockReset()
  revokeOtherSessions.mockReset()
  setLocale('es')
  window.localStorage.clear()
  Object.defineProperty(window, 'matchMedia', {
    configurable: true,
    writable: true,
    value: (query: string) => ({
      matches: false,
      media: query,
      addEventListener: () => undefined,
      removeEventListener: () => undefined,
    }),
  })
  listSessions.mockResolvedValue(TWO())
  revokeSession.mockResolvedValue(undefined)
  revokeOtherSessions.mockResolvedValue(undefined)
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('CA-CORE-294 (RN-CORE-84, RN-CORE-64, REQ-AUTH-005, WCAG 2.4.6): migración de SessionsView', () => {
  it('CA-CORE-258: la primera petición es page=1 y per_page=25, sin sort', async () => {
    await mountView()

    expect(listSessions).toHaveBeenCalledTimes(1)
    expect(listSessions).toHaveBeenCalledWith({ page: 1, per_page: 25 })
  })

  it('dos sesiones Chrome · Windows con distinta fecha tienen nombres accesibles distintos', async () => {
    await mountView()

    const names = rowButtons().map((button) => button.getAttribute('aria-label'))

    expect(names).toHaveLength(2)
    expect(new Set(names).size).toBe(2)

    for (const name of names) {
      expect(name).toMatch(/^Cerrar la sesión de Chrome · Windows iniciada el /)
    }
  })

  it('el diálogo de la fila current usa confirmRevokeCurrent y el de la otra confirmRevoke', async () => {
    await mountView()

    const [current, other] = rowButtons()

    await click(current!)

    expect(dialog()!.textContent).toContain(i18n.global.t('auth.sessions.confirmRevokeCurrent'))
    expect(revokeSession).not.toHaveBeenCalled()

    await click(
      [...dialog()!.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Cancelar')!,
    )
    await click(other!)

    expect(dialog()!.textContent).toContain(i18n.global.t('auth.sessions.confirmRevoke'))
    expect(dialog()!.textContent).not.toContain(i18n.global.t('auth.sessions.confirmRevokeCurrent'))
  })

  it('204 sobre otra fila: mensaje con role=status y se vuelve a pedir el listado', async () => {
    await mountView()

    await click(rowButtons()[1]!)
    await click(confirmButton())

    expect(revokeSession).toHaveBeenCalledWith('s-other')
    expect(listSessions).toHaveBeenCalledTimes(2)
    expect(document.body.querySelector('[role="status"]')?.textContent).toContain(
      i18n.global.t('auth.sessions.revokedSuccess'),
    )
  })

  it('204 sobre la fila current navega a login sin recargar', async () => {
    await mountView()

    await click(rowButtons()[0]!)
    await click(confirmButton())

    expect(revokeSession).toHaveBeenCalledWith('s-current')
    expect(listSessions).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.name).toBe('login')
  })

  it('409 vuelve a pedir el listado sin mensaje de error', async () => {
    revokeSession.mockRejectedValue(new ApiError('conflict', 409, null))
    await mountView()

    await click(rowButtons()[1]!)
    await click(confirmButton())

    expect(listSessions).toHaveBeenCalledTimes(2)
    expect(document.body.querySelector('[role="alert"]')).toBeNull()
  })

  it('429 con Retry-After: 30 muestra el mensaje con los segundos', async () => {
    revokeSession.mockRejectedValue(
      new ApiError('too many', 429, null, new Headers({ 'Retry-After': '30' })),
    )
    await mountView()

    await click(rowButtons()[1]!)
    await click(confirmButton())

    expect(document.body.querySelector('[role="alert"]')?.textContent).toContain('30')
  })

  it('otro error muestra auth.common.unexpectedError', async () => {
    revokeSession.mockRejectedValue(new ApiError('boom', 500, null))
    await mountView()

    await click(rowButtons()[1]!)
    await click(confirmButton())

    expect(document.body.querySelector('[role="alert"]')?.textContent).toContain(
      i18n.global.t('auth.common.unexpectedError'),
    )
  })

  it('con meta.total = 1 «Cerrar las demás sesiones» está deshabilitado', async () => {
    listSessions.mockResolvedValue(page([session('s-current', { current: true })]))
    await mountView()

    expect(othersButton().disabled).toBe(true)
  })

  it('con meta.total = 2 en una página que solo contiene la current, está habilitado', async () => {
    listSessions.mockResolvedValue(
      page([session('s-current', { current: true })], { total: 2, last_page: 2 }),
    )
    await mountView()

    expect(othersButton().disabled).toBe(false)
  })

  it('«Cerrar las demás sesiones» pide confirmación, llama al endpoint y vuelve a pedir el listado', async () => {
    await mountView()

    await click(othersButton())

    expect(revokeOtherSessions).not.toHaveBeenCalled()
    expect(dialog()!.textContent).toContain(i18n.global.t('auth.sessions.confirmRevokeOthers'))

    await click(confirmButton())

    expect(revokeOtherSessions).toHaveBeenCalledTimes(1)
    expect(listSessions).toHaveBeenCalledTimes(2)
    expect(document.body.querySelector('[role="status"]')?.textContent).toContain(
      i18n.global.t('auth.sessions.revokeOthersSuccess'),
    )
  })

  it('un 401 en la carga navega a login', async () => {
    listSessions.mockRejectedValue(new ApiError('unauthenticated', 401, null))
    await mountView()

    expect(router.currentRoute.value.name).toBe('login')
  })

  it('un 503 en la carga pinta el estado de error del componente con «Reintentar»', async () => {
    listSessions.mockRejectedValue(new ApiError('unavailable', 503, null))
    await mountView()

    expect(document.body.textContent).toContain('Reintentar')
  })
})

describe('CA-CORE-258 (CA-CORE-201, #90): IP nula', () => {
  it('una sesión sin dirección IP pinta el valor vacío común, no un literal', async () => {
    listSessions.mockResolvedValue(
      page([session('s-current', { current: true, ip_address: null })]),
    )
    await mountView()

    const cell = document.body.querySelectorAll('tbody td')[2]!

    expect(cell.querySelector('[data-slot="data-table-empty-value"]')).not.toBeNull()
  })
})
