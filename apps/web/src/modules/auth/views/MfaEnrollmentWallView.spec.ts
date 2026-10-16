/**
 * #427, `RN-CORE-26`, `RN-CORE-28`, `CA-CORE-090` (INV-015): el muro de MFA
 * recarga la sesión (`GET /me`) antes de navegar fuera de él; si no, el
 * estado sigue en `mfa-required` y el guard devolvería al muro.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'

const calls: string[] = []
const getMfaStatus = vi.fn()
const logout = vi.fn()
const reloadSession = vi.fn()

vi.mock('../api', () => ({
  getMfaStatus: (...args: unknown[]) => getMfaStatus(...args),
  logout: (...args: unknown[]) => logout(...args),
}))

vi.mock('@/session/useSession', () => ({
  reloadSession: (...args: unknown[]) => reloadSession(...args),
}))

const { default: MfaEnrollmentWallView } = await import('./MfaEnrollmentWallView.vue')

function makeRouter() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div/>' } },
      { path: '/cuenta/seguridad', name: 'mfa-security', component: { template: '<div/>' } },
      { path: '/entrar', name: 'login', component: { template: '<div/>' } },
      { path: '/muro', name: 'mfa-enrollment-wall', component: MfaEnrollmentWallView },
    ],
  })
  router.afterEach((to) => {
    calls.push(`push:${String(to.name)}`)
  })
  return router
}

async function mountWall() {
  const router = makeRouter()
  await router.push('/muro')
  await router.isReady()
  calls.length = 0

  const wrapper = mount(MfaEnrollmentWallView, {
    global: {
      plugins: [i18n, router],
      stubs: { MfaTotpEnrollment: true, MfaEmailEnrollment: true },
    },
  })
  await flushPromises()

  return { wrapper, router }
}

beforeEach(() => {
  calls.length = 0
  getMfaStatus.mockReset()
  logout.mockReset()
  reloadSession.mockReset()
  reloadSession.mockImplementation(async () => {
    calls.push('reload')
  })
  setLocale('es')
})

describe('MfaEnrollmentWallView — recarga de sesión antes de salir (#427)', () => {
  it('con enforced=false recarga la sesión y solo después navega a home', async () => {
    getMfaStatus.mockResolvedValue({
      mfa: { enforced: false, obligated: false },
      allowed_methods: ['totp'],
    })

    const { router } = await mountWall()

    expect(calls).toEqual(['reload', 'push:home'])
    expect(router.currentRoute.value.name).toBe('home')
  })

  it('con enforced=false y obligated=true navega a mfa-security tras recargar', async () => {
    getMfaStatus.mockResolvedValue({
      mfa: { enforced: false, obligated: true },
      allowed_methods: ['totp'],
    })

    const { router } = await mountWall()

    expect(calls).toEqual(['reload', 'push:mfa-security'])
    expect(router.currentRoute.value.name).toBe('mfa-security')
  })

  it('continueToApp recarga la sesión y solo después navega a home', async () => {
    getMfaStatus.mockResolvedValue({
      mfa: { enforced: true, obligated: true },
      allowed_methods: ['totp'],
    })

    const { wrapper, router } = await mountWall()
    expect(calls).toEqual([])

    wrapper.getComponent({ name: 'MfaTotpEnrollment' }).vm.$emit('enrolled')
    await flushPromises()

    const button = wrapper
      .findAll('button')
      .find((b) => b.text() === i18n.global.t('auth.mfa.wall.continue'))
    expect(button).toBeDefined()
    await button!.trigger('click')
    await flushPromises()

    expect(calls).toEqual(['reload', 'push:home'])
    expect(router.currentRoute.value.name).toBe('home')
  })
})
