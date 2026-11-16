/**
 * `docs/modulos/REQ-ALUM/funcional.md §10`, `CA-ALUM-070` (el listado lleva los filtros a la URL sin `q`), `CA-ALUM-071`
 * (etiquetas, errores asociados, foco en diálogos), `RN-ALUM-04`, `RN-ALUM-05`, `RN-ALUM-27`, `INV-002`.
 * `REQ-ALUM-001`.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { setLocale } from '@/i18n'
import { SEARCH_DEBOUNCE_MS } from '@/data-table'
import * as alumApi from '../api'
import * as acadApi from '@/modules/acad/api'
import * as cursoApi from '@/modules/curso/api'
import * as famApi from '@/modules/fam-unit/api'
import {
  buttonByText,
  course,
  fill,
  group,
  mountView,
  page,
  problem,
  stubMatchMedia,
  student,
  studentSummary,
  submitForm,
  year,
} from '../testSupport'

vi.mock('../api')
vi.mock('@/modules/acad/api')
vi.mock('@/modules/curso/api')
vi.mock('@/modules/fam-unit/api')
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
const { default: StudentsView } = await import('./StudentsView.vue')

const wrappers: VueWrapper[] = []

async function open(url = '/alumnado') {
  const mounted = await mountView('alum-students', StudentsView, url)

  wrappers.push(mounted.wrapper)

  return mounted
}

function lastList(): Record<string, unknown> {
  return vi.mocked(alumApi.listStudents).mock.calls.at(-1)![0] as Record<string, unknown>
}

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  window.localStorage.clear()
  resetSelectableYears()
  vi.mocked(cursoApi.listSelectableAcademicYears)
    .mockReset()
    .mockResolvedValue({
      data: [year('Y2', '2026-2027', 'activo'), year('Y1', '2025-2026', 'cerrado')],
    } as never)
  for (const api of [alumApi, acadApi, famApi]) {
    for (const fn of Object.values(api)) {
      if (typeof fn === 'function' && 'mockReset' in fn)
        (fn as ReturnType<typeof vi.fn>).mockReset()
    }
  }
  vi.mocked(alumApi.listStudents).mockResolvedValue(
    page([
      studentSummary(),
      studentSummary({
        public_id: 'S2',
        display_name: 'Ana Ejemplo Vidal',
        birth_date: null,
        enrollment: {
          course: { public_id: 'C1', code: '1ESO', name_translations: {} },
          group: { public_id: 'G1', code: '1ESO-A', name: '1º ESO A' },
        },
      }),
    ]) as never,
  )
  vi.mocked(acadApi.listCourses).mockResolvedValue(page([course()]) as never)
  vi.mocked(acadApi.listGroups).mockResolvedValue(page([group()]) as never)
  vi.mocked(famApi.listFamilyUnits).mockResolvedValue(page([]) as never)
  session.__setPermissions(['alumno.leer', 'alumno.crear'])
})

afterEach(() => {
  vi.useRealTimers()
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-ALUM-070: listado del alumnado', () => {
  it('pinta nombre (enlace a la ficha), fecha de nacimiento y, con matrícula, curso y grupo', async () => {
    session.__setPermissions(['alumno.leer', 'matricula.leer'])

    const { wrapper } = await open()
    const rows = wrapper.findAll('tbody tr').map((row) => row.text())

    expect(rows).toHaveLength(2)
    expect(rows[0]).toContain('Lucas Ejemplo Ruiz')
    expect(rows[1]).toContain('Ana Ejemplo Vidal')
    expect(rows[1]).toContain('1ESO · 1º ESO A')
    expect(wrapper.find('tbody th a').attributes('href')).toBe('/alumnado/S1')
  })

  it('sin matricula.leer no hay columna de matrícula ni filtros de matrícula', async () => {
    const { wrapper } = await open('/alumnado?course=C1&group=G1&enrollment_status=activa')

    expect(wrapper.text()).not.toContain('Matrícula')
    expect(acadApi.listCourses).not.toHaveBeenCalled()

    const params = lastList()

    expect(params.course).toBeUndefined()
    expect(params.group).toBeUndefined()
    expect(params.enrollment_status).toBeUndefined()
  })

  it('los filtros, el orden y el curso de la URL llegan a la API', async () => {
    session.__setPermissions(['alumno.leer', 'matricula.leer', 'alumno.eliminar'])

    await open(
      '/alumnado?academic_year=Y1&course=C1&group=G1&enrollment_status=activa&include_deleted=true&sort=-created_at',
    )

    expect(lastList()).toMatchObject({
      academic_year: 'Y1',
      course: 'C1',
      group: 'G1',
      enrollment_status: 'activa',
      include_deleted: 'true',
      sort: '-created_at',
    })
    expect(vi.mocked(acadApi.listCourses).mock.calls.at(-1)![0]).toMatchObject({
      academic_year: 'Y1',
    })
  })

  it('el orden va a la URL conservando academic_year; el texto de búsqueda q nunca', async () => {
    vi.useFakeTimers()

    const { wrapper, router } = await open('/alumnado?academic_year=Y1')

    await wrapper.get('input[type="search"]').setValue('Luc')
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 10)
    await flushPromises()

    expect(lastList().q).toBe('Luc')
    expect(JSON.stringify(router.currentRoute.value.query)).not.toContain('Luc')
    expect(window.location.href).not.toContain('Luc')
    expect(router.currentRoute.value.query.academic_year).toBe('Y1')

    const sortButton = [...wrapper.findAll('button')].find((button) =>
      button.attributes('aria-label')?.startsWith('Nombre'),
    )

    await sortButton!.trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query.sort).toBe('name')
    expect(router.currentRoute.value.query.academic_year).toBe('Y1')
    expect(JSON.stringify(router.currentRoute.value.query)).not.toContain('Luc')
  })

  it('una búsqueda de un solo carácter no se envía (mínimo 2 en el servidor)', async () => {
    vi.useFakeTimers()

    const { wrapper } = await open()

    await wrapper.get('input[type="search"]').setValue('L')
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 10)
    await flushPromises()

    expect(lastList().q).toBeUndefined()
  })

  it('la ficha recibe el curso académico elegido para respetar el solo lectura', async () => {
    const { wrapper } = await open('/alumnado?academic_year=Y1')

    expect(wrapper.find('tbody th a').attributes('href')).toBe('/alumnado/S1?academic_year=Y1')
  })

  it('un 403 del servidor se pinta como estado de error de pantalla', async () => {
    vi.mocked(alumApi.listStudents).mockRejectedValue(problem(403))

    const { wrapper } = await open()

    expect(wrapper.find('tbody').exists()).toBe(false)
  })
})

describe('INV-002 / RN-ACAD-42: acciones por permiso y por curso', () => {
  it('con alumno.crear hay alta; sin él no', async () => {
    const { wrapper } = await open()

    expect(wrapper.text()).toContain('Nuevo alumno')

    session.__setPermissions(['alumno.leer'])
    const other = await open()

    expect(other.wrapper.text()).not.toContain('Nuevo alumno')
  })

  it('con un curso de solo lectura no hay alta aunque se tenga alumno.crear', async () => {
    const { wrapper } = await open('/alumnado?academic_year=Y1')

    expect(wrapper.text()).not.toContain('Nuevo alumno')
  })

  it('CA-ALUM-070: mientras el curso no se ha resuelto no es escribible: sin alta (year.writable)', async () => {
    vi.mocked(cursoApi.listSelectableAcademicYears).mockReturnValue(new Promise(() => undefined))

    const { wrapper } = await open()

    expect(wrapper.text()).not.toContain('Nuevo alumno')
  })

  it('«Incluir bajas» solo con alumno.eliminar, y la baja se restaura desde la fila tras confirmar', async () => {
    session.__setPermissions(['alumno.leer', 'alumno.eliminar'])
    vi.mocked(alumApi.listStudents).mockResolvedValue(
      page([studentSummary({ deleted_at: '2026-11-02T09:00:00Z' })]) as never,
    )
    vi.mocked(alumApi.restoreStudent).mockResolvedValue(student() as never)

    const { wrapper } = await open('/alumnado?include_deleted=true')

    expect(wrapper.find('tbody a').exists()).toBe(false)
    expect(wrapper.text()).toContain('baja')

    await buttonByText('Restaurar')!.click()
    await flushPromises()
    expect(alumApi.restoreStudent).not.toHaveBeenCalled()

    await buttonByText('Restaurar a Lucas Ejemplo Ruiz')!.click()
    await flushPromises()

    expect(alumApi.restoreStudent).toHaveBeenCalledWith('S1')
  })
})

describe('CA-ALUM-071: formulario de alta accesible y RN-ALUM-27 (idempotencia)', () => {
  async function openForm() {
    const mounted = await open()

    await buttonByText('Nuevo alumno')!.click()
    await flushPromises()

    return mounted
  }

  it('cada campo tiene su etiqueta asociada y el diálogo tiene título', async () => {
    await openForm()

    for (const field of ['given_name', 'family_name_1', 'family_name_2', 'birth_date']) {
      const input = document.body.querySelector(`#alum-student-${field}`)

      expect(input, field).not.toBeNull()
      expect(
        document.body.querySelector(`label[for="alum-student-${field}"]`),
        field,
      ).not.toBeNull()
    }

    expect(document.body.querySelector('[role="dialog"]')).not.toBeNull()
    expect(document.body.querySelector('[role="dialog"] h2')?.textContent).toContain('Nuevo alumno')
  })

  it('sin familia.actualizar no se ofrece la unidad familiar (OPEN-ALUM-31 = A)', async () => {
    await openForm()

    expect(document.body.querySelector('[data-slot="alum-family-picker"]')).toBeNull()
  })

  it('con familia.actualizar se ofrece la unidad familiar opcional', async () => {
    session.__setPermissions(['alumno.leer', 'alumno.crear', 'familia.actualizar'])
    await openForm()

    expect(document.body.querySelector('[data-slot="alum-family-picker"]')).not.toBeNull()
  })

  it('envía el cuerpo con una Idempotency-Key ULID y la reutiliza en el reintento del mismo cuerpo', async () => {
    vi.mocked(alumApi.createStudent)
      .mockRejectedValueOnce(problem(503))
      .mockResolvedValue(student({ public_id: 'S9' }) as never)

    const { router } = await openForm()

    await fill('#alum-student-given_name', 'Lucas')
    await fill('#alum-student-family_name_1', 'Ejemplo')
    await fill('#alum-student-birth_date', '2015-04-12')
    await submitForm('alum-student-form')
    await submitForm('alum-student-form')

    const calls = vi.mocked(alumApi.createStudent).mock.calls

    expect(calls).toHaveLength(2)
    expect(calls[0]![0]).toEqual({
      given_name: 'Lucas',
      family_name_1: 'Ejemplo',
      birth_date: '2015-04-12',
    })
    expect(calls[0]![1]).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/)
    expect(calls[1]![1]).toBe(calls[0]![1])
    expect(router.currentRoute.value.fullPath).toBe('/alumnado/S9')
  })

  it('un 422 del servidor se pinta junto al campo con aria-invalid y aria-describedby', async () => {
    vi.mocked(alumApi.createStudent).mockRejectedValue(
      problem(422, undefined, {
        birth_date: [
          {
            code: 'alum.validation.birth_date_invalid',
            message: 'La fecha de nacimiento no es válida.',
          },
        ],
      }),
    )

    await openForm()
    await fill('#alum-student-given_name', 'Lucas')
    await fill('#alum-student-family_name_1', 'Ejemplo')
    await fill('#alum-student-birth_date', '2999-01-01')
    await submitForm('alum-student-form')

    const input = document.body.querySelector('#alum-student-birth_date')!
    const errors = document.getElementById(input.getAttribute('aria-describedby')!)

    expect(input.getAttribute('aria-invalid')).toBe('true')
    expect(errors?.getAttribute('role')).toBe('alert')
    expect(errors?.textContent).toContain('La fecha de nacimiento no es válida.')
  })
})
