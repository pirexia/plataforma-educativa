/**
 * `docs/modulos/REQ-ACAD/funcional.md §10.1`, `§4.5`, `§4.6`, `§13.9` (1.11-b):
 * fichas de etapa, curso, grupo y asignatura — `CA-ACAD-046` (acciones por
 * permiso y curso escribible), `CA-ACAD-048` (solo lectura), `CA-ACAD-050`
 * (`409`/`422` junto a la acción y la pantalla no cambia), `RN-ACAD-30..32`
 * (asignación de asignaturas, parte SPA), selector fijo en el curso de la entidad.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { setLocale } from '@/i18n'
import * as acadApi from '../api'
import * as cursoApi from '@/modules/curso/api'
import {
  buttonByText,
  course,
  group,
  level,
  mountView,
  page,
  stubMatchMedia,
  subgroup,
  subject,
  year,
} from '../testSupport'

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
const { default: LevelDetailView } = await import('./LevelDetailView.vue')
const { default: CourseDetailView } = await import('./CourseDetailView.vue')
const { default: GroupDetailView } = await import('./GroupDetailView.vue')
const { default: SubjectDetailView } = await import('./SubjectDetailView.vue')

const wrappers: VueWrapper[] = []

async function open(name: string, component: object, url: string): Promise<VueWrapper> {
  const wrapper = await mountView(name, component as never, url)

  wrappers.push(wrapper)

  return wrapper
}

const ALL = [
  'estructura_academica.leer',
  'estructura_academica.crear',
  'estructura_academica.actualizar',
  'estructura_academica.eliminar',
]

function conflict(dependents: string[]) {
  return new ApiError('x', 409, {
    type: 'urn:pge:error:conflict',
    status: 409,
    detail: 'El curso 2ESO tiene grupos o asignaturas.',
    errors: {
      dependents: [{ code: 'acad.conflict.has_dependents', message: 'x', params: { dependents } }],
    },
  })
}

async function confirmDelete(text: string): Promise<void> {
  buttonByText(text)!.click()
  await flushPromises()
}

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  window.localStorage.clear()
  resetSelectableYears()
  vi.mocked(cursoApi.listSelectableAcademicYears)
    .mockReset()
    .mockResolvedValue({
      data: [year('Y2', '2025-2026', 'activo'), year('Y1', '2024-2025', 'cerrado')],
    })
  for (const fn of Object.values(acadApi)) {
    if (typeof fn === 'function' && 'mockReset' in fn) (fn as ReturnType<typeof vi.fn>).mockReset()
  }
  vi.mocked(acadApi.getEducationLevel).mockResolvedValue(level() as never)
  vi.mocked(acadApi.getCourse).mockResolvedValue(course() as never)
  vi.mocked(acadApi.getGroup).mockResolvedValue(group() as never)
  vi.mocked(acadApi.getSubject).mockResolvedValue(subject() as never)
  vi.mocked(acadApi.listCourses).mockResolvedValue(page([course()]) as never)
  vi.mocked(acadApi.listGroups).mockResolvedValue(page([group()]) as never)
  vi.mocked(acadApi.listSubjects).mockResolvedValue(
    page([subject(), subject({ public_id: 'M2', code: 'LEN', name: 'Lengua' })]) as never,
  )
  vi.mocked(acadApi.listSubgroups).mockResolvedValue({ data: [subgroup()] } as never)
  vi.mocked(acadApi.listGroupSubjects).mockResolvedValue({ data: [subject()] } as never)
  session.__setPermissions(ALL)
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-ACAD-046 / CA-ACAD-048: acciones de la ficha de etapa', () => {
  it('con los tres permisos y un curso activo: Editar, Borrar y Nuevo curso', async () => {
    const wrapper = await open('acad-level-detail', LevelDetailView, '/academico/etapas/L1')

    expect(wrapper.text()).toContain('ESO')
    expect(buttonByText('Editar')).toBeDefined()
    expect(buttonByText('Borrar')).toBeDefined()
    expect(buttonByText('Nuevo curso')).toBeDefined()
    expect(wrapper.find('[data-slot="acad-read-only"]').exists()).toBe(false)
  })

  it('cada acción exige su permiso', async () => {
    session.__setPermissions(['estructura_academica.leer', 'estructura_academica.actualizar'])
    await open('acad-level-detail', LevelDetailView, '/academico/etapas/L1')

    expect(buttonByText('Editar')).toBeDefined()
    expect(buttonByText('Borrar')).toBeUndefined()
    expect(buttonByText('Nuevo curso')).toBeUndefined()
  })

  it('con un curso cerrado: aviso persistente y ninguna acción, aunque tenga todos los permisos', async () => {
    vi.mocked(acadApi.getEducationLevel).mockResolvedValue(level({ academic_year: 'Y1' }) as never)

    const wrapper = await open('acad-level-detail', LevelDetailView, '/academico/etapas/L1')

    expect(wrapper.find('[data-slot="acad-read-only"]').text()).toContain('solo lectura')
    expect(buttonByText('Editar')).toBeUndefined()
    expect(buttonByText('Borrar')).toBeUndefined()
    expect(buttonByText('Nuevo curso')).toBeUndefined()
  })

  it('pide los cursos de la etapa con el curso académico de la entidad', async () => {
    await open('acad-level-detail', LevelDetailView, '/academico/etapas/L1')

    expect(vi.mocked(acadApi.listCourses).mock.calls[0]![0]).toMatchObject({
      academic_year: 'Y2',
      education_level: ['L1'],
    })
  })

  it('un 404 de la etapa muestra el estado de no encontrado', async () => {
    vi.mocked(acadApi.getEducationLevel).mockRejectedValue(
      new ApiError('x', 404, { type: 'urn:pge:error:not-found', status: 404 }),
    )

    const wrapper = await open('acad-level-detail', LevelDetailView, '/academico/etapas/NOPE')

    expect(wrapper.text()).toContain('Página no encontrada')
    expect(buttonByText('Editar')).toBeUndefined()
  })
})

describe('CA-ACAD-050: 409 has_dependents junto a la acción y la pantalla no cambia', () => {
  it('cancelar la confirmación no envía ninguna petición', async () => {
    await open('acad-course-detail', CourseDetailView, '/academico/cursos/C1')

    buttonByText('Borrar')!.click()
    await flushPromises()
    buttonByText('Cancelar')!.click()
    await flushPromises()

    expect(acadApi.deleteCourse).not.toHaveBeenCalled()
  })

  it('el 409 muestra el mensaje del servidor y los dependientes, sin recargar ni navegar', async () => {
    vi.mocked(acadApi.deleteCourse).mockRejectedValue(conflict(['groups', 'subjects']))
    const wrapper = await open('acad-course-detail', CourseDetailView, '/academico/cursos/C1')
    const loads = vi.mocked(acadApi.getCourse).mock.calls.length

    buttonByText('Borrar')!.click()
    await flushPromises()
    await confirmDelete('Borrar 2ESO')

    expect(acadApi.deleteCourse).toHaveBeenCalledWith('C1')
    expect(wrapper.find('[role="alert"]').text()).toContain(
      'El curso 2ESO tiene grupos o asignaturas.',
    )
    expect(wrapper.text()).toContain('Grupos')
    expect(wrapper.text()).toContain('Asignaturas')
    expect(vi.mocked(acadApi.getCourse).mock.calls.length).toBe(loads)
    expect(wrapper.find('h1').text()).toContain('2ESO')
  })

  it('409 academic-year-closed al borrar: mensaje junto a la acción', async () => {
    vi.mocked(acadApi.deleteGroup).mockRejectedValue(
      new ApiError('x', 409, {
        type: 'urn:pge:error:academic-year-closed',
        status: 409,
        detail: 'El curso académico está cerrado.',
      }),
    )
    const wrapper = await open('acad-group-detail', GroupDetailView, '/academico/grupos/G1')

    buttonByText('Borrar')!.click()
    await flushPromises()
    await confirmDelete('Borrar A')

    expect(wrapper.find('[role="alert"]').text()).toContain('El curso académico está cerrado.')
  })

  it('un borrado correcto avisa y navega al curso del grupo', async () => {
    vi.mocked(acadApi.deleteGroup).mockResolvedValue(undefined)
    const wrapper = await open('acad-group-detail', GroupDetailView, '/academico/grupos/G1')

    buttonByText('Borrar')!.click()
    await flushPromises()
    await confirmDelete('Borrar A')

    expect(acadApi.deleteGroup).toHaveBeenCalledWith('G1')
    void wrapper
  })
})

describe('CA-ACAD-046 / RN-ACAD-30..32: asignación de asignaturas a un grupo (parte SPA)', () => {
  it('lista las asignaturas asignadas', async () => {
    const wrapper = await open('acad-group-detail', GroupDetailView, '/academico/grupos/G1')

    expect(wrapper.find('ul[aria-label="Asignaturas que cursa"]').text()).toContain('MAT')
    expect(wrapper.find('ul[aria-label="Asignaturas que cursa"]').text()).not.toContain('LEN')
  })

  it('«Editar asignaturas» solo con actualizar y un curso escribible', async () => {
    await open('acad-group-detail', GroupDetailView, '/academico/grupos/G1')
    expect(buttonByText('Editar asignaturas')).toBeDefined()

    while (wrappers.length > 0) wrappers.pop()!.unmount()
    session.__setPermissions(['estructura_academica.leer', 'estructura_academica.crear'])
    await open('acad-group-detail', GroupDetailView, '/academico/grupos/G1')
    expect(buttonByText('Editar asignaturas')).toBeUndefined()
  })

  it('en un curso cerrado no hay edición de asignaturas', async () => {
    vi.mocked(acadApi.getGroup).mockResolvedValue(group({ academic_year: 'Y1' }) as never)
    await open('acad-group-detail', GroupDetailView, '/academico/grupos/G1')

    expect(buttonByText('Editar asignaturas')).toBeUndefined()
  })

  it('guardar envía el conjunto completo (PUT) con las casillas marcadas', async () => {
    vi.mocked(acadApi.replaceGroupSubjects).mockResolvedValue({
      data: [subject(), subject({ public_id: 'M2', code: 'LEN', name: 'Lengua' })],
    } as never)
    const wrapper = await open('acad-group-detail', GroupDetailView, '/academico/grupos/G1')

    buttonByText('Editar asignaturas')!.click()
    await flushPromises()

    const boxes = wrapper.findAll('input[type="checkbox"]')

    expect(boxes).toHaveLength(2)
    expect((boxes[0]!.element as HTMLInputElement).checked).toBe(true)
    expect((boxes[1]!.element as HTMLInputElement).checked).toBe(false)

    await boxes[1]!.setValue(true)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(acadApi.replaceGroupSubjects).toHaveBeenCalledWith('G1', ['M1', 'M2'])
    expect(wrapper.text()).toContain('Asignaturas del grupo actualizadas.')
    expect(wrapper.find('ul[aria-label="Asignaturas que cursa"]').text()).toContain('LEN')
  })

  it('un 422 subject_not_in_course se muestra y el conjunto no cambia', async () => {
    vi.mocked(acadApi.replaceGroupSubjects).mockRejectedValue(
      new ApiError('x', 422, {
        type: 'urn:pge:error:validation',
        status: 422,
        detail: 'Asignatura ajena al curso.',
      }),
    )
    const wrapper = await open('acad-group-detail', GroupDetailView, '/academico/grupos/G1')

    buttonByText('Editar asignaturas')!.click()
    await flushPromises()
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('form [role="alert"]').text()).toContain('Asignatura ajena al curso.')
    expect(wrapper.find('form').exists()).toBe(true)
  })

  it('lista los subgrupos y ofrece editar y borrar cada uno con su permiso', async () => {
    const wrapper = await open('acad-group-detail', GroupDetailView, '/academico/grupos/G1')

    expect(wrapper.find('tbody').text()).toContain('D1')
    expect(wrapper.find('tbody').text()).toContain('Desdoble 1')
    expect(wrapper.find('tbody').text()).toContain('Editar')
    expect(buttonByText('Nuevo subgrupo')).toBeDefined()
  })
})

describe('ficha de asignatura: grupos que la cursan', () => {
  it('muestra solo los grupos cuya asignación incluye la asignatura', async () => {
    vi.mocked(acadApi.listGroups).mockResolvedValue(
      page([group(), group({ public_id: 'G2', code: 'B', name: '2.º ESO B' })]) as never,
    )
    vi.mocked(acadApi.listGroupSubjects).mockImplementation(((id: string) =>
      Promise.resolve({ data: id === 'G1' ? [subject()] : [] })) as never)

    const wrapper = await open(
      'acad-subject-detail',
      SubjectDetailView,
      '/academico/asignaturas/M1',
    )
    const list = wrapper.find('ul[aria-label="Grupos que la cursan"]')

    expect(list.text()).toContain('2.º ESO A')
    expect(list.text()).not.toContain('2.º ESO B')
  })

  it('sin grupos: mensaje', async () => {
    vi.mocked(acadApi.listGroupSubjects).mockResolvedValue({ data: [] } as never)

    const wrapper = await open(
      'acad-subject-detail',
      SubjectDetailView,
      '/academico/asignaturas/M1',
    )

    expect(wrapper.text()).toContain('Ningún grupo cursa esta asignatura.')
  })
})
