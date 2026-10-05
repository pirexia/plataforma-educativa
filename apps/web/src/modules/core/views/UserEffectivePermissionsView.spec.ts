/**
 * `docs/modulos/REQ-PERM/funcional.md §20.10`, `§20.17.6`: permisos efectivos de
 * un usuario — `CA-PERM-119` (una sola petición sin paginación, cabecera),
 * `CA-PERM-120` (procedencia y motivos de inercia, `RN-PERM-42`), `CA-PERM-121`
 * (filtro por defecto, «Incluir permisos sin ninguna concesión» sin nueva
 * petición, búsqueda sin tildes), `CA-PERM-122` (sin exportación, sin estado en
 * la URL ni en el navegador, recarga al cambiar de idioma, `404`).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { ApiError } from '@/api/client'
import { i18n, setLocale } from '@/i18n'

const getUserEffectivePermissions = vi.fn()

vi.mock('../api', () => ({
  getUserEffectivePermissions: (...args: unknown[]) => getUserEffectivePermissions(...args),
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
const { default: UserEffectivePermissionsView } = await import('./UserEffectivePermissionsView.vue')

function row(
  code: string,
  label: string,
  extra: Record<string, unknown> = {},
  moduleCode = 'core',
) {
  const [resource = code, action = 'leer'] = code.split('.')

  return {
    code,
    resource,
    action,
    module_code: moduleCode,
    is_special_category: false,
    resource_label: label,
    decision: 'denegado',
    scopes: [],
    unrestricted: false,
    sources: [],
    ...extra,
  }
}

const COORD = { public_id: 'RC', code: 'coordinacion_auditoria', name: 'Coordinación de auditoría' }
const RESTRICT = { public_id: 'RR', code: 'cuenta_restringida', name: 'Cuenta restringida' }
const ADMIN = { public_id: 'RA', code: 'administrador_centro', name: 'Administrador de Centro' }

/** El ejemplo de `api.md §7.1` más relleno hasta 40 filas, 3 de ellas con fuentes. */
function response(fillers = 37) {
  return {
    data: [
      row('auditoria.leer', 'Auditoría', {
        decision: 'permitido',
        scopes: ['propios'],
        sources: [
          { role: COORD, effect: 'allow', scope: 'propios', inert: false, inert_reason: null },
        ],
      }),
      row(
        'salud.leer',
        'Salud',
        {
          is_special_category: true,
          sources: [
            {
              role: { public_id: 'RS', code: 'aux', name: 'Auxiliar de enfermería' },
              effect: 'allow',
              scope: 'todos',
              inert: true,
              inert_reason: 'inerte_datos_especiales',
            },
          ],
        },
        'salud',
      ),
      row('configuracion.actualizar', 'Configuración', {
        sources: [
          { role: ADMIN, effect: 'allow', scope: 'todos', inert: false, inert_reason: null },
          { role: RESTRICT, effect: 'deny', scope: 'todos', inert: false, inert_reason: null },
        ],
      }),
      ...Array.from({ length: fillers }, (_, index) =>
        row(`recurso${String(index).padStart(2, '0')}.leer`, `Recurso ${index}`),
      ),
    ],
    meta: {
      subject: { public_id: 'U1', display_name: 'Ana Pérez' },
      roles: [COORD, ADMIN],
      computed_at: '2026-09-04T09:00:00Z',
    },
  }
}

const wrappers: VueWrapper[] = []
let router: Router

async function mountView(): Promise<VueWrapper> {
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/administracion/usuarios/:publicId/permisos',
        name: 'core-user-effective-permissions',
        component: UserEffectivePermissionsView,
      },
      {
        path: '/administracion/roles/:publicId',
        name: 'core-role-detail',
        component: { template: '<div/>' },
      },
    ],
  })

  await router.push('/administracion/usuarios/U1/permisos')
  await router.isReady()

  const wrapper = mount(UserEffectivePermissionsView, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return wrapper
}

function announcer(wrapper: VueWrapper): string {
  return wrapper.get('[data-slot="data-table-announcer"]').text()
}

function rowFor(wrapper: VueWrapper, text: string) {
  const found = wrapper.findAll('tbody tr').find((tr) => tr.text().includes(text))

  if (!found) {
    throw new Error(`No hay fila con «${text}»`)
  }

  return found
}

async function type(wrapper: VueWrapper, value: string): Promise<void> {
  vi.useFakeTimers()
  await wrapper.get('input[type="search"]').setValue(value)
  await vi.advanceTimersByTimeAsync(400)
  vi.useRealTimers()
  await flushPromises()
}

beforeEach(() => {
  setLocale('es')
  getUserEffectivePermissions.mockReset().mockResolvedValue(response())
  window.localStorage.clear()
  window.sessionStorage.clear()
  session.__setPermissions(['permiso_efectivo.leer', 'rol.leer'])
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
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  vi.useRealTimers()
  document.body.innerHTML = ''
})

