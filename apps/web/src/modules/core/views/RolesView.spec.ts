/**
 * `docs/modulos/REQ-CORE/funcional.md §14.8`, `§14.18`: roles en solo lectura
 * (1.9d) — `CA-CORE-240` (`RN-CORE-75`, sin controles de escritura aunque el
 * usuario tenga los permisos), `CA-CORE-241` (`RN-CORE-63`, recarga al cambiar
 * de idioma) y `OPEN-CORE-36` = A (ni detalle ni `GET /roles/{id}`).
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
    routes: [{ path: '/administracion/roles', name: 'core-roles', component: RolesView }],
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

describe('CA-CORE-240 (RN-CORE-75): roles de solo lectura', () => {
  it('con rol.leer y todos los permisos de escritura, el listado muestra las cinco columnas y ningún control de crear, clonar, editar ni borrar', async () => {
    const wrapper = await mountView()

    expect(wrapper.find('caption').text()).toBe('Roles del centro')
    expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual([
      'Nombre',
      'Tipo',
      'MFA obligatorio',
      'Acceso a datos especiales',
      'Usuarios',
    ])

    const rows = wrapper
      .findAll('tbody tr')
      .map((tr) => tr.findAll('th, td').map((cell) => cell.text()))

    expect(rows).toEqual([
      ['Administrador del centro', 'Del sistema', 'Sí', 'Sí', '1'],
      ['Docente', 'Del sistema', 'No', 'No', '3'],
      ['Coordinación pastoral', 'Personalizado', 'No', 'No', '12'],
    ])

    expect(wrapper.find('tbody th').attributes('scope')).toBe('row')
    expect(document.body.querySelectorAll('tbody button, tbody a, main button, form')).toHaveLength(
      0,
    )
    expect(document.body.textContent).not.toMatch(/Crear|Clonar|Editar|Borrar|Eliminar|Nuevo/)
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

  it('nunca llama a GET /roles/{id}: no hay detalle de rol (OPEN-CORE-36 = A)', async () => {
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
