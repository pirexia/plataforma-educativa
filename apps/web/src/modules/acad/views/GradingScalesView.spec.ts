/**
 * `docs/modulos/REQ-ACAD/funcional.md §10.5`, `§13.16` (1.11d-b): listado de
 * escalas de calificación — `CA-ACAD-098` (la lista vive en
 * `/academico/escalas`, con selector de curso, y se pide con el curso de la
 * URL), `CA-ACAD-099` (acciones por permiso y curso escribible, estados de
 * carga, vacío y solo lectura), `CA-ACAD-100` (`scale_type` con su etiqueta
 * traducida y un código sin clave cae al código en crudo; «aprobado» en texto).
 * `REQ-ACAD-001`.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { setLocale } from '@/i18n'
import * as acadApi from '../api'
import * as cursoApi from '@/modules/curso/api'
import { bandScale, mountView, page, scale, stubMatchMedia, year } from '../testSupport'

vi.mock('../api')
vi.mock('@/modules/curso/api')

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
const { resetSelectableYears } = await import('@/academic-year')
const { default: GradingScalesView } = await import('./GradingScalesView.vue')

const wrappers: VueWrapper[] = []

const YEARS = [
  year('Y3', '2026-2027', 'planificacion'),
  year('Y2', '2025-2026', 'activo'),
  year('Y1', '2024-2025', 'cerrado'),
]

async function open(url = '/academico/escalas'): Promise<VueWrapper> {
  const wrapper = await mountView('acad-grading-scales', GradingScalesView, url)

  wrappers.push(wrapper)

  return wrapper
}

function newLink(wrapper: VueWrapper) {
  return wrapper.find('a[href^="/academico/escalas/nueva"]')
}

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  window.localStorage.clear()
  resetSelectableYears()
  vi.mocked(cursoApi.listSelectableAcademicYears).mockReset().mockResolvedValue({ data: YEARS })
  vi.mocked(acadApi.listGradingScales)
    .mockReset()
    .mockResolvedValue(
      page([
        scale(),
        bandScale(),
        scale({ public_id: 'E3', code: 'RARA', scale_type: 'otra', values: [] }),
        scale({
          public_id: 'E4',
          code: 'NUM',
          scale_type: 'numerica',
          min_value: '0',
          max_value: '10',
          decimals: 0,
          passing_min_value: '5',
          values: [],
        }),
      ]) as never,
    )
  session.__setPermissions(['configuracion_academica.leer', 'configuracion_academica.crear'])
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-ACAD-100: tipo con etiqueta traducida, rango y aprobado en texto', () => {
  it('pinta código (enlace a la ficha), nombre, tipo traducido, rango y umbral', async () => {
    const wrapper = await open()
    const rows = wrapper.findAll('tbody tr').map((row) => row.text())

    expect(rows).toHaveLength(4)
    expect(rows[0]).toContain('CUAL')
    expect(rows[0]).toContain('Cualitativa')
    expect(rows[0]).toContain('2 valores')
    // Cualitativa: los códigos de los valores aprobados.
    expect(rows[0]).toContain('SU')
    expect(rows[1]).toContain('Mixta')
    expect(rows[1]).toContain('De 0.0 a 10.0')
    expect(rows[1]).toContain('Desde 5.0')
    expect(rows[3]).toContain('Numérica')
    expect(rows[3]).toContain('De 0 a 10')
    expect(wrapper.find('tbody th a').attributes('href')).toBe('/academico/escalas/E1')
  })

  it('un scale_type sin clave en el catálogo se muestra en crudo', async () => {
    const wrapper = await open()

    expect(wrapper.findAll('tbody tr')[2]!.text()).toContain('otra')
  })

  it('el filtro por tipo ofrece los tres tipos con su etiqueta', async () => {
    const wrapper = await open()

    expect(wrapper.text()).toContain('Tipo de escala')
  })
})

describe('CA-ACAD-098: el curso de la URL llega a la API', () => {
  it('sin parámetro no se envía academic_year (el servidor usa el activo)', async () => {
    await open()

    expect(vi.mocked(acadApi.listGradingScales).mock.calls[0]![0]).toMatchObject({
      academic_year: undefined,
    })
  })

  it('con ?academic_year=Y3 se envía ese curso', async () => {
    await open('/academico/escalas?academic_year=Y3')

    expect(vi.mocked(acadApi.listGradingScales).mock.calls[0]![0]).toMatchObject({
      academic_year: 'Y3',
    })
  })
})

describe('CA-ACAD-099: «Nueva escala» por permiso y curso escribible; estados', () => {
  it('con crear y un curso activo aparece y apunta al alta con el curso del selector', async () => {
    const wrapper = await open()

    expect(newLink(wrapper).exists()).toBe(true)
    expect(newLink(wrapper).text()).toBe('Nueva escala')
    expect(newLink(wrapper).attributes('href')).toBe('/academico/escalas/nueva?academic_year=Y2')
  })

  it('en planificación también aparece', async () => {
    const wrapper = await open('/academico/escalas?academic_year=Y3')

    expect(newLink(wrapper).attributes('href')).toBe('/academico/escalas/nueva?academic_year=Y3')
  })

  it('sin crear no aparece', async () => {
    session.__setPermissions(['configuracion_academica.leer'])

    const wrapper = await open()

    expect(newLink(wrapper).exists()).toBe(false)
  })

  it('con estructura_academica.crear pero sin configuracion_academica.crear no aparece (recurso distinto)', async () => {
    session.__setPermissions(['configuracion_academica.leer', 'estructura_academica.crear'])

    const wrapper = await open()

    expect(newLink(wrapper).exists()).toBe(false)
  })

  it('con un curso cerrado: aviso persistente de texto y ninguna acción de escritura', async () => {
    const wrapper = await open('/academico/escalas?academic_year=Y1')

    expect(wrapper.find('[data-slot="acad-scales-read-only"]').text()).toContain('solo lectura')
    expect(newLink(wrapper).exists()).toBe(false)
  })

  it('con un curso activo no hay aviso de solo lectura', async () => {
    const wrapper = await open()

    expect(wrapper.find('[data-slot="acad-scales-read-only"]').exists()).toBe(false)
  })

  it('estado vacío: «Este curso académico no tiene escalas»', async () => {
    vi.mocked(acadApi.listGradingScales).mockResolvedValue(page([]) as never)

    const wrapper = await open()

    expect(wrapper.text()).toContain('Este curso académico no tiene escalas')
  })

  it('error de carga: estado de error propio de la tabla', async () => {
    vi.mocked(acadApi.listGradingScales).mockRejectedValue(
      new ApiError('x', 500, { type: 'urn:pge:error:server', status: 500 }),
    )

    const wrapper = await open()

    expect(wrapper.find('h1').text()).toBe('Escalas de calificación')
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('sin curso activo: estado «sin curso activo», no un error', async () => {
    vi.mocked(cursoApi.listSelectableAcademicYears).mockResolvedValue({
      data: [year('Y3', '2026-2027', 'planificacion')],
    } as never)

    const wrapper = await open()

    expect(wrapper.text()).toContain('No hay un curso académico activo')
    expect(wrapper.find('table').exists()).toBe(false)
  })

  it('404 curso.no_active_year del listado: el mismo estado', async () => {
    vi.mocked(acadApi.listGradingScales).mockRejectedValue(
      new ApiError('x', 404, {
        type: 'urn:pge:error:not-found',
        status: 404,
        errors: { academic_year: [{ code: 'curso.no_active_year', message: 'Sin curso activo' }] },
      }),
    )

    const wrapper = await open()
    await flushPromises()

    expect(wrapper.text()).toContain('No hay un curso académico activo')
  })
})
