/**
 * `docs/modulos/REQ-ALUM/funcional.md §10`, `CA-ALUM-070` (filtros a la URL sin `q`; sin acciones de escritura con un
 * curso de solo lectura), `CA-ALUM-071` (etiquetas, foco en diálogos), `RN-ALUM-40`, `RN-ACAD-42`, `INV-002`,
 * `REQ-ALUM-003`. `1.15-d` T2b: lista de clase con exportación y solicitud de cambio de grupo por fila.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { setLocale } from '@/i18n'
import * as alumApi from '../api'
import * as acadApi from '@/modules/acad/api'
import * as cursoApi from '@/modules/curso/api'
import * as coreApi from '@/modules/core/api'
import {
  buttonByText,
  chooseOption,
  course,
  enrollment,
  fill,
  group,
  mountView,
  page,
  problem,
  stubMatchMedia,
  submitForm,
  year,
} from '../testSupport'

vi.mock('../api')
vi.mock('@/modules/acad/api')
vi.mock('@/modules/curso/api')
vi.mock('@/modules/core/api')
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
const { default: ClassListView } = await import('./ClassListView.vue')

const wrappers: VueWrapper[] = []

async function open(url = '/alumnado/listas') {
  const mounted = await mountView('alum-class-list', ClassListView, url)

  wrappers.push(mounted.wrapper)

  return mounted
}

function lastList(): Record<string, unknown> {
  return vi.mocked(alumApi.listEnrollments).mock.calls.at(-1)![0] as Record<string, unknown>
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
  for (const api of [alumApi, acadApi, coreApi]) {
    for (const fn of Object.values(api)) {
      if (typeof fn === 'function' && 'mockReset' in fn)
        (fn as ReturnType<typeof vi.fn>).mockReset()
    }
  }
  vi.mocked(alumApi.listEnrollments).mockResolvedValue(
    page([
      enrollment({ academic_year: { public_id: 'Y2', code: '2026-2027' } }),
      enrollment({
        public_id: 'E2',
        student: { public_id: 'S2', display_name: 'Ana Ejemplo Vidal' },
        academic_year: { public_id: 'Y2', code: '2026-2027' },
        group: null,
      }),
      enrollment({
        public_id: 'E3',
        student: { public_id: 'S3', display_name: 'Pablo Ejemplo Gil' },
        academic_year: { public_id: 'Y2', code: '2026-2027' },
        status: 'baja',
      }),
    ]) as never,
  )
  vi.mocked(acadApi.listCourses).mockResolvedValue(page([course()]) as never)
  vi.mocked(acadApi.listGroups).mockResolvedValue(
    page([
      group({ course: 'C1' }),
      group({ public_id: 'G2', code: '1ESO-B', name: '1º ESO B', course: 'C1' }),
    ]) as never,
  )
  session.__setPermissions(['matricula.leer'])
})

afterEach(() => {
  vi.useRealTimers()
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-ALUM-070: lista de clase', () => {
  it('pinta alumno (enlace a la ficha), curso, grupo, estado y fecha de matrícula', async () => {
    const { wrapper } = await open()
    const rows = wrapper.findAll('tbody tr').map((row) => row.text())

    expect(rows).toHaveLength(3)
    expect(rows[0]).toContain('Lucas Ejemplo Ruiz')
    expect(rows[0]).toContain('1ESO')
    expect(rows[0]).toContain('1º ESO A')
    expect(rows[0]).toContain('Activa')
    expect(rows[1]).toContain('Sin grupo')
    expect(rows[2]).toContain('Baja')
    expect(wrapper.find('tbody th a').attributes('href')).toBe('/alumnado/S1')
  })

  it('curso y grupo son opcionales: sin filtros, la API recibe solo el curso académico y la paginación', async () => {
    await open()

    const params = lastList()

    expect(params.course).toBeUndefined()
    expect(params.group).toBeUndefined()
    expect(params.status).toBeUndefined()
  })

  it('los filtros, el orden y el curso de la URL llegan a la API (el estado es status, no enrollment_status)', async () => {
    await open('/alumnado/listas?academic_year=Y1&course=C1&group=G1&status=baja&sort=-enrolled_on')

    expect(lastList()).toMatchObject({
      academic_year: 'Y1',
      course: 'C1',
      group: 'G1',
      status: 'baja',
      sort: '-enrolled_on',
    })
    expect(lastList()).not.toHaveProperty('enrollment_status')
    expect(lastList()).not.toHaveProperty('q')
    expect(vi.mocked(acadApi.listGroups).mock.calls.at(-1)![0]).toMatchObject({
      academic_year: 'Y1',
    })
  })

  it('el orden va a la URL conservando academic_year y no hay búsqueda ni q', async () => {
    const { wrapper, router } = await open('/alumnado/listas?academic_year=Y1')

    expect(wrapper.find('input[type="search"]').exists()).toBe(false)

    const sortButton = [...wrapper.findAll('button')].find((button) =>
      button.attributes('aria-label')?.startsWith('Nombre'),
    )

    await sortButton!.trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query.sort).toBe('name')
    expect(router.currentRoute.value.query.academic_year).toBe('Y1')
    expect(JSON.stringify(router.currentRoute.value.query)).not.toContain('"q"')
  })

  it('la ficha recibe el curso académico elegido para respetar el solo lectura', async () => {
    const { wrapper } = await open('/alumnado/listas?academic_year=Y1')

    expect(wrapper.find('tbody th a').attributes('href')).toBe('/alumnado/S1?academic_year=Y1')
  })

  it('un 403 del servidor se pinta como estado de error de pantalla', async () => {
    vi.mocked(alumApi.listEnrollments).mockRejectedValue(problem(403))

    const { wrapper } = await open()

    expect(wrapper.findAll('tbody tr')).toHaveLength(0)
    expect(wrapper.text()).toContain('permiso')
  })
})

describe('CA-ALUM-070 (RN-ALUM-40, RN-ACAD-42, INV-002): solicitud de cambio de grupo por fila', () => {
  it('sin matricula.actualizar no hay acciones por fila ni columna de acciones', async () => {
    const { wrapper } = await open()

    expect(buttonByText('Solicitar cambio de grupo')).toBeUndefined()
    expect(wrapper.text()).not.toContain('Acciones')
  })

  it('con matricula.actualizar solo las matrículas activas con grupo ofrecen la acción', async () => {
    session.__setPermissions(['matricula.leer', 'matricula.actualizar'])

    const { wrapper } = await open()
    const buttons = wrapper.findAll('tbody tr').map((row) => row.text().includes('Solicitar'))

    expect(buttons).toEqual([true, false, false])
  })

  it('con un curso de solo lectura (cerrado) no hay acciones por fila', async () => {
    session.__setPermissions(['matricula.leer', 'matricula.actualizar'])
    vi.mocked(alumApi.listEnrollments).mockResolvedValue(
      page([enrollment({ academic_year: { public_id: 'Y1', code: '2025-2026' } })]) as never,
    )

    const { wrapper } = await open('/alumnado/listas?academic_year=Y1')

    expect(wrapper.text()).toContain('solo lectura')
    expect(buttonByText('Solicitar cambio de grupo')).toBeUndefined()
  })

  it('una matrícula de otro curso que el elegido no ofrece la acción', async () => {
    session.__setPermissions(['matricula.leer', 'matricula.actualizar'])
    vi.mocked(alumApi.listEnrollments).mockResolvedValue(
      page([enrollment({ academic_year: { public_id: 'Y1', code: '2025-2026' } })]) as never,
    )

    await open('/alumnado/listas?academic_year=Y2')

    expect(buttonByText('Solicitar cambio de grupo')).toBeUndefined()
  })

  it('con el curso sin resolver (sin parámetro ni curso activo) no hay acciones ni exportación', async () => {
    session.__setPermissions(['matricula.leer', 'matricula.actualizar', 'matricula.exportar'])
    vi.mocked(cursoApi.listSelectableAcademicYears).mockResolvedValue({
      data: [year('Y1', '2025-2026', 'cerrado')],
    } as never)

    await open()

    expect(buttonByText('Solicitar cambio de grupo')).toBeUndefined()
    expect(buttonByText('Exportar')).toBeUndefined()
  })

  it('abre el diálogo con la matrícula y enfoca dentro', async () => {
    session.__setPermissions(['matricula.leer', 'matricula.actualizar'])
    vi.mocked(alumApi.createGroupChangeRequest).mockResolvedValue({} as never)

    const { wrapper } = await open()

    await buttonByText('Solicitar cambio de grupo')!.click()
    await flushPromises()

    const dialog = document.body.querySelector<HTMLElement>('[data-slot="alum-group-change-form"]')!

    expect(dialog.getAttribute('role')).toBe('dialog')
    expect(dialog.textContent).toContain('Lucas Ejemplo Ruiz')
    expect(dialog.contains(document.activeElement)).toBe(true)
    expect(acadApi.listGroups).toHaveBeenLastCalledWith({
      academic_year: 'Y2',
      course: ['C1'],
      per_page: 100,
    })
    expect(wrapper.text()).not.toContain('La solicitud de cambio de grupo se ha enviado.')
  })

  it('al enviar la solicitud avisa y recarga la lista', async () => {
    session.__setPermissions(['matricula.leer', 'matricula.actualizar'])
    vi.mocked(alumApi.createGroupChangeRequest).mockResolvedValue({} as never)

    const { wrapper } = await open()
    const loads = vi.mocked(alumApi.listEnrollments).mock.calls.length

    await buttonByText('Solicitar cambio de grupo')!.click()
    await flushPromises()
    await chooseOption('#alum-gcr-to_group', '1º ESO B')
    await fill('#alum-gcr-reason', 'Cambio de itinerario')
    await submitForm('alum-group-change-form')

    expect(alumApi.createGroupChangeRequest).toHaveBeenCalledWith({
      enrollment: 'E1',
      to_group: 'G2',
      reason: 'Cambio de itinerario',
    })
    expect(wrapper.text()).toContain('La solicitud de cambio de grupo se ha enviado.')
    expect(vi.mocked(alumApi.listEnrollments).mock.calls.length).toBeGreaterThan(loads)
  })
})

describe('CA-ALUM-070 (matricula.exportar, INV-012, RN-CORE-46): exportación', () => {
  it('sin matricula.exportar no hay control de exportación', async () => {
    await open()

    expect(buttonByText('Exportar')).toBeUndefined()
  })

  it('con matricula.exportar solicita el CSV con un valor por campo, el curso efectivo y sin q ni orden', async () => {
    session.__setPermissions(['matricula.leer', 'matricula.exportar'])
    vi.mocked(alumApi.exportEnrollments).mockResolvedValue({ public_id: 'X1', status: 'pendiente' })
    vi.mocked(coreApi.getDataExport).mockReturnValue(new Promise(() => undefined))

    await open('/alumnado/listas?course=C1&group=G1&status=activa&sort=-name')
    await buttonByText('Exportar')!.click()
    await flushPromises()

    expect(alumApi.exportEnrollments).toHaveBeenCalledTimes(1)

    const body = vi.mocked(alumApi.exportEnrollments).mock.calls[0]![0]

    expect(body).toEqual({
      academic_year: 'Y2',
      course: 'C1',
      group: 'G1',
      status: 'activa',
    })
    expect(body).not.toHaveProperty('q')
    expect(body).not.toHaveProperty('sort')
  })

  it('con un curso de solo lectura (cerrado) se puede exportar el de la URL', async () => {
    session.__setPermissions(['matricula.leer', 'matricula.exportar'])
    vi.mocked(alumApi.exportEnrollments).mockResolvedValue({ public_id: 'X1', status: 'pendiente' })
    vi.mocked(coreApi.getDataExport).mockReturnValue(new Promise(() => undefined))

    await open('/alumnado/listas?academic_year=Y1')
    await buttonByText('Exportar')!.click()
    await flushPromises()

    expect(vi.mocked(alumApi.exportEnrollments).mock.calls[0]![0]).toEqual({
      academic_year: 'Y1',
      course: undefined,
      group: undefined,
      status: undefined,
    })
  })

  it('consulta el estado con espera y ofrece la descarga firmada al completarse', async () => {
    session.__setPermissions(['matricula.leer', 'matricula.exportar'])
    vi.mocked(alumApi.exportEnrollments).mockResolvedValue({ public_id: 'X1', status: 'pendiente' })
    vi.mocked(coreApi.getDataExport).mockResolvedValue({
      public_id: 'X1',
      status: 'completada',
      download_url: 'https://files.example.com/signed?x=1',
      expires_at: null,
    } as never)

    const { wrapper } = await open()

    vi.useFakeTimers()
    await buttonByText('Exportar')!.click()
    await flushPromises()
    await vi.advanceTimersByTimeAsync(2500)
    await flushPromises()

    expect(coreApi.getDataExport).toHaveBeenCalledWith('X1')
    expect(wrapper.find('a[href="https://files.example.com/signed?x=1"]').exists()).toBe(true)
  })

  it('un 422 de la solicitud se muestra con el mensaje del servidor', async () => {
    session.__setPermissions(['matricula.leer', 'matricula.exportar'])
    vi.mocked(alumApi.exportEnrollments).mockRejectedValue(
      problem(422, undefined, {
        q: [{ code: 'x', message: 'La exportación no admite búsqueda.' }],
      }),
    )

    const { wrapper } = await open()

    await buttonByText('Exportar')!.click()
    await flushPromises()

    expect(wrapper.text()).toContain('La exportación no admite búsqueda.')
  })
})
