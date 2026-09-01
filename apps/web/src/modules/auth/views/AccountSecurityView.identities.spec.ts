import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'

// REQ-AUTH-002 (1.4), funcional.md §E.4.4/§E.4.5, api.md §E.5: el bloque
// "Cuentas vinculadas" de /cuenta/seguridad. Se cubre por separado del
// resto de AccountSecurityView.vue (sin tests previos, fuera de alcance
// de este paso) para mantener el fichero acotado a lo nuevo.
const getMfaStatus = vi.fn()
const getIdentities = vi.fn()
const unlinkIdentity = vi.fn()
const getIdentityProviders = vi.fn()
const beginOAuthAuthorization = vi.fn()

vi.mock('../api', () => ({
  getMfaStatus: (...args: unknown[]) => getMfaStatus(...args),
  getIdentities: (...args: unknown[]) => getIdentities(...args),
  unlinkIdentity: (...args: unknown[]) => unlinkIdentity(...args),
  getIdentityProviders: (...args: unknown[]) => getIdentityProviders(...args),
  beginOAuthAuthorization: (...args: unknown[]) => beginOAuthAuthorization(...args),
  regenerateMfaRecoveryCodes: vi.fn(),
  removeMfaFactor: vi.fn(),
  logout: vi.fn(),
}))

const { default: AccountSecurityView } = await import('./AccountSecurityView.vue')

const NON_ENFORCED_STATUS = {
  allowed_methods: ['totp'],
  factors: [],
  unused_recovery_codes_count: 0,
  mfa: {
    enrolled: false,
    obligated: false,
    enforced: false,
    grace_deadline_at: null,
    days_remaining: null,
    exempt_until: null,
  },
}

async function mountSecurityView() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/entrar', name: 'login', component: { template: '<div/>' } },
      { path: '/cuenta/seguridad', name: 'mfa-security', component: AccountSecurityView },
      {
        path: '/cuenta/seguridad/obligatorio',
        name: 'mfa-enrollment-wall',
        component: { template: '<div/>' },
      },
    ],
  })

  await router.push('/cuenta/seguridad')
  await router.isReady()

  const wrapper = mount(AccountSecurityView, { global: { plugins: [i18n, router] } })
  await flushPromises()

  return { wrapper, router }
}

describe('AccountSecurityView — cuentas vinculadas', () => {
  beforeEach(() => {
    getMfaStatus.mockReset().mockResolvedValue(NON_ENFORCED_STATUS)
    getIdentities.mockReset()
    unlinkIdentity.mockReset()
    getIdentityProviders.mockReset().mockResolvedValue({ data: [] })
    beginOAuthAuthorization.mockReset()
    setLocale('es')
  })

  it('api.md §E.5: pinta las cuentas vinculadas con el correo enmascarado por el servidor', async () => {
    getIdentities.mockResolvedValue({
      data: [
        {
          public_id: '01J-GOOGLE',
          provider: 'google',
          email_at_link: 'a***@gmail.com',
          link_method: 'perfil',
          linked_at: '2026-09-01T10:00:00Z',
          last_login_at: null,
        },
      ],
      meta: { total: 1 },
    })

    const { wrapper } = await mountSecurityView()

    expect(wrapper.text()).toContain('a***@gmail.com')
    expect(wrapper.text()).toContain('Vinculada desde tu perfil')
    expect(wrapper.text()).toContain('Todavía no la has usado para entrar')
    // RN-AUTH-89: como mucho un vínculo por proveedor hoy — sin botón de
    // vincular otro mientras exista uno vivo.
    expect(wrapper.text()).not.toContain('Vincular con Google')
  })

  it('funcional.md §E.9: sin ninguna cuenta vinculada y sin proveedor disponible, no ofrece vincular', async () => {
    getIdentities.mockResolvedValue({ data: [], meta: { total: 0 } })

    const { wrapper } = await mountSecurityView()

    expect(wrapper.text()).toContain('Todavía no tienes ninguna cuenta externa vinculada.')
    expect(wrapper.find('button').exists()).toBe(true) // solo el de "Desactivar"/enrolamiento, no Google
    expect(wrapper.text()).not.toContain('Vincular con Google')
  })

  it('RN-AUTH-96: desvincular exige contraseña actual y recarga la lista', async () => {
    getIdentities
      .mockResolvedValueOnce({
        data: [
          {
            public_id: '01J-GOOGLE',
            provider: 'google',
            email_at_link: 'a***@gmail.com',
            link_method: 'fusion_automatica',
            linked_at: '2026-09-01T10:00:00Z',
            last_login_at: '2026-09-14T08:00:00Z',
          },
        ],
        meta: { total: 1 },
      })
      .mockResolvedValueOnce({ data: [], meta: { total: 0 } })
    unlinkIdentity.mockResolvedValue(undefined)

    const { wrapper } = await mountSecurityView()

    const unlinkButton = wrapper.findAll('button').find((b) => b.text() === 'Desvincular')
    await unlinkButton?.trigger('click')
    await flushPromises()

    await wrapper.get('input[type="password"]').setValue('secreta123')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(unlinkIdentity).toHaveBeenCalledWith('01J-GOOGLE', 'secreta123')
    expect(getIdentities).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('Cuenta de Google desvinculada.')
    expect(wrapper.text()).toContain('Todavía no tienes ninguna cuenta externa vinculada.')
  })

  it('api.md §E.5: contraseña incorrecta responde 422 (no 401) y no desvincula', async () => {
    getIdentities.mockResolvedValue({
      data: [
        {
          public_id: '01J-GOOGLE',
          provider: 'google',
          email_at_link: 'a***@gmail.com',
          link_method: 'fusion_automatica',
          linked_at: '2026-09-01T10:00:00Z',
          last_login_at: null,
        },
      ],
      meta: { total: 1 },
    })
    unlinkIdentity.mockRejectedValue(
      new ApiError('validation', 422, {
        type: 'urn:pge:error:validation',
        title: 'validation',
        status: 422,
        errors: {
          current_password: [{ code: 'wrong', message: 'La contraseña actual no es correcta.' }],
        },
      }),
    )

    const { wrapper } = await mountSecurityView()

    const unlinkButton = wrapper.findAll('button').find((b) => b.text() === 'Desvincular')
    await unlinkButton?.trigger('click')
    await flushPromises()
    await wrapper.get('input[type="password"]').setValue('mala')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.text()).toContain('La contraseña actual no es correcta.')
    expect(getIdentities).toHaveBeenCalledTimes(1) // no se recargó: no hubo éxito
  })
})
