/**
 * `docs/modulos/REQ-CORE/funcional.md §14.9`, `§14.18` (1.9e): configuración del
 * centro — `CA-CORE-248` (solo lectura, `RN-CORE-79`), `-249` (`PATCH` por grupo
 * y solo lo modificado), `-250` (paleta, contraste y `refresh()`, `RN-CORE-80`),
 * `-251` (idiomas, `RN-CORE-81`) y el grupo `security` de `OPEN-CORE-37` = B.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'

const getTenantSettings = vi.fn()
const updateTenantSettings = vi.fn()
const refresh = vi.fn()

vi.mock('../api', () => ({
  getTenantSettings: (...args: unknown[]) => getTenantSettings(...args),
  updateTenantSettings: (...args: unknown[]) => updateTenantSettings(...args),
}))

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

vi.mock('@/tenant/useTenantBranding', () => ({
  useTenantBranding: () => ({ refresh: () => refresh() }),
}))

const session = (await import('@/session/useSession')) as unknown as {
  __setPermissions: (permissions: string[]) => void
}
const { default: SettingsView } = await import('./SettingsView.vue')

function settings(overrides: Record<string, unknown> = {}) {
  return {
    public_id: 'T1',
    regional: {
      default_locale: 'en',
      active_locales: ['es-ES', 'en'],
      timezone: 'Europe/Madrid',
      currency: 'EUR',
      autonomous_community: 'MD',
    },
    fiscal: {
      legal_name: 'Colegio Ficticio S.L.',
      tax_id: 'B00000000',
      address: 'Calle Inventada 1',
      postal_code: '28000',
      city: 'Madrid',
      province: 'Madrid',
      country_code: 'ES',
    },
    branding: {
      color_primary: '#1D4ED8',
      color_secondary: '#FFFFFF',
      logo_url: null,
      favicon_url: null,
      login_background_url: null,
    },
    security: {
      session_timeout_minutes: 30,
      mfa_allowed_methods: ['totp'],
      mfa_grace_period_days: 7,
    },
    updated_at: '2026-08-19T09:00:00Z',
    ...overrides,
  }
}

function problem(status: number, body: Record<string, unknown> = {}): ApiError {
  return new ApiError(`HTTP ${status}`, status, { status, ...body })
}

const wrappers: VueWrapper[] = []

async function mountView(): Promise<VueWrapper> {
  const stub = { template: '<div/>' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/administracion/centro', name: 'core-settings', component: SettingsView },
      { path: '/administracion/centro/marca', name: 'core-branding-assets', component: stub },
      { path: '/administracion/mfa', name: 'mfa-administration', component: stub },
    ],
  })

  await router.push('/administracion/centro')
  await router.isReady()

  const wrapper = mount(SettingsView, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return wrapper
}

function field(group: string, name: string): HTMLInputElement | HTMLSelectElement {
  return document.getElementById(`settings-${group}-${name}`) as HTMLInputElement
}

async function type(group: string, name: string, value: string): Promise<void> {
  const el = field(group, name)

  el.value = value
  el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }))
  await flushPromises()
}

async function check(group: string, name: string, checked: boolean): Promise<void> {
  const el = field(group, name) as HTMLInputElement

  el.checked = checked
  el.dispatchEvent(new Event('change', { bubbles: true }))
  await flushPromises()
}

async function saveGroup(wrapper: VueWrapper, group: string): Promise<void> {
  await wrapper.get(`section[aria-labelledby="settings-${group}-title"] form`).trigger('submit')
  await flushPromises()
}

beforeEach(() => {
  setLocale('es')
  getTenantSettings.mockReset().mockResolvedValue(settings())
  updateTenantSettings.mockReset().mockImplementation(async () => settings())
  refresh.mockReset().mockResolvedValue(undefined)
  session.__setPermissions(['configuracion.leer', 'configuracion.actualizar'])
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('CA-CORE-248 (RN-CORE-79): solo lectura sin configuracion.actualizar', () => {
  it('muestra los valores y no contiene campo editable, botón de guardar ni acción de escritura', async () => {
    session.__setPermissions(['configuracion.leer'])

    const wrapper = await mountView()
    const text = wrapper.text()

    expect(text).toContain('Colegio Ficticio S.L.')
    expect(text).toContain('Europe/Madrid')
    expect(text).toContain('Comunidad de Madrid')
    expect(text).toContain('#1D4ED8')
    expect(text).toContain('30 minutos')
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.find('input, select, textarea, button[type="submit"]').exists()).toBe(false)
    expect(updateTenantSettings).not.toHaveBeenCalled()
  })

  it('con configuracion.actualizar sí hay un formulario por grupo, cada uno con su botón', async () => {
    const wrapper = await mountView()

    expect(wrapper.findAll('form')).toHaveLength(4)
    expect(wrapper.findAll('button[type="submit"]').map((b) => b.text())).toEqual([
      'Guardar datos regionales',
      'Guardar datos fiscales',
      'Guardar paleta',
      'Guardar seguridad',
    ])
  })

  it('solo pide GET /tenant/settings: nada más al abrir', async () => {
    await mountView()

    expect(getTenantSettings).toHaveBeenCalledTimes(1)
    expect(updateTenantSettings).not.toHaveBeenCalled()
  })
})

describe('CA-CORE-249 (RN-CORE-79, ADR-038 §9.2): PATCH por grupo, solo lo modificado', () => {
  it('con solo el municipio cambiado, el cuerpo es exactamente {"fiscal":{"city":"…"}}', async () => {
    const wrapper = await mountView()

    await type('fiscal', 'city', 'Getafe')
    await saveGroup(wrapper, 'fiscal')

    expect(updateTenantSettings).toHaveBeenCalledTimes(1)
    expect(updateTenantSettings).toHaveBeenCalledWith({ fiscal: { city: 'Getafe' } })
    expect(wrapper.text()).toContain('Datos fiscales guardados.')
  })

  it('vaciar un campo opcional envía null, nunca ""', async () => {
    const wrapper = await mountView()

    await type('fiscal', 'province', '')
    await saveGroup(wrapper, 'fiscal')

    expect(updateTenantSettings).toHaveBeenCalledWith({ fiscal: { province: null } })
  })

  it('sin cambios el botón está deshabilitado y no sale ninguna petición', async () => {
    const wrapper = await mountView()
    const button = wrapper.get(
      'section[aria-labelledby="settings-fiscal-title"] button[type="submit"]',
    )

    expect(button.attributes('disabled')).toBeDefined()

    await saveGroup(wrapper, 'fiscal')

    expect(updateTenantSettings).not.toHaveBeenCalled()
  })

  it('guardar un grupo no envía ni descarta los cambios sin guardar de otro', async () => {
    const wrapper = await mountView()

    await type('fiscal', 'city', 'Getafe')
    await type('regional', 'currency', 'usd')
    await saveGroup(wrapper, 'regional')

    expect(updateTenantSettings).toHaveBeenCalledWith({ regional: { currency: 'USD' } })
    expect((field('fiscal', 'city') as HTMLInputElement).value).toBe('Getafe')

    updateTenantSettings.mockClear()
    await saveGroup(wrapper, 'fiscal')

    expect(updateTenantSettings).toHaveBeenCalledWith({ fiscal: { city: 'Getafe' } })
  })

  it('un 422 pinta el mensaje del servidor bajo el campo con aria-invalid y aria-describedby', async () => {
    updateTenantSettings.mockRejectedValueOnce(
      problem(422, {
        errors: {
          'fiscal.country_code': [{ code: 'regex', message: 'El país no es válido.' }],
        },
      }),
    )

    const wrapper = await mountView()

    await type('fiscal', 'country_code', 'xx')
    await saveGroup(wrapper, 'fiscal')

    const input = field('fiscal', 'country_code')

    expect(input.getAttribute('aria-invalid')).toBe('true')
    expect(input.getAttribute('aria-describedby')).toContain('settings-fiscal-country_code-error')
    expect(document.getElementById('settings-fiscal-country_code-error')?.textContent).toContain(
      'El país no es válido.',
    )
    expect(document.activeElement).toBe(input)
  })
})

describe('CA-CORE-250 (RN-CORE-80, RUX-BRAND-006): paleta', () => {
  it('con contraste 3,1:1 la vista previa muestra «3,1:1» y que no alcanza 4,5:1', async () => {
    const wrapper = await mountView()

    // #909090 sobre blanco da ≈ 3,2:1: por debajo del 4,5:1 de RUX-BRAND-006.
    await type('palette', 'color_primary', '#909090')
    await type('palette', 'color_secondary', '#FFFFFF')

    const section = wrapper.get('section[aria-labelledby="settings-palette-title"]').text()

    expect(section).toMatch(/Contraste: 3,\d+:1/)
    expect(section).toContain('No alcanza el mínimo de 4,5:1')
  })

  it('la razón se formatea según el idioma activo (en: 3.1:1)', async () => {
    setLocale('en')

    const wrapper = await mountView()

    await type('palette', 'color_primary', '#909090')
    await type('palette', 'color_secondary', '#FFFFFF')

    expect(wrapper.get('section[aria-labelledby="settings-palette-title"]').text()).toMatch(
      /Contrast: 3\.\d+:1/,
    )
  })

  it('con una paleta válida cumple el mínimo', async () => {
    const wrapper = await mountView()

    expect(wrapper.get('section[aria-labelledby="settings-palette-title"]').text()).toContain(
      'Cumple el mínimo de 4,5:1',
    )
  })

  it('no aplica la paleta al documento mientras se edita', async () => {
    await mountView()
    await type('palette', 'color_primary', '#909090')

    expect(document.documentElement.style.getPropertyValue('--primary')).toBe('')
  })

  it('un 422 contrast_insufficient muestra la razón y el mínimo del servidor', async () => {
    updateTenantSettings.mockRejectedValueOnce(
      problem(422, {
        errors: {
          branding: [
            {
              code: 'contrast_insufficient',
              message: 'El contraste de la paleta es insuficiente.',
              params: { ratio: 3.1, required: 4.5 },
            },
          ],
        },
      }),
    )

    const wrapper = await mountView()

    await type('palette', 'color_primary', '#909090')
    await saveGroup(wrapper, 'palette')

    const text = wrapper.get('section[aria-labelledby="settings-palette-title"]').text()

    expect(text).toContain('El contraste de la paleta es insuficiente.')
    expect(text).toContain('contraste de 3,1:1')
    expect(text).toContain('el mínimo exigido es 4,5:1')
    expect(refresh).not.toHaveBeenCalled()
  })

  it('una paleta válida responde 200: se llama una vez a refresh() y el cuerpo lleva solo lo modificado', async () => {
    const wrapper = await mountView()

    await type('palette', 'color_primary', '#0B3D91')
    await saveGroup(wrapper, 'palette')

    expect(updateTenantSettings).toHaveBeenCalledWith({ branding: { color_primary: '#0B3D91' } })
    expect(refresh).toHaveBeenCalledTimes(1)
    expect(wrapper.text()).toContain('Paleta guardada.')
  })
})

describe('CA-CORE-251 (RN-CORE-81, RN-CORE-13): idiomas', () => {
  it('al desmarcar el idioma por defecto deja de ofrecerse y no se puede guardar hasta elegir otro', async () => {
    const wrapper = await mountView()

    expect(
      [...(field('regional', 'default_locale') as HTMLSelectElement).options].map((o) => o.value),
    ).toEqual(['es-ES', 'en'])

    // El idioma activo de la interfaz es es: retirar `en` no dispara el aviso.
    const checkboxes = wrapper.findAll('fieldset input[type="checkbox"]')
    const english = checkboxes.find((box) =>
      box.element.closest('label')?.textContent?.includes('Inglés'),
    )!

    ;(english.element as HTMLInputElement).checked = false
    await english.trigger('change')
    await flushPromises()

    const select = field('regional', 'default_locale') as HTMLSelectElement
    const values = [...select.options].map((o) => o.value)

    expect(values).not.toContain('en')
    expect(select.value).toBe('')

    const button = wrapper.get(
      'section[aria-labelledby="settings-regional-title"] button[type="submit"]',
    )

    expect(button.attributes('disabled')).toBeDefined()

    await type('regional', 'default_locale', 'es-ES')

    expect(button.attributes('disabled')).toBeUndefined()

    await saveGroup(wrapper, 'regional')

    expect(updateTenantSettings).toHaveBeenCalledWith({
      regional: { default_locale: 'es-ES', active_locales: ['es-ES'] },
    })
    expect(refresh).toHaveBeenCalledTimes(1)
  })

  it('con la interfaz en en, retirar en avisa de que pasará al idioma por defecto', async () => {
    setLocale('en')

    const wrapper = await mountView()

    expect(wrapper.find('[role="status"]').exists()).toBe(false)

    const english = wrapper
      .findAll('fieldset input[type="checkbox"]')
      .find((box) => box.element.closest('label')?.textContent?.includes('English'))!

    ;(english.element as HTMLInputElement).checked = false
    await english.trigger('change')
    await flushPromises()

    expect(wrapper.get('[role="status"]').text()).toContain('default language')
  })

  it('no permite guardar sin ningún idioma activo', async () => {
    const wrapper = await mountView()

    for (const box of wrapper.findAll('fieldset input[type="checkbox"]')) {
      ;(box.element as HTMLInputElement).checked = false
      await box.trigger('change')
    }
    await flushPromises()

    expect(wrapper.text()).toContain('Marca al menos un idioma activo.')
    expect(
      wrapper
        .get('section[aria-labelledby="settings-regional-title"] button[type="submit"]')
        .attributes('disabled'),
    ).toBeDefined()
  })
})

describe('RN-CORE-82: catálogos', () => {
  it('la moneda se envía en mayúsculas y la comunidad autónoma «sin indicar» se envía como null', async () => {
    const wrapper = await mountView()

    await type('regional', 'autonomous_community', '')
    await type('regional', 'currency', 'gbp')
    await saveGroup(wrapper, 'regional')

    expect(updateTenantSettings).toHaveBeenCalledWith({
      regional: { currency: 'GBP', autonomous_community: null },
    })
  })

  it('la zona horaria se busca por texto y conserva el valor actual como opción', async () => {
    await mountView()

    const search = document.getElementById('settings-regional-timezone-search') as HTMLInputElement

    search.value = 'tokyo'
    search.dispatchEvent(new Event('input', { bubbles: true }))
    await flushPromises()

    const values = [...(field('regional', 'timezone') as HTMLSelectElement).options].map(
      (option) => option.value,
    )

    expect(values).toContain('Europe/Madrid')
    expect(values).toContain('Asia/Tokyo')
    expect(values).not.toContain('Europe/Paris')
  })
})

describe('OPEN-CORE-37 = B: grupo security (lo que /administracion/mfa no edita)', () => {
  it('muestra las tres claves de security.* y envía solo la modificada', async () => {
    const wrapper = await mountView()

    expect((field('security', 'session_timeout_minutes') as HTMLInputElement).value).toBe('30')
    expect((field('security', 'mfa_grace_period_days') as HTMLInputElement).value).toBe('7')
    expect((field('security', 'mfa_allowed_methods') as HTMLInputElement).checked).toBe(false)

    await type('security', 'session_timeout_minutes', '60')
    await saveGroup(wrapper, 'security')

    expect(updateTenantSettings).toHaveBeenCalledWith({ security: { session_timeout_minutes: 60 } })
  })

  it('totp va siempre marcado y deshabilitado; sms no se ofrece; el correo añade email', async () => {
    const wrapper = await mountView()
    const group = wrapper.get('section[aria-labelledby="settings-security-title"] fieldset')
    const boxes = group.findAll('input[type="checkbox"]')

    expect(boxes).toHaveLength(2)
    expect((boxes[0]!.element as HTMLInputElement).checked).toBe(true)
    expect(boxes[0]!.attributes('disabled')).toBeDefined()
    expect(group.text()).not.toContain('SMS message')
    expect(group.text()).not.toContain('Mensaje SMS')

    await check('security', 'mfa_allowed_methods', true)
    await saveGroup(wrapper, 'security')

    expect(updateTenantSettings).toHaveBeenCalledWith({
      security: { mfa_allowed_methods: ['totp', 'email'] },
    })
  })

  it('issue #324: un valor no entero no se envía y se muestra el error bajo el campo', async () => {
    const wrapper = await mountView()

    await type('security', 'mfa_grace_period_days', '')
    await saveGroup(wrapper, 'security')

    expect(updateTenantSettings).not.toHaveBeenCalled()
    expect(
      document.getElementById('settings-security-mfa_grace_period_days-error')?.textContent,
    ).toContain('Introduce un número entero.')

    await type('security', 'mfa_grace_period_days', '1.5')
    await saveGroup(wrapper, 'security')

    expect(updateTenantSettings).not.toHaveBeenCalled()

    await type('security', 'mfa_grace_period_days', '10')
    await saveGroup(wrapper, 'security')

    expect(updateTenantSettings).toHaveBeenCalledWith({ security: { mfa_grace_period_days: 10 } })
  })

  it('issue #323: alternar el correo conserva el resto de métodos que devuelve el servidor', async () => {
    getTenantSettings.mockResolvedValue(
      settings({
        security: {
          session_timeout_minutes: 30,
          mfa_allowed_methods: ['totp', 'sms'],
          mfa_grace_period_days: 7,
        },
      }),
    )
    let wrapper = await mountView()

    await check('security', 'mfa_allowed_methods', true)
    await saveGroup(wrapper, 'security')

    expect(updateTenantSettings).toHaveBeenLastCalledWith({
      security: { mfa_allowed_methods: ['totp', 'sms', 'email'] },
    })

    wrapper.unmount()
    getTenantSettings.mockResolvedValue(
      settings({
        security: {
          session_timeout_minutes: 30,
          mfa_allowed_methods: ['totp', 'email', 'sms'],
          mfa_grace_period_days: 7,
        },
      }),
    )
    wrapper = await mountView()

    await check('security', 'mfa_allowed_methods', false)
    await saveGroup(wrapper, 'security')

    expect(updateTenantSettings).toHaveBeenLastCalledWith({
      security: { mfa_allowed_methods: ['totp', 'sms'] },
    })
  })

  it('enlaza a /administracion/mfa solo con algún permiso de esa pantalla (RN-CORE-62)', async () => {
    let wrapper = await mountView()

    expect(wrapper.find('a[href="/administracion/mfa"]').exists()).toBe(false)

    wrapper.unmount()
    session.__setPermissions(['configuracion.leer', 'configuracion.actualizar', 'mfa.leer'])
    wrapper = await mountView()

    expect(wrapper.find('a[href="/administracion/mfa"]').exists()).toBe(true)
  })

  it('un 422 de mfa_allowed_methods se pinta bajo el grupo de métodos', async () => {
    updateTenantSettings.mockRejectedValueOnce(
      problem(422, {
        errors: {
          'security.mfa_allowed_methods': [{ code: 'x', message: 'No se admite SMS todavía.' }],
        },
      }),
    )

    const wrapper = await mountView()

    await check('security', 'mfa_allowed_methods', true)
    await saveGroup(wrapper, 'security')

    expect(
      document.getElementById('settings-security-mfa_allowed_methods-error')?.textContent,
    ).toContain('No se admite SMS todavía.')
  })

  it('si la respuesta no trae el grupo security, la sección no se pinta', async () => {
    getTenantSettings.mockResolvedValue(settings({ security: undefined }))

    const wrapper = await mountView()

    expect(wrapper.find('section[aria-labelledby="settings-security-title"]').exists()).toBe(false)
  })
})

describe('estados de carga y enlaces', () => {
  it('un 403 al cargar pinta el estado de error y ningún formulario (§12.6)', async () => {
    getTenantSettings.mockRejectedValue(problem(403))

    const wrapper = await mountView()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('enlaza a «Activos de marca»', async () => {
    const wrapper = await mountView()

    expect(wrapper.find('a[href="/administracion/centro/marca"]').exists()).toBe(true)
  })

  it('RN-CORE-50: no guarda nada de la configuración en el almacenamiento del navegador', async () => {
    const spy = vi.spyOn(Storage.prototype, 'setItem')
    const wrapper = await mountView()

    await type('fiscal', 'city', 'Getafe')
    await saveGroup(wrapper, 'fiscal')

    expect(spy).not.toHaveBeenCalled()
    spy.mockRestore()
  })
})
