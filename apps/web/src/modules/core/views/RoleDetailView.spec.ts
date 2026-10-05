/**
 * `docs/modulos/REQ-PERM/funcional.md §20.5`, `§20.9`, `§20.17.2`, `§20.17.5`:
 * ficha de un rol — `CA-PERM-102` (acciones por permiso, sin `GET /permissions`
 * sin `permiso.leer`), `CA-PERM-117` (rol del sistema: sin «Editar concesiones»,
 * con el texto que remite a «Clonar»), `CA-PERM-118` (baja con titulares, sin
 * titulares y `409` con `errors.role[0].params`), `RN-PERM-28` (`resource_label`
 * y recarga al cambiar de idioma) y `RN-PERM-46` (permiso, nunca código de rol).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { ApiError } from '@/api/client'
import { i18n, setLocale } from '@/i18n'
import { takeFlash } from '../composables/useFlash'

const getRole = vi.fn()
const listPermissions = vi.fn()
const deleteRole = vi.fn()

vi.mock('../api', () => ({
  getRole: (...args: unknown[]) => getRole(...args),
  listPermissions: (...args: unknown[]) => listPermissions(...args),
  deleteRole: (...args: unknown[]) => deleteRole(...args),
}))

vi.mock('@/session/useSession', async () => {
  const { shallowRef } = await import('vue')
  const user = shallowRef<{ public_id: string; permissions: string[]; roles: [] } | null>(null)

  return {
    useSession: () => ({ user }),
    __setPermissions: (permissions: string[]) => {
      user.value = { public_id: 'ME', permissions, roles: [] }
    },
  }
})

const session = (await import('@/session/useSession')) as unknown as {
  __setPermissions: (permissions: string[]) => void
}
const { default: RoleDetailView } = await import('./RoleDetailView.vue')

const ALL = ['rol.leer', 'rol.crear', 'rol.actualizar', 'rol.eliminar', 'usuario.leer']

function grant(
  code: string,
  effect: 'allow' | 'deny',
  scope: string,
  label: string,
  extra: Record<string, unknown> = {},
) {
  const [resource = code, action = 'leer'] = code.split('.')

  return { code, resource, action, effect, scope, resource_label: label, ...extra }
}

function role(overrides: Record<string, unknown> = {}) {
  return {
    public_id: 'R1',
    code: 'coordinacion',
    name: 'Coordinación pastoral',
    is_system: false,
    mfa_required: true,
    special_data_access: false,
    users_count: 0,
    permissions: [
      grant('auditoria.leer', 'allow', 'propios', 'Auditoría'),
      grant('usuario.leer', 'allow', 'todos', 'Usuarios'),
      grant('salud.leer', 'allow', 'todos', 'Salud'),
      grant('usuario.eliminar', 'deny', 'todos', 'Usuarios'),
    ],
    ...overrides,
  }
}

function catalog() {
  return {
    data: [
      { code: 'auditoria.leer', is_special_category: false },
      { code: 'usuario.leer', is_special_category: false },
      { code: 'salud.leer', is_special_category: true },
      { code: 'usuario.eliminar', is_special_category: false },
    ],
  }
}

const wrappers: VueWrapper[] = []
let router: Router

async function mountView(): Promise<VueWrapper> {
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/administracion/roles', name: 'core-roles', component: { template: '<div/>' } },
      {
        path: '/administracion/roles/:publicId',
        name: 'core-role-detail',
        component: RoleDetailView,
      },
      {
        path: '/administracion/roles/:publicId/editar',
        name: 'core-role-edit',
        component: { template: '<div/>' },
      },
      {
        path: '/administracion/roles/:publicId/clonar',
        name: 'core-role-clone',
        component: { template: '<div/>' },
      },
      {
        path: '/administracion/roles/:publicId/permisos',
        name: 'core-role-permissions',
        component: { template: '<div/>' },
      },
      { path: '/administracion/usuarios', name: 'core-users', component: { template: '<div/>' } },
    ],
  })

  await router.push('/administracion/roles/R1')
  await router.isReady()

  const wrapper = mount(RoleDetailView, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return wrapper
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

function deleteButton(wrapper: VueWrapper) {
  return wrapper.findAll('button').find((button) => button.text() === 'Eliminar')
}

beforeEach(() => {
  setLocale('es')
  getRole.mockReset().mockResolvedValue(role())
  listPermissions.mockReset().mockResolvedValue(catalog())
  deleteRole.mockReset().mockResolvedValue(null)
  takeFlash()
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
  session.__setPermissions([...ALL, 'permiso.leer'])
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('RN-PERM-26 / §20.5: datos, titulares y concesiones', () => {
  it('pinta nombre, código interno, tipo, MFA, categoría especial y titulares, y las concesiones con etiqueta de recurso, acción, efecto y ámbito', async () => {
    const wrapper = await mountView()

    expect(wrapper.find('h1').text()).toBe('Coordinación pastoral')
    expect(wrapper.text()).toContain('Código interno')
    expect(wrapper.find('code').text()).toBe('coordinacion')
    expect(wrapper.text()).toContain('Personalizado')
    expect(wrapper.get('[data-slot="role-users-count"]').text()).toBe('0')

    const rows = wrapper
      .findAll('tbody tr')
      .map((tr) => tr.findAll('th, td').map((cell) => cell.text()))

    expect(rows).toContainEqual(['Auditoría', 'Leer', 'Permitir', 'Propios', '—Sin valor'])
    expect(rows).toContainEqual([
      'Usuarios',
      'Eliminar',
      'Denegar',
      'Cualquier ámbito',
      '—Sin valor',
    ])
    // `RN-PERM-28`: la etiqueta del recurso llega del servidor.
    expect(wrapper.find('tbody').text()).toContain('Auditoría')
  })

  it('la categoría especial se marca con la insignia y, si el rol no tiene el atributo, como inerte (RN-PERM-35)', async () => {
    const wrapper = await mountView()
    const salud = wrapper.findAll('tbody tr').find((tr) => tr.text().includes('Salud'))!

    expect(salud.text()).toContain('Categoría especial')
    expect(salud.text()).toContain('Inerte: este rol no tiene acceso a datos especiales')

    getRole.mockResolvedValue(role({ special_data_access: true }))
    const second = await mountView()
    const row = second.findAll('tbody tr').find((tr) => tr.text().includes('Salud'))!

    expect(row.text()).toContain('Categoría especial')
    expect(row.text()).not.toContain('Inerte')
  })

  it('el estado vacío dice que el rol no concede ni deniega nada', async () => {
    getRole.mockResolvedValue(role({ permissions: [] }))
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('Este rol no concede ni deniega ningún permiso')
  })

  it('un recurso sin resource_label muestra su código en crudo (RN-PERM-28)', async () => {
    getRole.mockResolvedValue(
      role({
        permissions: [
          {
            code: 'nuevo.leer',
            resource: 'nuevo',
            action: 'leer',
            effect: 'allow',
            scope: 'todos',
          },
        ],
      }),
    )
    const wrapper = await mountView()

    expect(wrapper.find('tbody th').text()).toBe('nuevo')
  })
})

describe('CA-PERM-102 (RN-PERM-26, RN-PERM-40, RN-CORE-62): acciones por permiso', () => {
  it('rol del sistema con rol.leer/actualizar/crear/eliminar y sin permiso.leer: sin «Eliminar», con «Clonar», sin pedir el catálogo ni pintar su columna', async () => {
    session.__setPermissions(ALL)
    getRole.mockResolvedValue(role({ is_system: true, public_id: 'R1', users_count: 4 }))
    const wrapper = await mountView()

    expect(deleteButton(wrapper)).toBeUndefined()
    expect(wrapper.findAll('a').map((a) => a.text())).toContain('Clonar')
    expect(wrapper.findAll('a').map((a) => a.text())).toContain('Editar datos')
    expect(wrapper.findAll('a').map((a) => a.text())).not.toContain('Editar concesiones')
    expect(listPermissions).not.toHaveBeenCalled()
    expect(wrapper.findAll('thead th').map((th) => th.text())).not.toContain('Categoría especial')
  })

  it('con solo rol.leer, ninguna acción de escritura está en el documento', async () => {
    session.__setPermissions(['rol.leer'])
    const wrapper = await mountView()

    expect(wrapper.findAll('a').map((a) => a.text())).toEqual([])
    expect(wrapper.findAll('button').filter((b) => b.text() === 'Eliminar')).toHaveLength(0)
    expect(wrapper.find('#role-actions-title').exists()).toBe(false)
    expect(wrapper.text()).not.toMatch(/Clonar|Editar datos|Editar concesiones|Ver usuarios/)
  })

  it('«Ver usuarios con este rol» solo con usuario.leer y enlaza con el filtro role=<public_id>', async () => {
    getRole.mockResolvedValue(role({ users_count: 2 }))
    const wrapper = await mountView()
    const link = wrapper.findAll('a').find((a) => a.text() === 'Ver usuarios con este rol')!

    expect(link.attributes('href')).toBe('/administracion/usuarios?role=R1')

    session.__setPermissions(['rol.leer'])
    const second = await mountView()

    expect(second.text()).not.toContain('Ver usuarios con este rol')
  })

  it('no hay decisiones por código de rol: un rol personalizado con el código de un rol del sistema se trata por is_system (RN-PERM-46)', async () => {
    getRole.mockResolvedValue(role({ code: 'administrador_centro', is_system: false }))
    const wrapper = await mountView()

    expect(wrapper.findAll('a').map((a) => a.text())).toContain('Editar concesiones')
  })
})

describe('CA-PERM-117 (RN-PERM-40, OPEN-PERM-10 = B): rol del sistema', () => {
  it('no existe «Editar concesiones» y aparece el texto que remite a «Clonar»', async () => {
    getRole.mockResolvedValue(role({ is_system: true }))
    const wrapper = await mountView()

    expect(wrapper.findAll('a').map((a) => a.text())).not.toContain('Editar concesiones')
    expect(wrapper.text()).toContain(
      'Los roles del sistema no se modifican desde aquí. Clónalo para crear una versión propia.',
    )
  })

  it('un rol personalizado con rol.actualizar ofrece «Editar concesiones» hacia core-role-permissions', async () => {
    const wrapper = await mountView()
    const link = wrapper.findAll('a').find((a) => a.text() === 'Editar concesiones')!

    expect(link.attributes('href')).toBe('/administracion/roles/R1/permisos')
  })
})

describe('CA-PERM-118 (RN-PERM-39, RN-PERM-17): baja de rol', () => {
  it('con users_count = 3, «Eliminar» está deshabilitado con el texto traducido con «3» y enlace a los usuarios con role=<public_id>', async () => {
    getRole.mockResolvedValue(role({ users_count: 3 }))
    const wrapper = await mountView()
    const button = deleteButton(wrapper)!

    expect(button.attributes('disabled')).toBeDefined()
    expect(button.attributes('aria-describedby')).toBe('role-delete-note')
    expect(wrapper.get('#role-delete-note').text()).toBe(
      'No se puede eliminar un rol con titulares (3). Retíraselo antes a cada usuario',
    )
    expect(
      wrapper
        .findAll('a')
        .find((a) => a.text() === 'Ver usuarios con este rol')!
        .attributes('href'),
    ).toBe('/administracion/usuarios?role=R1')

    await button.trigger('click')

    expect(deleteRole).not.toHaveBeenCalled()
  })

  it('con users_count = 0, no sale ninguna petición hasta confirmar; con 204 se navega al listado con mensaje role="status"', async () => {
    const wrapper = await mountView()

    await deleteButton(wrapper)!.trigger('click')
    await flushPromises()

    expect(document.body.querySelector('[role="alertdialog"]')!.textContent).toContain(
      'Eliminar el rol «Coordinación pastoral»',
    )
    expect(deleteRole).not.toHaveBeenCalled()

    await click(dialogButton('Eliminar el rol «Coordinación pastoral»'))

    expect(deleteRole).toHaveBeenCalledExactlyOnceWith('R1')
    expect(router.currentRoute.value.name).toBe('core-roles')
    expect(takeFlash()).toEqual({
      key: 'core.roles.flash.deleted',
      params: { name: 'Coordinación pastoral' },
    })
  })

  it('cancelar la confirmación no envía nada', async () => {
    const wrapper = await mountView()

    await deleteButton(wrapper)!.trigger('click')
    await flushPromises()
    await click(dialogButton('Cancelar'))

    expect(deleteRole).not.toHaveBeenCalled()
    expect(router.currentRoute.value.name).toBe('core-role-detail')
  })

  it('un 409 role_has_assignments con errors.role[0].params.users_count = 2 muestra el detail con role="alert" y el texto con «2»', async () => {
    deleteRole.mockRejectedValue(
      new ApiError('HTTP 409', 409, {
        type: 'urn:pge:error:conflict',
        detail: 'El rol tiene asignaciones vivas.',
        errors: {
          role: [
            {
              code: 'core.validation.role_has_assignments',
              message: 'El rol tiene asignaciones vivas.',
              params: { users_count: 2 },
            },
          ],
        },
      }),
    )
    const wrapper = await mountView()

    await deleteButton(wrapper)!.trigger('click')
    await flushPromises()
    await click(dialogButton('Eliminar el rol «Coordinación pastoral»'))

    const alert = wrapper.get('[role="alert"]')

    expect(alert.text()).toContain('El rol tiene asignaciones vivas.')
    expect(alert.text()).toContain('El rol tiene titulares (2). Retíraselo antes a cada usuario.')
    expect(router.currentRoute.value.name).toBe('core-role-detail')
  })

  it('con users_count ausente (API anterior) el botón se habilita y decide el servidor', async () => {
    getRole.mockResolvedValue(role({ users_count: undefined }))
    const wrapper = await mountView()

    expect(deleteButton(wrapper)!.attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-slot="role-users-count"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Ver usuarios con este rol')
  })

  it('un 409 role_is_system muestra el detail del servidor', async () => {
    deleteRole.mockRejectedValue(
      new ApiError('HTTP 409', 409, { detail: 'No se puede eliminar un rol del sistema.' }),
    )
    const wrapper = await mountView()

    await deleteButton(wrapper)!.trigger('click')
    await flushPromises()
    await click(dialogButton('Eliminar el rol «Coordinación pastoral»'))

    expect(wrapper.get('[role="alert"]').text()).toContain(
      'No se puede eliminar un rol del sistema.',
    )
  })
})

describe('estados de la ficha (§12.6, CA-CORE-263)', () => {
  it('un 404 pinta «no encontrado»', async () => {
    getRole.mockRejectedValue(new ApiError('HTTP 404', 404, {}))
    const wrapper = await mountView()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Página no encontrada')
  })

  it('un fallo del catálogo no tumba la ficha: solo falta la columna de categoría especial', async () => {
    listPermissions.mockRejectedValue(new ApiError('HTTP 500', 500, {}))
    const wrapper = await mountView()

    expect(wrapper.find('h1').text()).toBe('Coordinación pastoral')
    expect(wrapper.findAll('thead th').map((th) => th.text())).not.toContain('Categoría especial')
  })
})

describe('RN-PERM-28 / RN-CORE-63: recarga al cambiar de idioma', () => {
  it('al pasar a inglés se vuelve a pedir la ficha y se pintan las etiquetas que devuelve el servidor', async () => {
    const wrapper = await mountView()

    getRole.mockClear()
    getRole.mockResolvedValue(
      role({
        permissions: [grant('auditoria.leer', 'allow', 'propios', 'Audit')],
      }),
    )

    setLocale('en')
    await flushPromises()

    expect(getRole).toHaveBeenCalledTimes(1)
    expect(wrapper.find('tbody').text()).toContain('Audit')
    expect(wrapper.find('tbody').text()).toContain('Read')
    expect(wrapper.find('tbody').text()).toContain('Allow')
    expect(wrapper.find('tbody').text()).toContain('Own')
  })
})

describe('RN-PERM-43 / RN-CORE-50: modo local y privacidad', () => {
  it('las únicas escrituras en localStorage son claves plataforma.table.core.role_grants, sin datos de fila', async () => {
    const setItem = vi.spyOn(Storage.prototype, 'setItem')
    const wrapper = await mountView()

    await wrapper.get('thead button').trigger('click')
    await flushPromises()

    for (const call of setItem.mock.calls) {
      expect(call[0]).toBe('plataforma.table.core.role_grants')
      expect(call[1]).not.toContain('auditoria')
    }

    setItem.mockRestore()
  })
})
