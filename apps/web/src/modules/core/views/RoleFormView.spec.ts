/**
 * `docs/modulos/REQ-PERM/funcional.md §20.6`, `§20.7`, `§20.17.3`: alta,
 * clonación y edición de datos de un rol — `CA-PERM-104` (código propuesto,
 * cuerpo exacto del `POST`, `422 role_code_taken`, navegación al editor),
 * `CA-PERM-105` (`special_data_access` por permiso y por posesión derivada),
 * `CA-PERM-106` (clonación: aviso, cuerpo exacto, `422` y `403 errors.grant[0]`),
 * `CA-PERM-107` (`PATCH` solo con lo modificado, nunca `code` ni `permissions`).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { ApiError } from '@/api/client'
import { i18n, setLocale } from '@/i18n'
import { takeFlash } from '../composables/useFlash'

const createRole = vi.fn()
const getRole = vi.fn()
const listRoles = vi.fn()
const updateRole = vi.fn()

vi.mock('../api', () => ({
  createRole: (...args: unknown[]) => createRole(...args),
  getRole: (...args: unknown[]) => getRole(...args),
  listRoles: (...args: unknown[]) => listRoles(...args),
  updateRole: (...args: unknown[]) => updateRole(...args),
}))

vi.mock('@/session/useSession', async () => {
  const { shallowRef } = await import('vue')
  const user = shallowRef<{
    public_id: string
    permissions: string[]
    roles: { public_id: string; code: string; name: string }[]
  } | null>(null)

  return {
    useSession: () => ({ user }),
    __setUser: (permissions: string[], roles: string[] = []) => {
      user.value = {
        public_id: 'ME',
        permissions,
        roles: roles.map((id) => ({ public_id: id, code: id.toLowerCase(), name: id })),
      }
    },
  }
})

const session = (await import('@/session/useSession')) as unknown as {
  __setUser: (permissions: string[], roles?: string[]) => void
}
const { default: RoleFormView } = await import('./RoleFormView.vue')

const WRITE = ['rol.leer', 'rol.crear', 'rol.actualizar']

function role(overrides: Record<string, unknown> = {}) {
  return {
    public_id: 'R1',
    code: 'coordinacion',
    name: 'Coordinación pastoral',
    is_system: false,
    mfa_required: true,
    special_data_access: false,
    users_count: 7,
    permissions: [
      {
        code: 'auditoria.leer',
        resource: 'auditoria',
        action: 'leer',
        effect: 'allow',
        scope: 'todos',
      },
      {
        code: 'usuario.leer',
        resource: 'usuario',
        action: 'leer',
        effect: 'allow',
        scope: 'todos',
      },
    ],
    ...overrides,
  }
}

const wrappers: VueWrapper[] = []
let router: Router

async function mountAt(path: string): Promise<VueWrapper> {
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/administracion/roles', name: 'core-roles', component: { template: '<div/>' } },
      { path: '/administracion/roles/nuevo', name: 'core-role-new', component: RoleFormView },
      {
        path: '/administracion/roles/:publicId',
        name: 'core-role-detail',
        component: { template: '<div/>' },
      },
      {
        path: '/administracion/roles/:publicId/clonar',
        name: 'core-role-clone',
        component: RoleFormView,
      },
      {
        path: '/administracion/roles/:publicId/editar',
        name: 'core-role-edit',
        component: RoleFormView,
      },
      {
        path: '/administracion/roles/:publicId/permisos',
        name: 'core-role-permissions',
        component: { template: '<div/>' },
      },
      {
        path: '/administracion/mfa',
        name: 'mfa-administration',
        component: { template: '<div/>' },
      },
    ],
  })

  await router.push(path)
  await router.isReady()

  const wrapper = mount(RoleFormView, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return wrapper
}

function problem(status: number, body: Record<string, unknown>): ApiError {
  return new ApiError(`HTTP ${status}`, status, body)
}

async function type(wrapper: VueWrapper, selector: string, value: string): Promise<void> {
  await wrapper.get(selector).setValue(value)
}

async function submit(wrapper: VueWrapper): Promise<void> {
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

function dialogButton(label: string): HTMLButtonElement {
  const found = [...document.body.querySelectorAll('[role="alertdialog"] button')].find(
    (button) => button.textContent?.trim() === label,
  )

  if (!found) {
    throw new Error(`No hay botón «${label}» en el diálogo`)
  }

  return found as HTMLButtonElement
}

async function click(el: Element): Promise<void> {
  el.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await flushPromises()
}

beforeEach(() => {
  setLocale('es')
  createRole.mockReset().mockResolvedValue(role({ public_id: 'NEW', name: 'Nuevo' }))
  getRole.mockReset().mockResolvedValue(role())
  listRoles.mockReset().mockResolvedValue({
    data: [],
    meta: { current_page: 1, per_page: 100, total: 0, last_page: 1 },
  })
  updateRole.mockReset().mockResolvedValue(role())
  takeFlash()
  session.__setUser(WRITE)
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('CA-PERM-104 (RN-PERM-29, RPERM-005, RPERM-014): alta', () => {
  it('propone el código a partir del nombre (minúsculas, sin tildes, espacios a _) y sigue editable', async () => {
    const wrapper = await mountAt('/administracion/roles/nuevo')

    await type(wrapper, '#role-form-name', 'Coordinación de auditoría')

    expect((wrapper.get('#role-form-code').element as HTMLInputElement).value).toBe(
      'coordinacion_de_auditoria',
    )

    await type(wrapper, '#role-form-code', 'mi_codigo')
    await type(wrapper, '#role-form-name', 'Otro nombre distinto')

    expect((wrapper.get('#role-form-code').element as HTMLInputElement).value).toBe('mi_codigo')
  })

  it('el código propuesto se recorta a 64 caracteres', async () => {
    const wrapper = await mountAt('/administracion/roles/nuevo')

    await type(wrapper, '#role-form-name', 'a'.repeat(100))

    expect((wrapper.get('#role-form-code').element as HTMLInputElement).value).toHaveLength(64)
  })

  it('con mfa_required marcado, el cuerpo del POST es exactamente {code, name, mfa_required} y se navega al editor de concesiones', async () => {
    const wrapper = await mountAt('/administracion/roles/nuevo')

    await type(wrapper, '#role-form-name', 'Coordinación de auditoría')
    await wrapper.get('#role-form-mfa').setValue(true)
    await submit(wrapper)

    expect(createRole).toHaveBeenCalledExactlyOnceWith({
      code: 'coordinacion_de_auditoria',
      name: 'Coordinación de auditoría',
      mfa_required: true,
    })
    expect(router.currentRoute.value.name).toBe('core-role-permissions')
    expect(router.currentRoute.value.params.publicId).toBe('NEW')
    expect(takeFlash()?.key).toBe('core.roles.flash.created')
  })

  it('sin tocar nada más, el cuerpo no lleva mfa_required, special_data_access, is_system, name_key ni permissions', async () => {
    const wrapper = await mountAt('/administracion/roles/nuevo')

    await type(wrapper, '#role-form-name', 'Mi rol')
    await submit(wrapper)

    const body = createRole.mock.calls[0]![0] as Record<string, unknown>

    expect(Object.keys(body).sort()).toEqual(['code', 'name'])
  })

  it('un 422 con errors.code[0] = role_code_taken pinta el mensaje bajo el campo con aria-invalid y el foco va a él', async () => {
    createRole.mockRejectedValue(
      problem(422, {
        errors: {
          code: [
            { code: 'core.validation.role_code_taken', message: 'Ese código ya está en uso.' },
          ],
        },
      }),
    )
    const wrapper = await mountAt('/administracion/roles/nuevo')

    await type(wrapper, '#role-form-name', 'Mi rol')
    await submit(wrapper)

    const input = wrapper.get('#role-form-code')

    expect(wrapper.get('#role-form-code-error').text()).toBe('Ese código ya está en uso.')
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(input.attributes('aria-describedby')).toContain('role-form-code-error')
    expect(document.activeElement).toBe(input.element)
    expect(router.currentRoute.value.name).toBe('core-role-new')
  })

  it('un código con formato inválido no envía nada y lo dice en el campo (comodidad, INV-010)', async () => {
    const wrapper = await mountAt('/administracion/roles/nuevo')

    await type(wrapper, '#role-form-name', 'Mi rol')
    await type(wrapper, '#role-form-code', '1_mal')
    await submit(wrapper)

    expect(createRole).not.toHaveBeenCalled()
    expect(wrapper.get('#role-form-code-error').text()).toContain('El código debe empezar')
  })

  it('el nombre y el código llevan la marca de obligatorio en texto y la ayuda asociada', async () => {
    const wrapper = await mountAt('/administracion/roles/nuevo')

    expect(wrapper.get('label[for="role-form-name"]').text()).toContain('obligatorio')
    expect(wrapper.get('#role-form-name-help').text()).toBe(
      'Se muestra igual en todos los idiomas.',
    )
    expect(wrapper.get('#role-form-name').attributes('aria-describedby')).toContain(
      'role-form-name-help',
    )
    expect(wrapper.get('#role-form-code-help').text()).toBe(
      'Identificador interno; no podrá cambiarse.',
    )
  })

  it('sin rol.actualizar, tras crear se va a la ficha en lugar de a una ruta prohibida', async () => {
    session.__setUser(['rol.leer', 'rol.crear'])
    const wrapper = await mountAt('/administracion/roles/nuevo')

    await type(wrapper, '#role-form-name', 'Mi rol')
    await submit(wrapper)

    expect(router.currentRoute.value.name).toBe('core-role-detail')
  })

  it('un 429 muestra el mensaje de demasiados intentos', async () => {
    createRole.mockRejectedValue(problem(429, {}))
    const wrapper = await mountAt('/administracion/roles/nuevo')

    await type(wrapper, '#role-form-name', 'Mi rol')
    await submit(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toContain('Demasiados intentos')
  })
})

describe('CA-PERM-105 (RN-PERM-31, RN-PERM-11): special_data_access', () => {
  it('sin rol_datos_especiales.actualizar, ni el alta ni la edición contienen el control', async () => {
    const created = await mountAt('/administracion/roles/nuevo')

    expect(created.find('#role-form-special').exists()).toBe(false)

    const edited = await mountAt('/administracion/roles/R1/editar')

    expect(edited.find('#role-form-special').exists()).toBe(false)
  })

  it('con el permiso y rol.leer, si ninguno de los roles de /me tiene el atributo, el control está deshabilitado con el texto de no posesión', async () => {
    session.__setUser([...WRITE, 'rol_datos_especiales.actualizar'], ['R9'])
    listRoles.mockResolvedValue({
      data: [
        { public_id: 'R9', code: 'r9', name: 'R9', special_data_access: false },
        { public_id: 'R8', code: 'r8', name: 'R8', special_data_access: true },
      ],
      meta: { current_page: 1, per_page: 100, total: 2, last_page: 1 },
    })
    const wrapper = await mountAt('/administracion/roles/nuevo')
    const control = wrapper.get('#role-form-special')

    expect(control.attributes('disabled')).toBeDefined()
    expect(wrapper.get('#role-form-special-not-held').text()).toBe(
      'No puedes activar este atributo: ninguno de tus roles tiene acceso a datos de categoría especial',
    )
    expect(control.attributes('aria-describedby')).toContain('role-form-special-not-held')
    expect(wrapper.get('#role-form-special-hint').text()).toContain(
      'solo surten efecto en roles con este atributo',
    )
  })

  it('si alguno de sus roles tiene el atributo, el control está habilitado y se envía special_data_access: true', async () => {
    session.__setUser([...WRITE, 'rol_datos_especiales.actualizar'], ['R9'])
    listRoles.mockResolvedValue({
      data: [{ public_id: 'R9', code: 'r9', name: 'R9', special_data_access: true }],
      meta: { current_page: 1, per_page: 100, total: 1, last_page: 1 },
    })
    const wrapper = await mountAt('/administracion/roles/nuevo')

    expect(wrapper.get('#role-form-special').attributes('disabled')).toBeUndefined()

    await type(wrapper, '#role-form-name', 'Mi rol')
    await wrapper.get('#role-form-special').setValue(true)
    await submit(wrapper)

    expect(createRole).toHaveBeenCalledExactlyOnceWith({
      code: 'mi_rol',
      name: 'Mi rol',
      special_data_access: true,
    })
  })

  it('con el permiso y sin rol.leer: control habilitado, no se pide GET /roles y un 403 se muestra junto al control con role="alert"', async () => {
    session.__setUser(['rol.crear', 'rol.actualizar', 'rol_datos_especiales.actualizar'], ['R9'])
    createRole.mockRejectedValue(
      problem(403, {
        detail: 'No puedes activar este atributo: no lo tienes.',
        errors: {},
      }),
    )
    const wrapper = await mountAt('/administracion/roles/nuevo')

    expect(listRoles).not.toHaveBeenCalled()
    expect(wrapper.get('#role-form-special').attributes('disabled')).toBeUndefined()

    await type(wrapper, '#role-form-name', 'Mi rol')
    await wrapper.get('#role-form-special').setValue(true)
    await submit(wrapper)

    const alert = wrapper.get('[role="alert"]')

    expect(alert.text()).toBe('No puedes activar este atributo: no lo tienes.')
    expect(wrapper.find('#role-form-special-error').exists()).toBe(true)
    expect(wrapper.get('#role-form-special').attributes('aria-invalid')).toBe('true')
  })

  it('si los roles propios no aparecen en GET /roles, la posesión es desconocida y el control se habilita', async () => {
    session.__setUser([...WRITE, 'rol_datos_especiales.actualizar'], ['R9'])
    listRoles.mockResolvedValue({
      data: [{ public_id: 'OTRO', code: 'o', name: 'O', special_data_access: false }],
      meta: { current_page: 1, per_page: 100, total: 1, last_page: 1 },
    })
    const wrapper = await mountAt('/administracion/roles/nuevo')

    expect(wrapper.get('#role-form-special').attributes('disabled')).toBeUndefined()
  })

  it('recorre las páginas de GET /roles hasta cubrir los roles propios', async () => {
    session.__setUser([...WRITE, 'rol_datos_especiales.actualizar'], ['R9'])
    listRoles
      .mockResolvedValueOnce({
        data: [{ public_id: 'OTRO', code: 'o', name: 'O', special_data_access: false }],
        meta: { current_page: 1, per_page: 100, total: 101, last_page: 2 },
      })
      .mockResolvedValueOnce({
        data: [{ public_id: 'R9', code: 'r9', name: 'R9', special_data_access: false }],
        meta: { current_page: 2, per_page: 100, total: 101, last_page: 2 },
      })
    const wrapper = await mountAt('/administracion/roles/nuevo')

    expect(listRoles).toHaveBeenCalledTimes(2)
    expect(listRoles).toHaveBeenNthCalledWith(2, { page: 2, per_page: 100 })
    expect(wrapper.get('#role-form-special').attributes('disabled')).toBeDefined()
  })
})

describe('CA-PERM-106 (RN-PERM-30, RPERM-006): clonación', () => {
  it('muestra el rol de origen, la explicación y, si tiene special_data_access, el aviso previo; el botón no se deshabilita', async () => {
    getRole.mockResolvedValue(role({ special_data_access: true }))
    const wrapper = await mountAt('/administracion/roles/R1/clonar')

    expect(getRole).toHaveBeenCalledExactlyOnceWith('R1')
    expect(wrapper.get('#role-form-source-title').text()).toContain('Coordinación pastoral')
    expect(wrapper.text()).toContain('2 concesiones')
    expect(wrapper.text()).toContain('No se copian los titulares')
    expect(wrapper.text()).toContain('desligado del origen')
    expect(wrapper.text()).toContain('solo podrás clonarlo si puedes activar ese atributo')
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeUndefined()
  })

  it('sin special_data_access en el origen no hay aviso previo', async () => {
    const wrapper = await mountAt('/administracion/roles/R1/clonar')

    expect(wrapper.text()).not.toContain('solo podrás clonarlo')
  })

  it('el cuerpo del POST contiene exactamente clone_from, code y name; y no hay control de mfa ni de special', async () => {
    const wrapper = await mountAt('/administracion/roles/R1/clonar')

    expect(wrapper.find('#role-form-mfa').exists()).toBe(false)
    expect(wrapper.find('#role-form-special').exists()).toBe(false)

    await type(wrapper, '#role-form-name', 'Copia de coordinación')
    await submit(wrapper)

    expect(createRole).toHaveBeenCalledExactlyOnceWith({
      clone_from: 'R1',
      code: 'copia_de_coordinacion',
      name: 'Copia de coordinación',
    })
    expect(router.currentRoute.value.name).toBe('core-role-detail')
    expect(router.currentRoute.value.params.publicId).toBe('NEW')
    expect(takeFlash()).toEqual({
      key: 'core.roles.flash.cloned',
      params: { name: 'Nuevo', source: 'Coordinación pastoral' },
    })
  })

  it('un 422 clone_requires_special_data_access muestra el mensaje del servidor y no navega', async () => {
    createRole.mockRejectedValue(
      problem(422, {
        errors: {
          special_data_access: [
            {
              code: 'core.validation.clone_requires_special_data_access',
              message: 'No puedes clonar un rol con acceso a datos especiales sin poseerlo.',
            },
          ],
        },
      }),
    )
    const wrapper = await mountAt('/administracion/roles/R1/clonar')

    await type(wrapper, '#role-form-name', 'Copia')
    await submit(wrapper)

    expect(wrapper.get('#role-form-summary').text()).toContain(
      'No puedes clonar un rol con acceso a datos especiales sin poseerlo.',
    )
    expect(router.currentRoute.value.name).toBe('core-role-clone')
  })

  it('un 403 con errors.grant[0] muestra el detail, el permiso y el ámbito traducido, y que no se ha creado nada', async () => {
    createRole.mockRejectedValue(
      problem(403, {
        detail: 'No puedes conceder un permiso que no tienes.',
        errors: {
          grant: [
            {
              code: 'core.authorization.cannot_grant_unheld_permission',
              message: 'No puedes conceder un permiso que no tienes.',
              params: { code: 'auditoria.leer', scope: 'todos' },
            },
          ],
        },
      }),
    )
    const wrapper = await mountAt('/administracion/roles/R1/clonar')

    await type(wrapper, '#role-form-name', 'Copia')
    await submit(wrapper)

    const summary = wrapper.get('#role-form-summary')

    expect(summary.text()).toContain('No puedes conceder un permiso que no tienes.')
    expect(summary.text()).toContain('Permiso: auditoria.leer. Ámbito: Todos.')
    expect(summary.text()).toContain(
      'El rol de origen concede algo que tú no tienes; no se ha creado nada.',
    )
    expect(router.currentRoute.value.name).toBe('core-role-clone')
  })

  it('sin rol.leer no se pide el rol de origen y se explica qué permiso falta (RN-PERM-25)', async () => {
    session.__setUser(['rol.crear'])
    const wrapper = await mountAt('/administracion/roles/R1/clonar')

    expect(getRole).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('necesitas además el permiso rol.leer')
    expect(wrapper.find('form').exists()).toBe(false)
  })
})

describe('CA-PERM-107 (RN-PERM-29, RN-CORE-65, ADR-038 §9.2): edición de datos', () => {
  it('si solo cambia el nombre, el cuerpo del PATCH es exactamente {name} y nunca lleva code ni permissions', async () => {
    const wrapper = await mountAt('/administracion/roles/R1/editar')

    expect((wrapper.get('#role-form-name').element as HTMLInputElement).value).toBe(
      'Coordinación pastoral',
    )

    await type(wrapper, '#role-form-name', 'Coordinación general')
    await submit(wrapper)

    expect(updateRole).toHaveBeenCalledExactlyOnceWith('R1', { name: 'Coordinación general' })
    expect(router.currentRoute.value.name).toBe('core-role-detail')
    expect(takeFlash()?.key).toBe('core.roles.flash.updated')
  })

  it('el código se muestra y no es un campo; sin cambios no sale ninguna petición', async () => {
    const wrapper = await mountAt('/administracion/roles/R1/editar')

    expect(wrapper.find('#role-form-code').exists()).toBe(false)
    expect(wrapper.text()).toContain('coordinacion')

    await submit(wrapper)

    expect(updateRole).not.toHaveBeenCalled()
    expect(router.currentRoute.value.name).toBe('core-role-detail')
  })

  it('en un rol del sistema el nombre no es editable y se explica; mfa_required es solo lectura con enlace a la administración de MFA', async () => {
    getRole.mockResolvedValue(role({ is_system: true, name: 'Docente' }))
    const wrapper = await mountAt('/administracion/roles/R1/editar')

    expect(wrapper.find('#role-form-name').exists()).toBe(false)
    expect(wrapper.text()).toContain('El nombre de un rol del sistema no se edita.')
    expect(wrapper.text()).toContain('MFA obligatorio: Sí')
    expect(wrapper.find('#role-form-mfa').exists()).toBe(false)

    const link = wrapper
      .findAll('a')
      .find((a) => a.text() === 'Cambiar en la configuración de MFA')!

    expect(link.attributes('href')).toBe('/administracion/mfa')
  })

  it('activar special_data_access pide confirmación con el recuento de titulares y solo entonces envía {special_data_access: true}', async () => {
    session.__setUser([...WRITE, 'rol_datos_especiales.actualizar'], ['R9'])
    listRoles.mockResolvedValue({
      data: [{ public_id: 'R9', code: 'r9', name: 'R9', special_data_access: true }],
      meta: { current_page: 1, per_page: 100, total: 1, last_page: 1 },
    })
    const wrapper = await mountAt('/administracion/roles/R1/editar')

    await wrapper.get('#role-form-special').setValue(true)
    await submit(wrapper)

    expect(updateRole).not.toHaveBeenCalled()

    const dialog = document.body.querySelector('[role="alertdialog"]')!

    expect(dialog.textContent).toContain('Activar el acceso a datos de categoría especial')
    expect(dialog.textContent).toContain('para 7 titulares')

    await click(dialogButton('Activar en «Coordinación pastoral»'))

    expect(updateRole).toHaveBeenCalledExactlyOnceWith('R1', { special_data_access: true })
  })

  it('cancelar la confirmación de special_data_access no envía nada', async () => {
    session.__setUser([...WRITE, 'rol_datos_especiales.actualizar'], ['R9'])
    listRoles.mockResolvedValue({
      data: [{ public_id: 'R9', code: 'r9', name: 'R9', special_data_access: true }],
      meta: { current_page: 1, per_page: 100, total: 1, last_page: 1 },
    })
    const wrapper = await mountAt('/administracion/roles/R1/editar')

    await wrapper.get('#role-form-special').setValue(true)
    await submit(wrapper)
    await click(dialogButton('Cancelar'))

    expect(updateRole).not.toHaveBeenCalled()
  })

  it('desactivarlo dice que las concesiones dejarán de surtir efecto', async () => {
    getRole.mockResolvedValue(role({ special_data_access: true }))
    session.__setUser([...WRITE, 'rol_datos_especiales.actualizar'], ['R9'])
    listRoles.mockResolvedValue({
      data: [{ public_id: 'R9', code: 'r9', name: 'R9', special_data_access: true }],
      meta: { current_page: 1, per_page: 100, total: 1, last_page: 1 },
    })
    const wrapper = await mountAt('/administracion/roles/R1/editar')

    await wrapper.get('#role-form-special').setValue(false)
    await submit(wrapper)

    expect(document.body.querySelector('[role="alertdialog"]')!.textContent).toContain(
      'dejarán de surtir efecto para 7 titulares',
    )
  })

  it('un 422 pinta el error bajo el campo name', async () => {
    updateRole.mockRejectedValue(
      problem(422, { errors: { name: [{ code: 'x', message: 'El nombre es obligatorio.' }] } }),
    )
    const wrapper = await mountAt('/administracion/roles/R1/editar')

    await type(wrapper, '#role-form-name', 'Otro')
    await submit(wrapper)

    expect(wrapper.get('#role-form-name-error').text()).toBe('El nombre es obligatorio.')
    expect(wrapper.get('#role-form-name').attributes('aria-invalid')).toBe('true')
  })

  it('sin rol.leer no se pide el rol y se explica qué permiso falta', async () => {
    session.__setUser(['rol.actualizar'])
    const wrapper = await mountAt('/administracion/roles/R1/editar')

    expect(getRole).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('necesitas además el permiso rol.leer')
  })

  it('un 404 al cargar pinta «no encontrado»', async () => {
    getRole.mockRejectedValue(new ApiError('HTTP 404', 404, {}))
    const wrapper = await mountAt('/administracion/roles/R1/editar')

    expect(wrapper.text()).toContain('Página no encontrada')
  })
})
