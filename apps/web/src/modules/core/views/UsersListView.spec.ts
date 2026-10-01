/**
 * `docs/modulos/REQ-CORE/funcional.md §14.4.1`, `§14.18`: listado de
 * usuarios (1.9b) — `CA-CORE-212` (sin peticiones a ciegas), `-213`
 * (columnas sin datos identificativos), `-214` (orden por correo), `-215`
 * (estado en la URL, sin `q`), `-216` (dados de baja por permiso), `-222`
 * (exportación con los filtros estructurados), `-241` (recarga al cambiar de
 * idioma, parte de usuarios) y `-262` (nada de filas en el almacenamiento).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import { SEARCH_DEBOUNCE_MS } from '@/data-table'

const listUsers = vi.fn()
const listRoles = vi.fn()
const exportUsers = vi.fn()
const getDataExport = vi.fn()

vi.mock('../api', () => ({
  listUsers: (...args: unknown[]) => listUsers(...args),
  listRoles: (...args: unknown[]) => listRoles(...args),
  exportUsers: (...args: unknown[]) => exportUsers(...args),
  getDataExport: (...args: unknown[]) => getDataExport(...args),
}))

vi.mock('@/session/useSession', async () => {
  const { shallowRef } = await import('vue')
  const user = shallowRef<{ public_id: string; permissions: string[] } | null>(null)

  return {
    useSession: () => ({ user }),
    __setPermissions: (permissions: string[], publicId = 'me-1') => {
      user.value = { public_id: publicId, permissions }
    },
  }
})

vi.mock('@/tenant/useTenantBranding', async () => {
  const { shallowRef } = await import('vue')

  return {
    useTenantBranding: () => ({
      branding: shallowRef({ active_locales: ['es-ES', 'en'], default_locale: 'es-ES' }),
    }),
  }
})

const session = (await import('@/session/useSession')) as unknown as {
  __setPermissions: (permissions: string[], publicId?: string) => void
}
const { default: UsersListView } = await import('./UsersListView.vue')

function user(id: string, overrides: Record<string, unknown> = {}) {
  return {
    public_id: id,
    email: `${id.toLowerCase()}@example.com`,
    status: 'activo',
    person: {
      public_id: `P-${id}`,
      given_name: 'Ana',
      family_name_1: 'López',
      family_name_2: 'Ruiz',
      contact_email: 'contacto-secreto@example.com',
      contact_phone: '699111222',
      document_type: 'DNI',
      document_number: 'ZZ-DOC-98765',
      birth_date: '1990-05-05',
      locale: 'es-ES',
    },
    roles: [{ public_id: 'R1', code: 'docente', name: 'Docente' }],
    email_verified_at: null,
    created_at: '2026-09-01T10:00:00Z',
    updated_at: '2026-09-01T10:00:00Z',
    deleted_at: null,
    ...overrides,
  }
}

function page(rows: unknown[]) {
  return { data: rows, meta: { current_page: 1, per_page: 25, total: rows.length, last_page: 1 } }
}

const wrappers: VueWrapper[] = []

async function mountView(url = '/administracion/usuarios'): Promise<{
  wrapper: VueWrapper
  router: Router
}> {
  const stub = { template: '<div/>' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/administracion/usuarios', name: 'core-users', component: UsersListView },
      { path: '/administracion/usuarios/nuevo', name: 'core-user-new', component: stub },
      { path: '/administracion/usuarios/:publicId', name: 'core-user-detail', component: stub },
    ],
  })

  await router.push(url)
  await router.isReady()

  const wrapper = mount(UsersListView, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return { wrapper, router }
}

async function click(el: Element): Promise<void> {
  el.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await flushPromises()
}

function button(label: string): HTMLButtonElement | undefined {
  return [...document.body.querySelectorAll('button')].find((candidate) =>
    (candidate.getAttribute('aria-label') ?? candidate.textContent ?? '').trim().startsWith(label),
  ) as HTMLButtonElement | undefined
}

function lastListQuery(): Record<string, unknown> {
  return listUsers.mock.calls.at(-1)![0] as Record<string, unknown>
}

beforeEach(() => {
  setLocale('es')
  listUsers
    .mockReset()
    .mockResolvedValue(page([user('U1'), user('U2', { email: 'u2@example.com' })]))
  listRoles.mockReset().mockResolvedValue({
    data: [{ public_id: 'R1', code: 'docente', name: 'Docente', is_system: true }],
    meta: { current_page: 1, per_page: 100, total: 1, last_page: 1 },
  })
  exportUsers.mockReset().mockResolvedValue({ public_id: 'EXP1' })
  getDataExport.mockReset()
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
  session.__setPermissions(['usuario.leer'])
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
  vi.useRealTimers()
})

describe('CA-CORE-213 (RN-CORE-67): columnas del listado', () => {
  it('caption traducido, «Nombre» como th scope=row con enlace a la ficha, sin documento ni fecha de nacimiento', async () => {
    const { wrapper } = await mountView()

    expect(wrapper.find('table caption').text()).toBe('Usuarios del centro')

    const rowHeaders = wrapper.findAll('tbody th[scope="row"]')

    expect(rowHeaders).toHaveLength(2)
    expect(rowHeaders[0]!.find('a').attributes('href')).toBe('/administracion/usuarios/U1')
    expect(rowHeaders[0]!.text()).toBe('López Ruiz, Ana')

    const text = document.body.textContent ?? ''

    for (const secret of [
      'ZZ-DOC-98765',
      '1990-05-05',
      '05/05/1990',
      'contacto-secreto',
      '699111222',
    ]) {
      expect(text).not.toContain(secret)
    }

    expect(wrapper.text()).toContain('Docente')
  })
})

describe('CA-CORE-212 (RN-CORE-62): sin peticiones a ciegas', () => {
  it('sin rol.leer no se pide GET /roles y no existe el filtro de rol', async () => {
    await mountView()

    expect(listRoles).not.toHaveBeenCalled()
    expect(button('Rol')).toBeUndefined()
  })

  it('con rol.leer se pide una vez y el filtro ofrece los roles recibidos', async () => {
    session.__setPermissions(['usuario.leer', 'rol.leer'])
    await mountView()

    expect(listRoles).toHaveBeenCalledTimes(1)
    expect(listRoles).toHaveBeenCalledWith({ per_page: 100 })

    await click(button('Rol')!)

    const items = [...document.body.querySelectorAll('[role="menuitemcheckbox"]')].map((el) =>
      el.textContent?.trim(),
    )

    expect(items).toEqual(['Docente'])
  })
})

describe('CA-CORE-214 (RN-CORE-67, RN-CORE-39): ordenar por correo', () => {
  it('dos pulsaciones envían sort=email y después sort=-email', async () => {
    await mountView()

    await click(button('Correo de acceso, ordenar de forma ascendente')!)
    expect(lastListQuery().sort).toBe('email')

    await click(button('Correo de acceso, ordenar de forma descendente')!)
    expect(lastListQuery().sort).toBe('-email')
  })
})

describe('CA-CORE-215 (RN-CORE-54): estado en la URL, nunca q', () => {
  it('restaura estado, página y orden desde la URL y la URL no lleva q al buscar', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const { wrapper, router } = await mountView(
      '/administracion/usuarios?status=activo&page=2&sort=-created_at',
    )

    expect(lastListQuery()).toMatchObject({
      status: ['activo'],
      page: 2,
      sort: '-created_at',
    })

    await wrapper.get('input[type="search"]').setValue('ana')
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 50)
    await flushPromises()

    expect(lastListQuery().q).toBe('ana')
    expect(router.currentRoute.value.fullPath).not.toContain('q=')
    expect(router.currentRoute.value.query).toMatchObject({ status: 'activo' })
  })
})

describe('CA-CORE-216 (RN-CORE-68): dados de baja solo con usuario.eliminar', () => {
  it('sin usuario.eliminar no existe el filtro; con él, la casilla envía include_deleted=true', async () => {
    await mountView()

    expect(document.body.textContent).not.toContain('Incluir dados de baja')

    wrappers.pop()!.unmount()
    session.__setPermissions(['usuario.leer', 'usuario.eliminar'])
    await mountView()

    expect(lastListQuery().include_deleted).toBeUndefined()

    const checkbox = document.body.querySelector<HTMLInputElement>(
      '[data-slot="data-table-two-state-filter"] input',
    )!

    checkbox.checked = true
    checkbox.dispatchEvent(new Event('change', { bubbles: true }))
    await flushPromises()

    expect(lastListQuery().include_deleted).toBe(true)
  })

  it('un usuario dado de baja muestra el estado «Dado de baja»', async () => {
    session.__setPermissions(['usuario.leer', 'usuario.eliminar'])
    listUsers.mockResolvedValue(page([user('U9', { deleted_at: '2026-09-20T10:00:00Z' })]))
    const { wrapper } = await mountView()

    expect(wrapper.text()).toContain('Dado de baja')
  })
})

describe('CA-CORE-222 (RN-CORE-69, RN-CORE-51): exportación con los filtros estructurados', () => {
  it('POST /users/exports recibe solo los filtros, como arrays, sin q, sort, page ni per_page', async () => {
    session.__setPermissions(['usuario.leer', 'usuario.exportar', 'rol.leer'])
    await mountView('/administracion/usuarios?status=activo,inactivo&role=R1&sort=email&page=2')

    await click(button('Exportar')!)

    expect(exportUsers).toHaveBeenCalledTimes(1)
    expect(exportUsers.mock.calls[0]![0]).toEqual({ status: 'activo,inactivo', role: 'R1' })
  })

  it('sin usuario.exportar no hay control de exportación', async () => {
    await mountView()

    expect(button('Exportar')).toBeUndefined()
  })
})

describe('acciones de la barra por permiso (RN-CORE-61)', () => {
  it('«Nuevo usuario» solo con usuario.crear; «Importar» no se ofrece mientras core-user-imports no exista', async () => {
    const { wrapper } = await mountView()

    expect(wrapper.text()).not.toContain('Nuevo usuario')

    wrappers.pop()!.unmount()
    session.__setPermissions(['usuario.leer', 'usuario.crear', 'usuario.importar'])
    const second = await mountView()

    expect(second.wrapper.find('a[href="/administracion/usuarios/nuevo"]').exists()).toBe(true)
    expect(second.wrapper.text()).not.toContain('Importar')
  })
})

describe('CA-CORE-241 (RN-CORE-63): recarga al cambiar de idioma', () => {
  it('al cambiar el idioma se vuelve a pedir la página actual (una petición) y los roles', async () => {
    session.__setPermissions(['usuario.leer', 'rol.leer'])
    await mountView()

    const before = listUsers.mock.calls.length
    const rolesBefore = listRoles.mock.calls.length

    setLocale('en')
    await flushPromises()

    expect(listUsers.mock.calls.length).toBe(before + 1)
    expect(listRoles.mock.calls.length).toBe(rolesBefore + 1)
  })
})

describe('CA-CORE-262 (RN-CORE-50): almacenamiento del navegador', () => {
  it('las únicas escrituras son claves plataforma.table.<tableId>, sin datos de fila', async () => {
    const setItem = vi.spyOn(Storage.prototype, 'setItem')

    const { wrapper } = await mountView()
    await wrapper.get('input[type="search"]').setValue('ana')
    await flushPromises()

    const keys = setItem.mock.calls.map((call) => String(call[0]))

    for (const key of keys) {
      expect(key).toMatch(/^plataforma\.(table\.core\.users|locale|brand)/)
    }

    for (const call of setItem.mock.calls) {
      expect(String(call[1])).not.toContain('example.com')
      expect(String(call[1])).not.toContain('ana')
    }

    setItem.mockRestore()
  })
})
