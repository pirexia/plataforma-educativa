/**
 * `docs/modulos/REQ-CORE/funcional.md §14.10c`, `§14.18` (1.9e): perfil propio —
 * `CA-CORE-265` (`RN-CORE-88`: solo correo y teléfono editables, sin selector de
 * idioma ni `GET /me`) y `CA-CORE-266` (`RN-CORE-89`: cuerpo exacto de `PATCH /me`,
 * sesión sustituida por la respuesta, mensaje de estado y `422` por campo).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'

const updateSessionProfile = vi.fn()
const getMe = vi.fn()

vi.mock('@/modules/core/api', () => ({ getMe: (...args: unknown[]) => getMe(...args) }))
vi.mock('../api', () => ({ getMe: (...args: unknown[]) => getMe(...args) }))

vi.mock('@/session/useSession', async () => {
  const { shallowRef } = await import('vue')
  const user = shallowRef<Record<string, unknown> | null>(null)

  return {
    useSession: () => ({
      user,
      updateSessionProfile: (...args: unknown[]) => updateSessionProfile(...args),
    }),
    __setUser: (value: Record<string, unknown> | null) => {
      user.value = value
    },
  }
})

const session = (await import('@/session/useSession')) as unknown as {
  __setUser: (value: Record<string, unknown> | null) => void
}
const { default: ProfileView } = await import('./ProfileView.vue')

function me(overrides: Record<string, unknown> = {}) {
  return {
    public_id: 'U1',
    email: 'ana@example.com',
    status: 'activo',
    person: {
      public_id: 'P1',
      given_name: 'Ana',
      family_name_1: 'García',
      family_name_2: 'Ruiz',
      contact_email: 'contacto@example.com',
      contact_phone: '600000000',
      document_type: null,
      document_number: null,
      birth_date: null,
      locale: 'es-ES',
    },
    roles: [],
    permissions: [],
    email_verified_at: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    deleted_at: null,
    ...overrides,
  }
}

function problem(status: number, body: Record<string, unknown> = {}): ApiError {
  return new ApiError(`HTTP ${status}`, status, { status, ...body })
}

const wrappers: VueWrapper[] = []

async function mountView(): Promise<VueWrapper> {
  const wrapper = mount(ProfileView, {
    global: { plugins: [i18n] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return wrapper
}

async function type(id: string, value: string): Promise<void> {
  const el = document.getElementById(`profile-${id}`) as HTMLInputElement

  el.value = value
  el.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}

beforeEach(() => {
  setLocale('es')
  session.__setUser(me())
  getMe.mockReset()
  updateSessionProfile.mockReset().mockResolvedValue(me())
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('CA-CORE-265 (RN-CORE-88): perfil propio', () => {
  it('muestra nombre y correo de acceso sin campo editable, y solo correo y teléfono de contacto editables', async () => {
    const wrapper = await mountView()
    const text = wrapper.text()

    expect(text).toContain('Ana García Ruiz')
    expect(text).toContain('ana@example.com')
    expect(text).toContain('no se cambia desde aquí')

    const inputs = wrapper.findAll('input')

    expect(inputs.map((input) => input.attributes('id'))).toEqual([
      'profile-contact_email',
      'profile-contact_phone',
    ])
    expect((inputs[0]!.element as HTMLInputElement).value).toBe('contacto@example.com')
    expect((inputs[1]!.element as HTMLInputElement).value).toBe('600000000')
    expect(wrapper.find('select').exists()).toBe(false)
    expect(wrapper.text()).not.toMatch(/Idioma/)
  })

  it('con /me.permissions vacío la pantalla se pinta igual y no pide GET /me', async () => {
    await mountView()

    expect(getMe).not.toHaveBeenCalled()
    expect(updateSessionProfile).not.toHaveBeenCalled()
  })

  it('cada campo tiene etiqueta asociada', async () => {
    await mountView()

    for (const id of ['profile-contact_email', 'profile-contact_phone']) {
      expect(
        document.querySelector(`label[for="${id}"]`)?.textContent?.trim().length,
      ).toBeGreaterThan(0)
    }
  })
})

describe('CA-CORE-266 (RN-CORE-89, ADR-038 §9.2, CA-CORE-018): guardar el perfil', () => {
  it('con solo el teléfono cambiado y el correo vaciado, el cuerpo es exactamente {contact_phone, contact_email: null}', async () => {
    const wrapper = await mountView()

    await type('contact_phone', '611223344')
    await type('contact_email', '')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(updateSessionProfile).toHaveBeenCalledTimes(1)
    expect(JSON.stringify({ person: updateSessionProfile.mock.calls[0]![0] })).toBe(
      '{"person":{"contact_email":null,"contact_phone":"611223344"}}',
    )
    expect(Object.keys(updateSessionProfile.mock.calls[0]![0] as object).sort()).toEqual([
      'contact_email',
      'contact_phone',
    ])
  })

  it('nunca envía email, status, roles ni otro campo', async () => {
    const wrapper = await mountView()

    await type('contact_phone', '611223344')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(updateSessionProfile.mock.calls[0]![0]).toEqual({ contact_phone: '611223344' })
  })

  it('con 200 aparece un mensaje con role="status" y no hay segunda petición a /me', async () => {
    const wrapper = await mountView()

    await type('contact_phone', '611223344')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="status"]').text()).toBe('Perfil guardado.')
    expect(getMe).not.toHaveBeenCalled()
  })

  it('sin cambios no sale ninguna petición', async () => {
    const wrapper = await mountView()

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(updateSessionProfile).not.toHaveBeenCalled()
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined()
  })

  it('con 422 en person.contact_email, el mensaje del servidor aparece bajo el campo con aria-invalid', async () => {
    updateSessionProfile.mockRejectedValueOnce(
      problem(422, {
        errors: {
          'person.contact_email': [{ code: 'email', message: 'El correo no es válido.' }],
        },
      }),
    )

    const wrapper = await mountView()

    await type('contact_email', 'no-es-un-correo')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const input = document.getElementById('profile-contact_email')!

    expect(input.getAttribute('aria-invalid')).toBe('true')
    expect(input.getAttribute('aria-describedby')).toBe('profile-contact_email-error')
    expect(document.getElementById('profile-contact_email-error')?.textContent).toContain(
      'El correo no es válido.',
    )
    expect(document.activeElement).toBe(input)
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('un 429 muestra el tiempo de espera y no pierde lo escrito', async () => {
    updateSessionProfile.mockRejectedValueOnce(
      new ApiError('HTTP 429', 429, { status: 429 }, new Headers({ 'Retry-After': '12' })),
    )

    const wrapper = await mountView()

    await type('contact_phone', '611223344')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('12 s')
    expect((document.getElementById('profile-contact_phone') as HTMLInputElement).value).toBe(
      '611223344',
    )
  })
})
