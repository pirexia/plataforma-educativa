/**
 * `docs/modulos/REQ-CURSO/funcional.md §10.1`, `§13.5` (1.10): listado de
 * cursos — código, fechas y estado (etiqueta traducida), orden por defecto del
 * servidor, filtro de estado múltiple, sin búsqueda ni exportación, «Nuevo
 * curso» solo con `curso_academico.crear`.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'

const listAcademicYears = vi.fn()

vi.mock('../api', () => ({
  listAcademicYears: (...args: unknown[]) => listAcademicYears(...args),
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
const { default: AcademicYearsListView } = await import('./AcademicYearsListView.vue')

function year(id: string, code: string, status: string, startsOn: string, endsOn: string) {
  return {
    public_id: id,
    code,
    starts_on: startsOn,
    ends_on: endsOn,
    status,
    created_at: '2026-06-01T10:00:00Z',
    updated_at: '2026-06-01T10:00:00Z',
  }
}

function page(rows: unknown[]) {
  return { data: rows, meta: { current_page: 1, per_page: 25, total: rows.length, last_page: 1 } }
}

const wrappers: VueWrapper[] = []

async function mountView(url = '/administracion/cursos'): Promise<VueWrapper> {
  const stub = { template: '<div/>' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/administracion/cursos',
        name: 'curso-academic-years',
        component: AcademicYearsListView,
      },
      { path: '/administracion/cursos/nuevo', name: 'curso-academic-year-new', component: stub },
      {
        path: '/administracion/cursos/:publicId',
        name: 'curso-academic-year-detail',
        component: stub,
      },
    ],
  })

  await router.push(url)
  await router.isReady()

  const wrapper = mount(AcademicYearsListView, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  setLocale('es')
  listAcademicYears
    .mockReset()
    .mockResolvedValue(
      page([
        year('Y3', '2026-2027', 'planificacion', '2026-09-01', '2027-06-30'),
        year('Y2', '2025-2026', 'activo', '2025-09-01', '2026-06-30'),
        year('Y1', '2024-2025', 'cerrado', '2024-09-01', '2025-06-30'),
      ]),
    )
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
  session.__setPermissions(['curso_academico.leer', 'curso_academico.crear'])
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('listado de cursos (funcional.md §10.1)', () => {
  it('pinta código (enlace a la ficha), fechas y la etiqueta traducida del estado', async () => {
    const wrapper = await mountView()
    const rows = wrapper.findAll('tbody tr').map((row) => row.text())

    expect(rows).toHaveLength(3)
    expect(rows[0]).toContain('2026-2027')
    expect(rows[0]).toContain('En planificación')
    expect(rows[1]).toContain('Activo')
    expect(rows[2]).toContain('Cerrado')
    expect(rows[0]).toContain('1 sept 2026')
    expect(wrapper.find('tbody th a').attributes('href')).toBe('/administracion/cursos/Y3')
  })

  it('sin búsqueda ni exportación (permisos.md §2.1)', async () => {
    const wrapper = await mountView()

    expect(wrapper.find('input[type="search"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Exportar')
  })

  it('el filtro de estado múltiple viaja como lista (ADR-038 §5.2) y el orden se delega en el servidor', async () => {
    await mountView('/administracion/cursos?status=activo,cerrado')

    expect(listAcademicYears.mock.calls.at(-1)![0]).toMatchObject({ status: ['activo', 'cerrado'] })
    expect(listAcademicYears.mock.calls.at(-1)![0].sort).toBeUndefined()
  })

  it('«Nuevo curso» aparece solo con curso_academico.crear', async () => {
    let wrapper = await mountView()

    expect(wrapper.find('a[href="/administracion/cursos/nuevo"]').text()).toBe('Nuevo curso')

    wrapper.unmount()
    wrappers.pop()
    document.body.innerHTML = ''
    session.__setPermissions(['curso_academico.leer'])
    wrapper = await mountView()

    expect(wrapper.find('a[href="/administracion/cursos/nuevo"]').exists()).toBe(false)
  })

  it('un centro sin cursos muestra el estado vacío con la acción de crear', async () => {
    listAcademicYears.mockResolvedValue(page([]))
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('Aún no hay ningún curso')
    expect(wrapper.text()).toContain('Crea el primer curso')
  })
})
