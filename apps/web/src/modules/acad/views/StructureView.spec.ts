/**
 * `docs/modulos/REQ-ACAD/funcional.md §10.1`, `§13.9` (1.11-b): listado de etapas
 * — `CA-ACAD-046` (acciones por permiso y curso escribible), `CA-ACAD-047` (el
 * curso de la URL llega a la API), `CA-ACAD-048` (solo lectura), `CA-ACAD-049`
 * (sin curso activo), `CA-ACAD-059` (etiquetas de `stage`/`regime`).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { setLocale } from '@/i18n'
import * as acadApi from '../api'
import * as cursoApi from '@/modules/curso/api'
import { buttonByText, level, mountView, page, stubMatchMedia, year } from '../testSupport'

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

vi.mock('@/tenant/useTenantBranding', async () => {
  const { shallowRef } = await import('vue')
  const branding = shallowRef({ default_locale: 'es-ES', active_locales: ['es-ES', 'en'] })

  return { useTenantBranding: () => ({ branding }) }
})

const session = (await import('@/session/useSession')) as unknown as {
  __setPermissions: (permissions: string[]) => void
}
const { resetSelectableYears } = await import('@/academic-year')
const { default: StructureView } = await import('./StructureView.vue')

const wrappers: VueWrapper[] = []

async function open(url = '/academico/estructura'): Promise<VueWrapper> {
  const wrapper = await mountView('acad-structure', StructureView, url)

  wrappers.push(wrapper)

  return wrapper
}

const YEARS = [
  year('Y3', '2026-2027', 'planificacion'),
  year('Y2', '2025-2026', 'activo'),
  year('Y1', '2024-2025', 'cerrado'),
]

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  window.localStorage.clear()
  resetSelectableYears()
  vi.mocked(cursoApi.listSelectableAcademicYears).mockReset().mockResolvedValue({ data: YEARS })
  vi.mocked(acadApi.listEducationLevels)
    .mockReset()
    .mockResolvedValue(
      page([
        level(),
        level({ public_id: 'L2', code: 'ODD', stage: 'otra', regime: 'mixto', name: 'Rara' }),
      ]),
    )
  session.__setPermissions(['estructura_academica.leer', 'estructura_academica.crear'])
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-ACAD-059 / CA-ACAD-046: listado de etapas', () => {
  it('pinta código (enlace a la ficha), nombre y las etiquetas traducidas de stage y regime', async () => {
    const wrapper = await open()
    const rows = wrapper.findAll('tbody tr').map((row) => row.text())

    expect(rows).toHaveLength(2)
    expect(rows[0]).toContain('Educación Secundaria Obligatoria')
    expect(rows[0]).toContain('Concertado')
    expect(wrapper.find('tbody th a').attributes('href')).toBe('/academico/etapas/L1')
  })

  it('CA-ACAD-059: un código sin clave en el catálogo se muestra en crudo', async () => {
    const wrapper = await open()
    const rows = wrapper.findAll('tbody tr').map((row) => row.text())

    expect(rows[1]).toContain('otra')
    expect(rows[1]).toContain('mixto')
  })

  it('sin búsqueda ni exportación (api.md §3, §4)', async () => {
    const wrapper = await open()

    expect(wrapper.find('input[type="search"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Exportar')
  })
})

describe('CA-ACAD-047: el curso académico de la URL llega a la API', () => {
  it('sin parámetro, no se envía academic_year (el servidor usa el activo)', async () => {
    await open()

    expect(vi.mocked(acadApi.listEducationLevels).mock.calls[0]![0]).toMatchObject({
      academic_year: undefined,
    })
  })

  it('con ?academic_year=Y3 se envía ese curso', async () => {
    await open('/academico/estructura?academic_year=Y3')

    expect(vi.mocked(acadApi.listEducationLevels).mock.calls[0]![0]).toMatchObject({
      academic_year: 'Y3',
    })
  })
})

describe('CA-ACAD-046 / CA-ACAD-048: «Nueva etapa» por permiso y curso escribible; solo lectura', () => {
  it('con crear y un curso activo aparece', async () => {
    await open()

    expect(buttonByText('Nueva etapa')).toBeDefined()
  })

  it('en planificación también aparece (RN-CURSO-20)', async () => {
    await open('/academico/estructura?academic_year=Y3')

    expect(buttonByText('Nueva etapa')).toBeDefined()
  })

  it('sin crear no aparece', async () => {
    session.__setPermissions(['estructura_academica.leer'])
    await open()

    expect(buttonByText('Nueva etapa')).toBeUndefined()
  })

  it('con un curso cerrado: aviso persistente de texto y ninguna acción de escritura', async () => {
    const wrapper = await open('/academico/estructura?academic_year=Y1')

    expect(wrapper.find('[data-slot="acad-read-only"]').text()).toContain('solo lectura')
    expect(buttonByText('Nueva etapa')).toBeUndefined()
  })

  it('con un curso activo no hay aviso de solo lectura', async () => {
    const wrapper = await open()

    expect(wrapper.find('[data-slot="acad-read-only"]').exists()).toBe(false)
  })
})

describe('CA-ACAD-049: sin curso activo', () => {
  it('sin parámetro y sin curso activo entre las opciones: estado «sin curso activo», no un error', async () => {
    vi.mocked(cursoApi.listSelectableAcademicYears).mockResolvedValue({
      data: [year('Y3', '2026-2027', 'planificacion')],
    })

    const wrapper = await open()

    expect(wrapper.text()).toContain('No hay un curso académico activo')
    expect(wrapper.text()).not.toContain('Algo ha ido mal')
    expect(wrapper.find('table').exists()).toBe(false)
  })

  it('404 curso.no_active_year del listado: el mismo estado', async () => {
    vi.mocked(acadApi.listEducationLevels).mockRejectedValue(
      new ApiError('x', 404, {
        type: 'urn:pge:error:not-found',
        status: 404,
        errors: { academic_year: [{ code: 'curso.no_active_year', message: 'Sin curso activo' }] },
      }),
    )
    resetSelectableYears()
    vi.mocked(cursoApi.listSelectableAcademicYears).mockRejectedValue(new Error('offline'))

    const wrapper = await open()
    await flushPromises()

    expect(wrapper.text()).toContain('No hay un curso académico activo')
  })

  it('con un curso en la URL no es «sin curso activo» aunque no haya activo', async () => {
    vi.mocked(cursoApi.listSelectableAcademicYears).mockResolvedValue({
      data: [year('Y3', '2026-2027', 'planificacion')],
    })

    const wrapper = await open('/academico/estructura?academic_year=Y3')

    expect(wrapper.text()).not.toContain('No hay un curso académico activo')
    expect(wrapper.findAll('tbody tr')).toHaveLength(2)
  })
})
