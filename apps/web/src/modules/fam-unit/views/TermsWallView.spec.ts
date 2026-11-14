/**
 * `CA-FAM-UNIT-076` (parte de SPA), `ADR-067 §5.11`, `INV-015`: el muro de condiciones muestra el texto pendiente, registra
 * la aceptación, **recarga la sesión antes de navegar** (o el *guard* devolvería al muro con `session_walls` desfasado) y
 * siempre deja salir (cerrar sesión). `REQ-FAM-UNIT-003`.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'

const calls: string[] = []
const getMyTerms = vi.fn()
const acceptMyTerms = vi.fn()
const logout = vi.fn()
const reloadSession = vi.fn()
const clearSession = vi.fn()

vi.mock('../api', () => ({
  getMyTerms: (...args: unknown[]) => getMyTerms(...args),
  acceptMyTerms: (...args: unknown[]) => acceptMyTerms(...args),
}))

vi.mock('@/modules/auth/api', () => ({
  logout: (...args: unknown[]) => logout(...args),
}))

vi.mock('@/session/useSession', () => ({
  reloadSession: (...args: unknown[]) => reloadSession(...args),
  clearSession: (...args: unknown[]) => clearSession(...args),
}))

const { default: TermsWallView } = await import('./TermsWallView.vue')
const { ApiError } = await import('@/api/client')

const VERSION = {
  public_id: '01JB0000000000000000000040',
  kind: 'condiciones_uso',
  version_number: 3,
  title: 'Condiciones de uso del centro',
  body: 'Primer párrafo.\n\nSegundo párrafo con <b>marcado</b> que debe verse como texto.',
  status: 'publicada',
  requires_reacceptance: true,
  published_at: '2026-10-28T08:00:00Z',
}

function makeRouter() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div/>' } },
      { path: '/entrar', name: 'login', component: { template: '<div/>' } },
      { path: '/condiciones-de-uso', name: 'fam-unit-terms-wall', component: TermsWallView },
    ],
  })
  router.afterEach((to) => {
    calls.push(`push:${String(to.name)}`)
  })
  return router
}

async function mountWall() {
  const router = makeRouter()
  await router.push('/condiciones-de-uso')
  await router.isReady()
  calls.length = 0

  const wrapper = mount(TermsWallView, { global: { plugins: [i18n, router] } })
  await flushPromises()

  return { wrapper, router }
}

beforeEach(() => {
  setLocale('es')
  calls.length = 0
  getMyTerms.mockReset()
  acceptMyTerms.mockReset()
  logout.mockReset().mockResolvedValue(undefined)
  clearSession.mockReset()
  reloadSession.mockReset().mockImplementation(async () => {
    calls.push('reloadSession')
  })
})

describe('CA-FAM-UNIT-076: pantalla del muro de condiciones', () => {
  it('muestra el título y el texto pendiente como texto (no como HTML) en una región con nombre', async () => {
    getMyTerms.mockResolvedValue({ required: true, version: VERSION })

    const { wrapper } = await mountWall()

    expect(wrapper.find('h1').text()).toBe('Condiciones de uso')
    expect(wrapper.find('h2').text()).toBe(VERSION.title)

    const body = wrapper.get('[data-testid="terms-body"]')
    expect(body.text()).toContain('<b>marcado</b>')
    expect(body.find('b').exists()).toBe(false)
    expect(body.attributes('role')).toBe('region')
    expect(body.attributes('aria-label')).toBe('Texto de las condiciones de uso')
    expect(body.attributes('tabindex')).toBe('0')
  })

  it('aceptar registra la versión mostrada, recarga la sesión y solo después navega al inicio', async () => {
    getMyTerms.mockResolvedValue({ required: true, version: VERSION })
    acceptMyTerms.mockResolvedValue({ public_id: '01JA' })

    const { wrapper, router } = await mountWall()
    const accept = wrapper
      .findAll('button')
      .find((b) => b.text() === 'Acepto las condiciones de uso')

    await accept!.trigger('click')
    await flushPromises()

    expect(acceptMyTerms).toHaveBeenCalledWith(VERSION.public_id)
    expect(calls).toEqual(['reloadSession', 'push:home'])
    expect(router.currentRoute.value.name).toBe('home')
  })

  it('sin nada pendiente (ya aceptó) no pinta el muro: recarga la sesión y va al inicio', async () => {
    getMyTerms.mockResolvedValue({ required: false, version: null })

    const { router } = await mountWall()

    expect(calls).toEqual(['reloadSession', 'push:home'])
    expect(router.currentRoute.value.name).toBe('home')
  })

  it('409 version_not_current: vuelve a pedir el texto y avisa de que cambió, sin salir del muro', async () => {
    getMyTerms.mockResolvedValueOnce({ required: true, version: VERSION }).mockResolvedValueOnce({
      required: true,
      version: { ...VERSION, public_id: '01JB0000000000000000000041', version_number: 4 },
    })
    acceptMyTerms.mockRejectedValue(new ApiError('409', 409, { type: 'urn:pge:error:conflict' }))

    const { wrapper, router } = await mountWall()
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Acepto las condiciones de uso')!
      .trigger('click')
    await flushPromises()

    expect(getMyTerms).toHaveBeenCalledTimes(2)
    expect(wrapper.get('[role="alert"]').text()).toContain('nueva versión')
    expect(router.currentRoute.value.name).toBe('fam-unit-terms-wall')
    expect(reloadSession).not.toHaveBeenCalled()
  })

  it('un error al aceptar se anuncia con role=alert y no navega', async () => {
    getMyTerms.mockResolvedValue({ required: true, version: VERSION })
    acceptMyTerms.mockRejectedValue(new ApiError('500', 500, null))

    const { wrapper, router } = await mountWall()
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Acepto las condiciones de uso')!
      .trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('No se ha podido registrar')
    expect(router.currentRoute.value.name).toBe('fam-unit-terms-wall')
  })

  it('un error al cargar se anuncia con role=alert y deja cerrar sesión', async () => {
    getMyTerms.mockRejectedValue(new ApiError('503', 503, null))

    const { wrapper } = await mountWall()

    expect(wrapper.get('[role="alert"]').text()).toContain('No se han podido cargar')
    expect(wrapper.findAll('button').some((b) => b.text() === 'Cerrar sesión')).toBe(true)
  })

  it('cerrar sesión siempre está disponible: cierra, vacía el estado y va a /entrar', async () => {
    getMyTerms.mockResolvedValue({ required: true, version: VERSION })

    const { wrapper, router } = await mountWall()
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Cerrar sesión')!
      .trigger('click')
    await flushPromises()

    expect(logout).toHaveBeenCalledTimes(1)
    expect(clearSession).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.name).toBe('login')
  })
})
