/**
 * `docs/modulos/REQ-CORE/funcional.md §14.4.3`, `§14.18`: alta y edición de
 * usuario (1.9b) — `CA-CORE-217` (errores por campo, foco y resumen),
 * `-218` (`PATCH` solo con lo modificado), `-219` (`403` de `RPERM-013`
 * junto al selector de roles), `-221` (mensaje con la caducidad, sin token)
 * y `-260` (etiquetas y marca de obligatorio no solo visual); y, desde 1.9c,
 * `CA-CORE-281` (selector del catálogo cerrado de tipos de documento,
 * `RN-CORE-90`, issue #292).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'
import { DOCUMENT_TYPES } from '../documentTypes'

const createUser = vi.fn()
const updateUser = vi.fn()
const getUser = vi.fn()
const listRoles = vi.fn()

vi.mock('../api', () => ({
  createUser: (...args: unknown[]) => createUser(...args),
  updateUser: (...args: unknown[]) => updateUser(...args),
  getUser: (...args: unknown[]) => getUser(...args),
  listRoles: (...args: unknown[]) => listRoles(...args),
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

vi.mock('@/tenant/useTenantBranding', async () => {
  const { shallowRef } = await import('vue')

  return {
    useTenantBranding: () => ({
      branding: shallowRef({ active_locales: ['es-ES', 'en', 'de'], default_locale: 'en' }),
    }),
  }
})

const session = (await import('@/session/useSession')) as unknown as {
  __setPermissions: (permissions: string[]) => void
}
const { takeFlash } = await import('../composables/useFlash')
const { default: UserFormView } = await import('./UserFormView.vue')

function problem(status: number, body: Record<string, unknown> = {}): ApiError {
  return new ApiError(`HTTP ${status}`, status, { status, ...body })
}

const wrappers: VueWrapper[] = []

async function mountView(path: string): Promise<{ wrapper: VueWrapper; router: Router }> {
  const stub = { template: '<div/>' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/administracion/usuarios', name: 'core-users', component: stub },
      { path: '/administracion/usuarios/nuevo', name: 'core-user-new', component: UserFormView },
      { path: '/administracion/usuarios/:publicId', name: 'core-user-detail', component: stub },
      {
        path: '/administracion/usuarios/:publicId/editar',
        name: 'core-user-edit',
        component: UserFormView,
      },
    ],
  })

  await router.push(path)
  await router.isReady()

  const wrapper = mount(UserFormView, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return { wrapper, router }
}

function input(id: string): HTMLInputElement {
  return document.getElementById(`user-form-${id}`) as HTMLInputElement
}

async function type(id: string, value: string): Promise<void> {
  const el = input(id)

  el.value = value
  el.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}

async function submit(wrapper: VueWrapper): Promise<void> {
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

function loadedUser() {
  return {
    public_id: 'U1',
    email: 'ana@example.com',
    status: 'activo',
    person: {
      public_id: 'P1',
      given_name: 'Ana',
      family_name_1: 'López',
      family_name_2: 'Ruiz',
      contact_email: 'contacto@example.com',
      contact_phone: '699111222',
      document_type: 'DNI',
      document_number: 'ZZ-DOC-1',
      birth_date: '1990-05-05',
      locale: 'es-ES',
    },
    roles: [],
    email_verified_at: null,
    created_at: '2026-09-01T10:00:00Z',
    updated_at: '2026-09-01T10:00:00Z',
    deleted_at: null,
  }
}

beforeEach(() => {
  setLocale('es')
  createUser.mockReset()
  updateUser.mockReset().mockResolvedValue(loadedUser())
  getUser.mockReset().mockResolvedValue(loadedUser())
  listRoles.mockReset().mockResolvedValue({
    data: [
      { public_id: 'R1', code: 'docente', name: 'Docente' },
      { public_id: 'R2', code: 'secretaria', name: 'Secretaría' },
    ],
    meta: { current_page: 1, per_page: 100, total: 2, last_page: 1 },
  })
  session.__setPermissions(['usuario.crear'])
  takeFlash()
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('alta (RN-CORE-65): payload y campos', () => {
  it('envía solo los campos rellenos, el idioma por defecto del centro y la invitación marcada por defecto', async () => {
    createUser.mockResolvedValue({ ...loadedUser(), invitation: null })
    const { wrapper } = await mountView('/administracion/usuarios/nuevo')

    expect((input('locale') as unknown as HTMLSelectElement).value).toBe('en')
    expect(document.body.querySelector<HTMLInputElement>('input[type="checkbox"]')!.checked).toBe(
      true,
    )

    await type('email', 'nueva@example.com')
    await type('given_name', 'Marta')
    await type('family_name_1', 'Ruiz')
    await submit(wrapper)

    expect(createUser).toHaveBeenCalledWith({
      email: 'nueva@example.com',
      person: { given_name: 'Marta', family_name_1: 'Ruiz', locale: 'en' },
      send_invitation: true,
    })
  })

  it('los campos usan el tipo de entrada adecuado para teclado táctil', async () => {
    await mountView('/administracion/usuarios/nuevo')

    expect(input('email').type).toBe('email')
    expect(input('contact_email').type).toBe('email')
    expect(input('contact_phone').type).toBe('tel')
    expect(input('birth_date').type).toBe('date')
  })
})

describe('CA-CORE-281 (RN-CORE-90, #292): el tipo de documento es un selector del catálogo cerrado', () => {
  function optionValues(id: string): string[] {
    return [...(input(id) as unknown as HTMLSelectElement).options].map((option) => option.value)
  }

  function optionTexts(id: string): string[] {
    return [...(input(id) as unknown as HTMLSelectElement).options].map(
      (option) => option.textContent?.trim() ?? '',
    )
  }

  it('«Sin indicar» más exactamente los códigos de la constante, con etiqueta traducida', async () => {
    await mountView('/administracion/usuarios/nuevo')

    expect(input('document_type').tagName).toBe('SELECT')
    expect(optionValues('document_type')).toEqual(['', ...DOCUMENT_TYPES])
    expect(optionTexts('document_type')).toEqual([
      'Sin indicar',
      'DNI (documento nacional de identidad)',
      'NIE (número de identidad de extranjero)',
      'Pasaporte',
    ])
    // La pista de «texto libre» ya no existe.
    expect(document.getElementById('user-form-document_type-hint')).toBeNull()
  })

  it('con «Sin indicar» el número está deshabilitado y el cuerpo del alta no contiene ni tipo ni número', async () => {
    createUser.mockResolvedValue({ ...loadedUser(), invitation: null })
    const { wrapper } = await mountView('/administracion/usuarios/nuevo')

    expect(input('document_number').disabled).toBe(true)

    await type('email', 'nueva@example.com')
    await type('given_name', 'Marta')
    await type('family_name_1', 'Ruiz')
    await submit(wrapper)

    const body = createUser.mock.calls[0]![0] as { person: Record<string, unknown> }

    expect(body.person).not.toHaveProperty('document_type')
    expect(body.person).not.toHaveProperty('document_number')
  })

  it('al elegir un tipo se habilita el número y los dos viajan; el cliente no normaliza el número (decide el servidor)', async () => {
    createUser.mockResolvedValue({ ...loadedUser(), invitation: null })
    const { wrapper } = await mountView('/administracion/usuarios/nuevo')

    const select = input('document_type') as unknown as HTMLSelectElement

    select.value = 'dni'
    select.dispatchEvent(new Event('change', { bubbles: true }))
    await flushPromises()

    expect(input('document_number').disabled).toBe(false)

    await type('document_number', ' 12345678-z ')
    await type('email', 'nueva@example.com')
    await type('given_name', 'Marta')
    await type('family_name_1', 'Ruiz')
    await submit(wrapper)

    expect(createUser.mock.calls[0]![0].person).toMatchObject({
      document_type: 'dni',
      document_number: '12345678-z',
    })
  })

  it('al editar a una persona con un tipo anterior al catálogo, el selector lo conserva con su código crudo y el PATCH no lo envía si no se toca', async () => {
    const { wrapper } = await mountView('/administracion/usuarios/U1/editar')

    expect(optionValues('document_type')).toContain('DNI')
    expect(optionTexts('document_type')).toContain('DNI')
    expect((input('document_type') as unknown as HTMLSelectElement).value).toBe('DNI')

    await type('given_name', 'Anabel')
    await submit(wrapper)

    expect(updateUser).toHaveBeenCalledWith('U1', { person: { given_name: 'Anabel' } })
  })

  it('al editar, pasar a «Sin indicar» envía los dos a null (vaciar el par, RN-CORE-93)', async () => {
    const { wrapper } = await mountView('/administracion/usuarios/U1/editar')

    const select = input('document_type') as unknown as HTMLSelectElement

    select.value = ''
    select.dispatchEvent(new Event('change', { bubbles: true }))
    await flushPromises()

    expect(input('document_number').disabled).toBe(true)

    await submit(wrapper)

    expect(updateUser).toHaveBeenCalledWith('U1', {
      person: { document_type: null, document_number: null },
    })
  })

  it('un 422 del servidor en person.document_type se pinta bajo el selector con aria-invalid', async () => {
    createUser.mockRejectedValue(
      problem(422, {
        errors: {
          'person.document_type': [
            {
              code: 'core.validation.document_type_invalid',
              message: 'El tipo de documento «carnet» no está admitido.',
            },
          ],
        },
      }),
    )
    const { wrapper } = await mountView('/administracion/usuarios/nuevo')

    await type('email', 'nueva@example.com')
    await type('given_name', 'Marta')
    await type('family_name_1', 'Ruiz')
    await submit(wrapper)

    expect(input('document_type').getAttribute('aria-invalid')).toBe('true')
    expect(document.getElementById('user-form-document_type-error')?.textContent).toContain(
      'carnet',
    )
    expect(document.activeElement).toBe(input('document_type'))
  })
})

describe('CA-CORE-217 (RN-CORE-65, INV-010): errores del servidor por campo', () => {
  it('pinta cada mensaje bajo su campo con aria-invalid y aria-describedby, enfoca el correo y enumera en un resumen', async () => {
    createUser.mockRejectedValue(
      problem(422, {
        errors: {
          email: [{ code: 'email', message: 'El correo no es válido.' }],
          'person.locale': [{ code: 'in', message: 'El idioma no está disponible.' }],
        },
      }),
    )
    const { wrapper } = await mountView('/administracion/usuarios/nuevo')

    await type('email', 'mal')
    await type('given_name', 'Marta')
    await type('family_name_1', 'Ruiz')
    await submit(wrapper)

    expect(input('email').getAttribute('aria-invalid')).toBe('true')
    expect(input('email').getAttribute('aria-describedby')).toBe('user-form-email-error')
    expect(document.getElementById('user-form-email-error')?.textContent).toContain(
      'El correo no es válido.',
    )
    expect(input('locale').getAttribute('aria-invalid')).toBe('true')
    expect(document.getElementById('user-form-locale-error')?.textContent).toContain(
      'El idioma no está disponible.',
    )

    expect(document.activeElement).toBe(input('email'))

    const summary = wrapper.get('form [role="alert"]')

    expect(summary.text()).toContain('El correo no es válido.')
    expect(summary.text()).toContain('El idioma no está disponible.')
  })
})

describe('CA-CORE-219 (RPERM-013, OPEN-CORE-43): roles en el alta', () => {
  it('sin rol.leer y asignacion_rol.crear no hay selector ni GET /roles', async () => {
    await mountView('/administracion/usuarios/nuevo')

    expect(listRoles).not.toHaveBeenCalled()
    expect(document.body.textContent).not.toContain('Secretaría')
  })

  it('con ambos permisos se ofrece el selector y role_ids viaja en la petición', async () => {
    session.__setPermissions(['usuario.crear', 'rol.leer', 'asignacion_rol.crear'])
    createUser.mockResolvedValue({ ...loadedUser(), invitation: null })
    const { wrapper } = await mountView('/administracion/usuarios/nuevo')

    expect(listRoles).toHaveBeenCalledTimes(1)

    const roleBox = document.getElementById('user-form-roles') as HTMLInputElement

    roleBox.checked = true
    roleBox.dispatchEvent(new Event('change', { bubbles: true }))
    await type('email', 'nueva@example.com')
    await type('given_name', 'Marta')
    await type('family_name_1', 'Ruiz')
    await submit(wrapper)

    expect(createUser.mock.calls[0]![0]).toMatchObject({ role_ids: ['R1'] })
  })

  it('un 403 de RPERM-013 muestra el detail junto al selector de roles y no navega', async () => {
    session.__setPermissions(['usuario.crear', 'rol.leer', 'asignacion_rol.crear'])
    createUser.mockRejectedValue(
      problem(403, { detail: 'No puedes asignar un rol que concede permisos que tú no tienes.' }),
    )
    const { wrapper, router } = await mountView('/administracion/usuarios/nuevo')

    const roleBox = document.getElementById('user-form-roles') as HTMLInputElement

    roleBox.checked = true
    roleBox.dispatchEvent(new Event('change', { bubbles: true }))
    await type('email', 'nueva@example.com')
    await type('given_name', 'Marta')
    await type('family_name_1', 'Ruiz')
    await submit(wrapper)

    expect(document.getElementById('user-form-roles-error')?.textContent).toContain(
      'No puedes asignar un rol que concede permisos',
    )
    expect(router.currentRoute.value.name).toBe('core-user-new')
  })
})

describe('CA-CORE-221 (RN-CORE-66, RN-CORE-19): alta con invitación', () => {
  it('navega a la ficha y deja un mensaje con la caducidad formateada; sin token en ningún sitio', async () => {
    createUser.mockResolvedValue({
      ...loadedUser(),
      invitation: { public_id: 'I1', expires_at: '2026-12-31T10:00:00Z' },
    })
    const { wrapper, router } = await mountView('/administracion/usuarios/nuevo')

    await type('email', 'nueva@example.com')
    await type('given_name', 'Marta')
    await type('family_name_1', 'Ruiz')
    await submit(wrapper)

    expect(router.currentRoute.value.name).toBe('core-user-detail')
    expect(router.currentRoute.value.params.publicId).toBe('U1')

    const flash = takeFlash()

    expect(flash?.key).toBe('core.users.flash.createdInvited')
    expect(String(flash?.params?.date)).toContain('2026')
    expect(JSON.stringify({ ...window.localStorage })).not.toMatch(/token/i)
  })
})

describe('CA-CORE-218 (RN-CORE-65, ADR-038 §9.2): PATCH solo con lo modificado', () => {
  it('cambiar el teléfono y vaciar el segundo apellido envía exactamente esas dos claves, vacía con null', async () => {
    session.__setPermissions(['usuario.actualizar'])
    const { wrapper } = await mountView('/administracion/usuarios/U1/editar')

    expect(getUser).toHaveBeenCalledWith('U1')
    expect(input('email').value).toBe('ana@example.com')

    await type('contact_phone', '600000000')
    await type('family_name_2', '')
    await submit(wrapper)

    expect(updateUser).toHaveBeenCalledTimes(1)
    expect(updateUser).toHaveBeenCalledWith('U1', {
      person: { contact_phone: '600000000', family_name_2: null },
    })
    expect(document.body.querySelector('#user-form-roles')).toBeNull()
  })

  it('sin cambios no envía ninguna petición', async () => {
    session.__setPermissions(['usuario.actualizar'])
    const { wrapper } = await mountView('/administracion/usuarios/U1/editar')

    await submit(wrapper)

    expect(updateUser).not.toHaveBeenCalled()
  })

  it('la edición no ofrece roles ni invitación', async () => {
    session.__setPermissions(['usuario.actualizar', 'rol.leer', 'asignacion_rol.crear'])
    await mountView('/administracion/usuarios/U1/editar')

    expect(listRoles).not.toHaveBeenCalled()
    expect(document.body.textContent).not.toContain('Enviar invitación por correo')
  })
})

describe('CA-CORE-260 (RUX-004): etiquetas y obligatorios no solo visuales', () => {
  it('todo campo tiene su etiqueta asociada y los obligatorios llevan texto para lectores de pantalla', async () => {
    await mountView('/administracion/usuarios/nuevo')

    for (const id of [
      'email',
      'given_name',
      'family_name_1',
      'family_name_2',
      'contact_phone',
      'locale',
    ]) {
      expect(document.querySelector(`label[for="user-form-${id}"]`), id).not.toBeNull()
    }

    for (const id of ['email', 'given_name', 'family_name_1']) {
      const label = document.querySelector(`label[for="user-form-${id}"]`)!

      expect(label.textContent).toContain('(obligatorio)')
      expect(input(id).required).toBe(true)
    }
  })
})