describe('CA-PERM-119 (RN-PERM-41, RPERM-009): una petición y cabecera', () => {
  it('sale exactamente una petición GET /users/{id}/effective-permissions, sin parámetros de paginación', async () => {
    await mountView()

    expect(getUserEffectivePermissions).toHaveBeenCalledExactlyOnceWith('U1')
  })

  it('la cabecera muestra el nombre, la frase explicativa, los roles enlazados a su ficha con rol.leer y la fecha de cálculo formateada', async () => {
    const wrapper = await mountView()

    expect(wrapper.get('h1').text()).toBe('Permisos efectivos de Ana Pérez')
    expect(wrapper.text()).toContain(
      'Lo que esta persona puede hacer ahora mismo, calculado con las mismas reglas que aplica la plataforma en cada petición.',
    )

    const links = wrapper.findAll('header ~ div a')

    expect(links.map((a) => a.text())).toEqual([
      'Coordinación de auditoría',
      'Administrador de Centro',
    ])
    expect(links[0]!.attributes('href')).toBe('/administracion/roles/RC')
    expect(wrapper.get('[data-slot="effective-computed-at"]').text()).toMatch(
      /^Calculado el .*2026/,
    )
    expect(wrapper.get('[data-slot="effective-computed-at"]').text()).not.toContain('T09:00:00Z')
  })

  it('sin rol.leer los roles son texto, sin enlace', async () => {
    session.__setPermissions(['permiso_efectivo.leer'])
    const wrapper = await mountView()

    expect(wrapper.findAll('header ~ div a')).toHaveLength(0)
    expect(wrapper.text()).toContain('Coordinación de auditoría')
  })

  it('un usuario sin roles dice «Sin roles asignados»', async () => {
    const empty = response()

    empty.meta.roles = []
    getUserEffectivePermissions.mockResolvedValue(empty)
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('Sin roles asignados.')
  })

  it('«Recalcular» vuelve a pedir (sin caché) y repinta', async () => {
    const wrapper = await mountView()

    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Recalcular')!
      .trigger('click')
    await flushPromises()

    expect(getUserEffectivePermissions).toHaveBeenCalledTimes(2)
  })
})

describe('CA-PERM-120 (RN-PERM-42, RPERM-007): la procedencia explica la decisión', () => {
  it('auditoria.leer: «Permitido», ámbito «Propios» y su fuente', async () => {
    const wrapper = await mountView()
    const text = rowFor(wrapper, 'Auditoría').text()

    expect(text).toContain('Permitido')
    expect(text).toContain('Propios')
    expect(text).toContain('Coordinación de auditoría')
    expect(text).toContain('Permite')
  })

  it('salud.leer: «Denegado», «Concedido, pero sin efecto» con el motivo traducido, y la fuente inerte', async () => {
    const wrapper = await mountView()
    const text = rowFor(wrapper, 'Salud').text()

    expect(text).toContain('Denegado')
    expect(text).toContain(
      'Concedido, pero sin efecto: el rol no tiene acceso a datos de categoría especial',
    )
    expect(text).toContain('No surte efecto: el rol no tiene acceso a datos de categoría especial')
    expect(text).toContain('Categoría especial')
  })

  it('configuracion.actualizar: «Denegado por «Cuenta restringida»…» y las dos fuentes (Permite y Deniega)', async () => {
    const wrapper = await mountView()
    const text = rowFor(wrapper, 'Configuración').text()

    expect(text).toContain(
      'Denegado por «Cuenta restringida»: una denegación anula cualquier concesión',
    )
    expect(text).toContain('Administrador de Centro')
    expect(text).toContain('Permite')
    expect(text).toContain('Deniega')
    expect(text).toContain('Cualquier ámbito')
  })

  it('un inert_reason desconocido se pinta en crudo, sin error', async () => {
    const data = response(0)
    const sources = data.data[1]!.sources as { inert_reason: string }[]

    sources[0]!.inert_reason = 'inerte_futuro'
    getUserEffectivePermissions.mockResolvedValue(data)
    const wrapper = await mountView()

    expect(rowFor(wrapper, 'Salud').text()).toContain('No surte efecto: inerte_futuro')
  })

  it('una concesión inerte nunca se presenta igual que una ausente', async () => {
    const wrapper = await mountView()

    expect(rowFor(wrapper, 'Salud').text()).toContain('Concedido, pero sin efecto')
    expect(wrapper.text()).not.toContain('Recurso 0 Concedido')
  })

  it('el resultado lleva icono decorativo y texto, nunca solo color', async () => {
    const wrapper = await mountView()
    const cell = rowFor(wrapper, 'Auditoría').findAll('td')[1]!

    expect(cell.find('svg').attributes('aria-hidden')).toBe('true')
    expect(cell.text()).toContain('Permitido')
  })

  it('en la procedencia, cada rol enlaza a su ficha con rol.leer', async () => {
    const wrapper = await mountView()
    const link = rowFor(wrapper, 'Auditoría')
      .findAll('a')
      .find((a) => a.text() === 'Coordinación de auditoría')!

    expect(link.attributes('href')).toBe('/administracion/roles/RC')
  })
})

