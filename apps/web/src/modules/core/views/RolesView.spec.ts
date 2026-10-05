/**
 * `docs/modulos/REQ-CORE/funcional.md §14.8`, `§14.18` y
 * `docs/modulos/REQ-PERM/funcional.md §20.4`: listado de roles.
 * `CA-CORE-241` (`RN-CORE-63`, recarga al cambiar de idioma) sigue vigente.
 * **`CA-CORE-240` (`RN-CORE-75`, «solo lectura») queda retirado en este mismo
 * *commit* y sustituido por `CA-PERM-101`** (1.5b): el listado tiene «Nuevo rol»
 * con `rol.crear` y el nombre de cada rol es un enlace a su ficha. Ya no hay
 * `OPEN-CORE-36` = A: la ficha existe, pero el listado nunca llama a
 * `GET /roles/{id}`.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'

const listRoles = vi.fn()
const getRole = vi.fn()

vi.mock('../api', () => ({
  listRoles: (...args: unknown[]) => listRoles(...args),
  getRole: (...args: unknown[]) => getRole(...args),
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

const session = (await import('@/session/useSession')) as unknown as {
  __setPermissions: (permissions: string[]) => void
}
const { default: RolesView } = await import('./RolesView.vue')

function role(id: string, name: string, overrides: Record<string, unknown> = {}) {
  return {
    public_id: id,
    code: id.toLowerCase(),
    name,
    is_system: true,
    mfa_required: false,
    special_data_access: false,
    users_count: 3,
    ...overrides,
  }
}

function page(rows: unknown[]) {
  return { data: rows, meta: { current_page: 1, per_page: 25, total: rows.length, last_page: 1 } }
}

const wrappers: VueWrapper[] = []

async function mountView(): Promise<VueWrapper> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/administracion/roles', name: 'core-roles', component: RolesView },
      {
        path: '/administracion/roles/nuevo',
        name: 'core-role-new',
        component: { template: '<div/>' },
      },
      {
        path: '/administracion/roles/:publicId',
        name: 'core-role-detail',
        component: { template: '<div/>' },
      },
    ],
  })

  await router.push('/administracion/roles')
  await router.isReady()

  const wrapper = mount(RolesView, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  setLocale('es')
  listRoles.mockReset().mockResolvedValue(
    page([
      role('R1', 'Administrador del centro', {
        mfa_required: true,
        special_data_access: true,
        users_count: 1,
      }),
      role('R2', 'Docente'),
      role('R3', 'Coordinación pastoral', { is_system: false, users_count: 12 }),
    ]),
  )
  getRole.mockReset()
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
  session.__setPermissions(['rol.leer', 'rol.crear', 'rol.actualizar', 'rol.eliminar'])
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('CA-PERM-101 (RN-PERM-26, sustituye a CA-CORE-240): listado de roles con alta y enlaces', () => {
  it('con rol.leer y sin rol.crear no hay «Nuevo rol» ni ningún otro control de escritura, y cada nombre es un enlace a su ficha', async () => {
    session.__setPermissions(['rol.leer'])
    const wrapper = await mountView()

    expect(wrapper.find('caption').text()).toBe('Roles del centro')
    expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual([
      'Nombre',
      'Tipo',
      'MFA obligatorio',
      'Acceso a datos especiales',
      'Usuarios',
    ])

    const links = wrapper.findAll('tbody th a')

    expect(links.map((link) => link.text())).toEqual([
      'Administrador del centro',
      'Docente',
      'Coordinación pastoral',
    ])
    expect(links.map((link) => link.attributes('href'))).toEqual([
      '/administracion/roles/R1',
      '/administracion/roles/R2',
      '/administracion/roles/R3',
    ])
    expect(document.body.textContent).not.toContain('Nuevo rol')
    expect(document.body.querySelectorAll('tbody button, main button, form')).toHaveLength(0)
    expect(document.body.textContent).not.toMatch(/Clonar|Editar|Borrar|Eliminar/)
  })

  it('con rol.crear existe «Nuevo rol» y lleva a core-role-new', async () => {
    const wrapper = await mountView()
    const action = wrapper.findAll('a').find((link) => link.text() === 'Nuevo rol')!

    expect(action).toBeDefined()
    expect(action.attributes('href')).toBe('/administracion/roles/nuevo')
  })

  it('el listado muestra las cinco columnas con sus valores', async () => {
    const wrapper = await mountView()
    const rows = wrapper
      .findAll('tbody tr')
      .map((tr) => tr.findAll('th, td').map((cell) => cell.text()))

    expect(rows).toEqual([
      ['Administrador del centro', 'Del sistema', 'Sí', 'Sí', '1'],
      ['Docente', 'Del sistema', 'No', 'No', '3'],
      ['Coordinación pastoral', 'Personalizado', 'No', 'No', '12'],
    ])
    expect(wrapper.find('tbody th').attributes('scope')).toBe('row')
  })

  it('el número de usuarios va alineado a la derecha', async () => {
    const wrapper = await mountView()

    expect(wrapper.find('tbody tr td:last-child').classes().join(' ')).toMatch(
      /text-right|text-end/,
    )
  })

  it('sin filtros, sin búsqueda y sin columnas ordenables; pide solo page y per_page', async () => {
    const wrapper = await mountView()

    expect(wrapper.find('input[type="search"]').exists()).toBe(false)
    expect(wrapper.find('thead button').exists()).toBe(false)
    expect(listRoles).toHaveBeenCalledWith({ page: 1, per_page: 25 })
  })

  it('el listado nunca llama a GET /roles/{id}: el detalle lo pide la ficha, no el listado', async () => {
    await mountView()

    expect(getRole).not.toHaveBeenCalled()
  })
})

describe('CA-CORE-241 (RN-CORE-63): texto traducido por el servidor', () => {
  it('al cambiar el idioma a en se vuelve a pedir la página actual (una sola petición) y se muestran los nombres que devuelve el servidor', async () => {
    const wrapper = await mountView()

    listRoles.mockClear()
    listRoles.mockResolvedValue(page([role('R2', 'Teacher')]))

    setLocale('en')
    await flushPromises()

    expect(listRoles).toHaveBeenCalledTimes(1)
    expect(wrapper.find('tbody').text()).toContain('Teacher')
    expect(wrapper.find('caption').text()).toBe('School roles')
  })
})
