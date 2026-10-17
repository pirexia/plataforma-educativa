/**
 * `docs/modulos/REQ-ACAD/funcional.md §10.5.1`, `§4.9.4`, `§13.16` (1.11d-b):
 * ficha de escala — `CA-ACAD-099` (Editar/Borrar por permiso **y** curso
 * escribible, confirmación de borrado, `409` junto a la acción sin cambiar la
 * pantalla, `404` con su estado) y `CA-ACAD-100` (aprobado/suspenso en texto,
 * `scale_type` con etiqueta traducida y código en crudo si no tiene clave).
 * `REQ-ACAD-001`.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { setLocale } from '@/i18n'
import * as acadApi from '../api'
import * as cursoApi from '@/modules/curso/api'
import { bandScale, buttonByText, mountView, scale, stubMatchMedia, year } from '../testSupport'

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
const { default: GradingScaleDetailView } = await import('./GradingScaleDetailView.vue')

const wrappers: VueWrapper[] = []

const CONFIG_ALL = [
  'configuracion_academica.leer',
  'configuracion_academica.actualizar',
  'configuracion_academica.eliminar',
]

async function open(id = 'E1'): Promise<VueWrapper> {
  const wrapper = await mountView(
    'acad-grading-scale-detail',
    GradingScaleDetailView,
    `/academico/escalas/${id}`,
  )

  wrappers.push(wrapper)

  return wrapper
}

function routeName(wrapper: VueWrapper): string {
  return String((wrapper.vm as unknown as { $route: { name: string } }).$route.name)
}

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  window.localStorage.clear()
  resetSelectableYears()
  vi.mocked(cursoApi.listSelectableAcademicYears)
    .mockReset()
    .mockResolvedValue({
      data: [
        year('Y2', '2025-2026', 'activo'),
        year('Y1', '2024-2025', 'cerrado'),
        year('Y3', '2026-2027', 'planificacion'),
      ],
    })
  vi.mocked(acadApi.getGradingScale)
    .mockReset()
    .mockResolvedValue(scale() as never)
  vi.mocked(acadApi.deleteGradingScale).mockReset().mockResolvedValue(undefined)
  session.__setPermissions(CONFIG_ALL)
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-ACAD-100: valores con su etiqueta y aprobado en texto', () => {
  it('cualitativa: cada valor con código, etiqueta y «Aprobado»/«Suspenso» en texto', async () => {
    const wrapper = await open()
    const items = wrapper.findAll('[data-slot="acad-scale-values"] li').map((li) => li.text())

    expect(wrapper.find('h1').text()).toContain('CUAL')
    expect(wrapper.text()).toContain('Cualitativa')
    expect(items).toHaveLength(2)
    expect(items[0]).toContain('Insuficiente')
    expect(items[0]).toContain('Suspenso')
    expect(items[1]).toContain('Suficiente')
    expect(items[1]).toContain('Aprobado')
  })

  it('mixta: datos numéricos y tramos con su rango', async () => {
    vi.mocked(acadApi.getGradingScale).mockResolvedValue(bandScale() as never)

    const wrapper = await open('E2')
    const items = wrapper.findAll('[data-slot="acad-scale-values"] li').map((li) => li.text())

    expect(wrapper.text()).toContain('Mixta')
    expect(wrapper.text()).toContain('Nota mínima de aprobado')
    expect(wrapper.find('[data-slot="acad-scale-values"] h2').text()).toBe('Tramos')
    expect(items[0]).toContain('De 0.0 a 4.9')
    expect(items[1]).toContain('De 5.0 a 10.0')
  })

  it('un scale_type sin clave se muestra en crudo', async () => {
    vi.mocked(acadApi.getGradingScale).mockResolvedValue(
      scale({ scale_type: 'otra', values: [] }) as never,
    )

    const wrapper = await open()

    expect(wrapper.text()).toContain('otra')
  })
})

describe('CA-ACAD-099: Editar y Borrar por permiso y curso escribible', () => {
  it('con actualizar y eliminar en un curso activo: ambos; Editar enlaza a la edición', async () => {
    const wrapper = await open()

    expect(wrapper.find('a[href="/academico/escalas/E1/editar"]').text()).toBe('Editar')
    expect(buttonByText('Borrar')).toBeDefined()
  })

  it.each([
    ['configuracion_academica.actualizar', true, false],
    ['configuracion_academica.eliminar', false, true],
  ])('solo %s: únicamente su acción', async (permission, edit, remove) => {
    session.__setPermissions(['configuracion_academica.leer', permission])

    const wrapper = await open()

    expect(wrapper.find('a[href$="/editar"]').exists()).toBe(edit)
    expect(buttonByText('Borrar') !== undefined).toBe(remove)
  })

  it('con estructura_academica.* pero sin configuracion_academica.*: ninguna acción', async () => {
    session.__setPermissions([
      'configuracion_academica.leer',
      'estructura_academica.actualizar',
      'estructura_academica.eliminar',
    ])

    const wrapper = await open()

    expect(wrapper.find('a[href$="/editar"]').exists()).toBe(false)
    expect(buttonByText('Borrar')).toBeUndefined()
  })

  it('con el curso de la escala cerrado: aviso persistente y ninguna acción de escritura', async () => {
    vi.mocked(acadApi.getGradingScale).mockResolvedValue(scale({ academic_year: 'Y1' }) as never)

    const wrapper = await open()

    expect(wrapper.find('[data-slot="acad-scale-read-only"]').text()).toContain('solo lectura')
    expect(wrapper.find('a[href$="/editar"]').exists()).toBe(false)
    expect(buttonByText('Borrar')).toBeUndefined()
  })

  it('404: estado de error, sin ficha', async () => {
    vi.mocked(acadApi.getGradingScale).mockRejectedValue(
      new ApiError('x', 404, { type: 'urn:pge:error:not-found', status: 404 }),
    )

    const wrapper = await open()

    expect(wrapper.find('h1').exists()).toBe(false)
    expect(wrapper.find('[data-slot="acad-scale-values"]').exists()).toBe(false)
  })
})

describe('CA-ACAD-099 (§4.9.4): borrado con confirmación', () => {
  it('pide confirmación; al confirmar borra y vuelve al listado', async () => {
    const wrapper = await open()

    buttonByText('Borrar')!.click()
    await flushPromises()

    expect(acadApi.deleteGradingScale).not.toHaveBeenCalled()
    expect(document.body.textContent).toContain('Borrar la escala CUAL')
    expect(document.body.textContent).toContain('La escala y todos sus valores se borran.')

    buttonByText('Borrar CUAL')!.click()
    await flushPromises()

    expect(acadApi.deleteGradingScale).toHaveBeenCalledWith('E1')
    expect(routeName(wrapper)).toBe('acad-grading-scales')
  })

  it('cancelar no borra', async () => {
    await open()

    buttonByText('Borrar')!.click()
    await flushPromises()
    buttonByText('Cancelar')!.click()
    await flushPromises()

    expect(acadApi.deleteGradingScale).not.toHaveBeenCalled()
  })

  it('un 409 se muestra junto a la acción y la pantalla no cambia', async () => {
    vi.mocked(acadApi.deleteGradingScale).mockRejectedValue(
      new ApiError('x', 409, {
        type: 'urn:pge:error:academic-year-closed',
        status: 409,
        detail: 'El curso académico está cerrado.',
      }),
    )

    const wrapper = await open()

    buttonByText('Borrar')!.click()
    await flushPromises()
    buttonByText('Borrar CUAL')!.click()
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toContain('está cerrado')
    expect(routeName(wrapper)).toBe('acad-grading-scale-detail')
    expect(wrapper.find('h1').text()).toContain('CUAL')
  })
})