describe('CA-PERM-121 (RN-PERM-41, RN-PERM-44 E4/E5): filtro por defecto y búsqueda', () => {
  it('con 40 filas y 3 con fuentes se ven 3 y el anuncio dice «3 de 40»; «Incluir permisos sin ninguna concesión» las muestra sin nueva petición', async () => {
    const wrapper = await mountView()

    expect(wrapper.findAll('tbody tr')).toHaveLength(3)
    expect(announcer(wrapper)).toBe('3 de 40 resultados')

    const include = wrapper.get<HTMLInputElement>('input[type="checkbox"]')

    expect(include.element.checked).toBe(false)
    expect(wrapper.get('[data-slot="data-table-two-state-filter"]').text()).toBe(
      'Incluir permisos sin ninguna concesión',
    )

    await include.setValue(true)
    await flushPromises()

    expect(announcer(wrapper)).toBe('40 de 40 resultados')
    expect(wrapper.get('[data-slot="data-table-total"]').text()).toBe('40 resultados')
    expect(getUserEffectivePermissions).toHaveBeenCalledTimes(1)
  })

  it('buscar «audito» (sin tilde ni mayúsculas) deja las filas de auditoría', async () => {
    const wrapper = await mountView()

    await type(wrapper, 'AUDITO')

    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    expect(wrapper.find('tbody').text()).toContain('Auditoría')
    expect(announcer(wrapper)).toBe('1 de 40 resultado')
  })

  it('la búsqueda también casa con el nombre de un rol', async () => {
    const wrapper = await mountView()

    await type(wrapper, 'cuenta restringida')

    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    expect(wrapper.find('tbody').text()).toContain('Configuración')
  })

  it('la barra de filtros ofrece resultado y módulo sin nueva petición', async () => {
    const wrapper = await mountView()

    // Módulo: opciones derivadas de las filas.
    const triggers = wrapper.findAll('[data-slot="data-table-toolbar"] button')

    expect(triggers.map((b) => b.text())).toEqual(expect.arrayContaining(['Resultado', 'Módulo']))
    expect(getUserEffectivePermissions).toHaveBeenCalledTimes(1)
  })

  it('con el filtro por defecto y sin ninguna concesión, el vacío dice que no tiene ningún permiso concedido ni denegado', async () => {
    getUserEffectivePermissions.mockResolvedValue({
      ...response(5),
      data: response(5).data.slice(3),
    })
    const wrapper = await mountView()

    expect(wrapper.text()).toContain(
      'Esta persona no tiene ningún permiso concedido ni denegado por sus roles.',
    )
  })

  it('las columnas Módulo y Código están ocultas por defecto', async () => {
    const wrapper = await mountView()

    expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual([
      'Recurso',
      'Acción',
      'Resultado',
      'Ámbitos',
      'Procedencia',
    ])
  })
})

describe('CA-PERM-122 (RN-PERM-45, RN-CORE-50, RN-CORE-63): sin salida del dato', () => {
  it('no hay control de exportación, la URL no cambia al filtrar, buscar u ordenar y ningún almacenamiento contiene datos de filas', async () => {
    const wrapper = await mountView()
    const path = router.currentRoute.value.fullPath

    expect(wrapper.text()).not.toContain('Exportar')

    await type(wrapper, 'audito')
    await wrapper.get<HTMLInputElement>('input[type="checkbox"]').setValue(true)
    await wrapper.get('thead button').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.fullPath).toBe(path)

    for (const storage of [window.localStorage, window.sessionStorage]) {
      for (let index = 0; index < storage.length; index += 1) {
        const key = storage.key(index)!

        expect(key).toBe('plataforma.table.core.effective_permissions')
        expect(storage.getItem(key)).not.toMatch(/auditoria|salud|configuracion|Coordinación/)
      }
    }
  })

  it('al cambiar de idioma sale exactamente una petición nueva', async () => {
    await mountView()

    getUserEffectivePermissions.mockClear()
    setLocale('en')
    await flushPromises()

    expect(getUserEffectivePermissions).toHaveBeenCalledTimes(1)
  })

  it('en inglés se pintan las etiquetas del vocabulario (RN-PERM-27)', async () => {
    const wrapper = await mountView()

    setLocale('en')
    await flushPromises()

    expect(wrapper.find('thead').text()).toContain('Result')
    expect(rowFor(wrapper, 'Auditoría').text()).toContain('Allowed')
    expect(rowFor(wrapper, 'Auditoría').text()).toContain('Own')
  })

  it('un 404 pinta «no encontrado»', async () => {
    getUserEffectivePermissions.mockRejectedValue(new ApiError('HTTP 404', 404, {}))
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('Página no encontrada')
    expect(wrapper.find('table').exists()).toBe(false)
  })

  it('un error de servidor ofrece «Reintentar» que repite la petición', async () => {
    getUserEffectivePermissions.mockRejectedValueOnce(new ApiError('HTTP 500', 500, {}))
    const wrapper = await mountView()

    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Reintentar')!
      .trigger('click')
    await flushPromises()

    expect(getUserEffectivePermissions).toHaveBeenCalledTimes(2)
    expect(wrapper.find('table').exists()).toBe(true)
  })
})
