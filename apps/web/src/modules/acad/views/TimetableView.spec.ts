/**
 * `docs/modulos/REQ-ACAD/funcional.md §18.3`, `§18.8`, `CA-ACAD-122` a `-127`,
 * `CA-ACAD-134`: la rejilla de `/academico/horarios`. La vista y la entidad viven
 * en la URL; cada vista pide `GET /timetable-sessions` con su filtro
 * (`RN-ACAD-127`); la creación parte de una celda vacía de la vista de grupo; un
 * `422 timetable_conflict` se muestra como texto junto a la acción nombrando la
 * sesión con la que choca; las acciones dependen del permiso y del curso.
 * `REQ-ACAD-002`.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { setLocale } from '@/i18n'
import * as acadApi from '../api'
import * as cursoApi from '@/modules/curso/api'
import {
  buttonByText,
  chooseOption,
  course,
  facility,
  group,
  mountView,
  page,
  slot,
  stubMatchMedia,
  teachingAssignment,
  timetableSession,
  year,
} from '../testSupport'

vi.mock('../api')
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
const { default: TimetableView } = await import('./TimetableView.vue')

const wrappers: VueWrapper[] = []
const WRITER = ['horarios.leer', 'horarios.crear', 'horarios.actualizar', 'horarios.eliminar']
const URL = '/academico/horarios?view=group&id=G1'

const FRAME = {
  public_id: 'FR1',
  academic_year: 'Y2',
  education_level: 'L1',
  frame_type: 'ordinaria',
  name: 'Horario general',
  name_translations: { 'es-ES': 'Horario general' },
  valid_from: null,
  valid_to: null,
  slots: [slot(), slot({ public_id: 'T2', weekday: 2 })],
  created_at: '2026-06-01T10:00:00Z',
  updated_at: '2026-06-01T10:00:00Z',
}

async function open(url = URL): Promise<VueWrapper> {
  const wrapper = await mountView('acad-timetable', TimetableView, url)

  wrappers.push(wrapper)

  return wrapper
}

function problem(status: number, errors: Record<string, unknown[]>, detail = 'x'): ApiError {
  return new ApiError('x', status, { type: 'urn:pge:error:validation', status, detail, errors })
}

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  Element.prototype.scrollIntoView = () => undefined
  window.localStorage.clear()
  resetSelectableYears()
  vi.mocked(cursoApi.listSelectableAcademicYears)
    .mockReset()
    .mockResolvedValue({ data: [year('Y2', '2026-2027', 'activo')] })
  for (const fn of Object.values(acadApi)) {
    if (typeof fn === 'function' && 'mockReset' in fn) (fn as ReturnType<typeof vi.fn>).mockReset()
  }
  vi.mocked(acadApi.listGroups).mockResolvedValue(page([group()]) as never)
  vi.mocked(acadApi.getGroup).mockResolvedValue(group() as never)
  vi.mocked(acadApi.getCourse).mockResolvedValue(course() as never)
  vi.mocked(acadApi.listTimetableFrames).mockResolvedValue(page([FRAME]) as never)
  vi.mocked(acadApi.listTimetableSessions).mockResolvedValue({
    data: [timetableSession()],
  } as never)
  vi.mocked(acadApi.listFacilities).mockResolvedValue(page([facility()]) as never)
  vi.mocked(acadApi.listTeachingAssignments).mockResolvedValue(
    page([teachingAssignment()]) as never,
  )
  vi.mocked(acadApi.listGroupSubjects).mockResolvedValue({
    data: [{ public_id: 'M1', code: 'MAT', name: 'Matemáticas' }],
  } as never)
  vi.mocked(acadApi.listSubgroups).mockResolvedValue({ data: [] } as never)
  session.__setPermissions(WRITER)
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-ACAD-127 / CA-ACAD-134: la vista y su filtro viven en la URL', () => {
  it('la vista de grupo pide las sesiones con academic_year y group, y las franjas del marco ordinario de su etapa', async () => {
    const wrapper = await open()

    expect(acadApi.listTimetableSessions).toHaveBeenCalledWith({ academic_year: 'Y2', group: 'G1' })
    expect(acadApi.listTimetableFrames).toHaveBeenCalledWith({
      academic_year: 'Y2',
      education_level: ['L1'],
      per_page: 100,
    })
    expect(wrapper.get('[role="table"]').text()).toContain('Matemáticas')
    expect(wrapper.get('[role="table"]').text()).toContain('Ana Ejemplo')
    expect(wrapper.get('[role="table"]').text()).toContain('A21')
  })

  it.each([
    ['facility', 'F1', { facility: 'F1' }],
    ['subject', 'M1', { subject: 'M1' }],
    ['teacher', 'U1', { teacher: 'U1' }],
  ])('la vista %s pide solo su filtro y no permite crear', async (view, id, filter) => {
    vi.mocked(acadApi.listSubjects).mockResolvedValue(page([]) as never)

    const wrapper = await open(`/academico/horarios?view=${view}&id=${id}`)

    expect(acadApi.listTimetableSessions).toHaveBeenCalledWith({ academic_year: 'Y2', ...filter })
    // No hay marco que componer; solo se piden las jornadas especiales para rotular sus sesiones (RN-ACAD-124).
    expect(acadApi.listTimetableFrames).toHaveBeenCalledTimes(1)
    expect(acadApi.listTimetableFrames).toHaveBeenCalledWith({
      academic_year: 'Y2',
      frame_type: ['especial'],
      per_page: 100,
    })
    expect(wrapper.get('[role="table"]').text()).toContain('Matemáticas')
    expect(buttonByText('Añadir sesión')).toBeUndefined()
    // Editar sí: la sesión existe y hay horarios.actualizar.
    expect(buttonByText('Editar')).toBeDefined()
  })

  it('sin entidad elegida se pide que se elija y no se llama a la API de sesiones', async () => {
    const wrapper = await open('/academico/horarios')

    expect(wrapper.text()).toContain('Elige una opción para ver su horario.')
    expect(acadApi.listTimetableSessions).not.toHaveBeenCalled()
  })

  it('una etapa sin marco horario se avisa con texto', async () => {
    vi.mocked(acadApi.listTimetableFrames).mockResolvedValue(page([]) as never)

    const wrapper = await open()

    expect(wrapper.get('[data-slot="acad-timetable-no-frame"]').text()).toContain(
      'no tiene marco horario',
    )
  })
})

describe('CA-ACAD-122: crear una sesión desde una celda vacía', () => {
  it('«Añadir sesión» abre el diálogo con las asignaciones del grupo; envía franja, asignación y aula', async () => {
    vi.mocked(acadApi.createTimetableSession).mockResolvedValue(timetableSession() as never)

    const wrapper = await open()

    expect(wrapper.text()).not.toContain('Nueva sesión')
    buttonByText('Añadir sesión')!.click()
    await flushPromises()

    expect(document.body.textContent).toContain('Nueva sesión')

    await chooseOption(
      '#acad-session-assignment',
      'MAT · Matemáticas · Todo el grupo · Ana Ejemplo',
    )
    await chooseOption('#acad-session-facility', 'A21')
    document.body
      .querySelector<HTMLFormElement>('[data-slot="acad-session-form"] form')!
      .dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
    await flushPromises()

    expect(acadApi.createTimetableSession).toHaveBeenCalledWith({
      time_slot: 'T2',
      teaching_assignment: 'TA1',
      facility: 'F1',
    })
    expect(wrapper.text()).toContain('Sesión creada.')
  })

  it('CA-ACAD-123: un 422 timetable_conflict se muestra como texto junto a la acción, nombrando la sesión con la que choca', async () => {
    vi.mocked(acadApi.createTimetableSession).mockRejectedValue(
      problem(422, {
        time_slot: [
          {
            code: 'acad.validation.timetable_conflict',
            message: 'La sesión coincide en el horario con otra del mismo profesor, aula o grupo.',
            params: { conflicts: [{ kind: 'teacher', session: 'SE9' }] },
          },
        ],
      }),
    )
    vi.mocked(acadApi.getTimetableSession).mockResolvedValue(
      timetableSession({
        public_id: 'SE9',
        group: { public_id: 'G9', code: 'B', name: '2.º ESO B' },
        slot: slot({ weekday: 2 }),
      }) as never,
    )

    await open()
    buttonByText('Añadir sesión')!.click()
    await flushPromises()
    await chooseOption('#acad-session-assignment', 'Ana Ejemplo')
    document.body
      .querySelector<HTMLFormElement>('[data-slot="acad-session-form"] form')!
      .dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
    await flushPromises()

    const box = document.body.querySelector('[data-slot="acad-session-conflicts"]')!

    expect(box.getAttribute('role')).toBe('alert')
    expect(box.textContent).toContain('La sesión choca con otra')
    expect(box.textContent).toContain(
      'Profesor: Matemáticas · B · Ana Ejemplo · Martes 08:30–09:25',
    )
    expect(acadApi.getTimetableSession).toHaveBeenCalledWith('SE9')
    expect(document.body.querySelector('[data-slot="acad-session-form"]')).not.toBeNull()
  })
})

describe('CA-ACAD-125: editar y quitar una sesión', () => {
  it('«Editar» ofrece las franjas del marco y el aula; solo se envía lo modificado', async () => {
    vi.mocked(acadApi.getTimetableFrame).mockResolvedValue(FRAME as never)
    vi.mocked(acadApi.updateTimetableSession).mockResolvedValue(timetableSession() as never)

    const wrapper = await open()

    buttonByText('Editar')!.click()
    await flushPromises()

    expect(acadApi.getTimetableFrame).toHaveBeenCalledWith('FR1')

    await chooseOption('#acad-session-slot', 'Martes')
    document.body
      .querySelector<HTMLFormElement>('[data-slot="acad-session-form"] form')!
      .dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
    await flushPromises()

    expect(acadApi.updateTimetableSession).toHaveBeenCalledWith('SE1', { time_slot: 'T2' })
    expect(wrapper.text()).toContain('Sesión actualizada.')
  })

  it('«Quitar sesión» pide confirmación y borra', async () => {
    vi.mocked(acadApi.getTimetableFrame).mockResolvedValue(FRAME as never)
    vi.mocked(acadApi.deleteTimetableSession).mockResolvedValue(undefined as never)

    const wrapper = await open()

    buttonByText('Editar')!.click()
    await flushPromises()
    buttonByText('Quitar sesión')!.click()
    await flushPromises()

    expect(acadApi.deleteTimetableSession).not.toHaveBeenCalled()

    // Botón de confirmación del ConfirmDialog.
    buttonByText('Quitar sesión')!.click()
    await flushPromises()

    expect(acadApi.deleteTimetableSession).toHaveBeenCalledWith('SE1')
    expect(wrapper.text()).toContain('Sesión borrada.')
  })
})

describe('CA-ACAD-134: acciones por permiso y por curso', () => {
  it('con solo horarios.leer la rejilla es de lectura: sin «Añadir sesión» ni «Editar»', async () => {
    session.__setPermissions(['horarios.leer'])

    const wrapper = await open()

    expect(wrapper.get('[role="table"]').text()).toContain('Matemáticas')
    expect(buttonByText('Añadir sesión')).toBeUndefined()
    expect(buttonByText('Editar')).toBeUndefined()
  })

  it('con horarios.crear y sin actualizar se puede añadir pero no editar', async () => {
    session.__setPermissions(['horarios.leer', 'horarios.crear'])

    await open()

    expect(buttonByText('Añadir sesión')).toBeDefined()
    expect(buttonByText('Editar')).toBeUndefined()
  })

  it('con el curso cerrado hay aviso persistente de texto y ninguna acción de escritura (RN-ACAD-42)', async () => {
    vi.mocked(cursoApi.listSelectableAcademicYears).mockResolvedValue({
      data: [year('Y2', '2025-2026', 'cerrado')],
    })

    const wrapper = await open(`${URL}&academic_year=Y2`)

    expect(wrapper.get('[data-slot="acad-read-only"]').text()).toContain('solo lectura')
    expect(buttonByText('Añadir sesión')).toBeUndefined()
    expect(buttonByText('Editar')).toBeUndefined()
  })
})

/** `RN-ACAD-111` (1.12-b): jornada intensiva de la etapa del 7 al 11 de diciembre, con su propia franja. */
const SPECIAL = {
  ...FRAME,
  public_id: 'FR2',
  frame_type: 'especial',
  name: 'Jornada intensiva',
  name_translations: { 'es-ES': 'Jornada intensiva' },
  valid_from: '2026-12-07',
  valid_to: '2026-12-11',
  slots: [slot({ public_id: 'T9', starts_at: '10:00', ends_at: '10:55' })],
}

describe('CA-ACAD-136: jornadas especiales en la rejilla', () => {
  beforeEach(() => {
    vi.mocked(acadApi.listTimetableFrames).mockResolvedValue(page([FRAME, SPECIAL]) as never)
    vi.mocked(acadApi.listTimetableSessions).mockResolvedValue({
      data: [
        timetableSession(),
        timetableSession({
          public_id: 'SE2',
          frame: 'FR2',
          slot: slot({ public_id: 'T9', starts_at: '10:00', ends_at: '10:55' }),
          subject: { public_id: 'M2', code: 'LEN', name: 'Lengua' },
        }),
      ],
    } as never)
  })

  it('con más de un marco aparece el selector «Marco horario» (marco, vigencia en texto); por defecto, el ordinario y solo sus sesiones', async () => {
    const wrapper = await open()

    expect(wrapper.get('[data-slot="acad-timetable-frame-select"]').text()).toContain(
      'Marco horario',
    )
    expect(wrapper.get('[role="table"]').text()).toContain('Matemáticas')
    expect(wrapper.get('[role="table"]').text()).not.toContain('Lengua')
    expect(wrapper.findAll('[role="rowheader"]').map((node) => node.text())).toEqual([
      '08:30–09:25',
    ])
  })

  it('?frame= elige la jornada especial: sus franjas y solo sus sesiones, y se puede crear en ella', async () => {
    const wrapper = await open(`${URL}&frame=FR2`)

    expect(wrapper.get('[role="table"]').text()).toContain('Lengua')
    expect(wrapper.get('[role="table"]').text()).not.toContain('Matemáticas')
    expect(wrapper.findAll('[role="rowheader"]').map((node) => node.text())).toEqual([
      '10:00–10:55',
    ])
    expect(wrapper.get('[role="table"]').text()).toContain('Jornada intensiva ·')
  })

  it('con un solo marco no hay selector', async () => {
    vi.mocked(acadApi.listTimetableFrames).mockResolvedValue(page([FRAME]) as never)

    const wrapper = await open()

    expect(wrapper.find('[data-slot="acad-timetable-frame-select"]').exists()).toBe(false)
  })

  it('en la vista de profesor, la sesión de una jornada especial lleva su vigencia en texto junto a la del ordinario', async () => {
    vi.mocked(acadApi.listTimetableFrames).mockResolvedValue(page([SPECIAL]) as never)
    vi.mocked(acadApi.listSubjects).mockResolvedValue(page([]) as never)

    const wrapper = await open('/academico/horarios?view=subject&id=M1')
    const text = wrapper.get('[role="table"]').text()

    expect(text).toContain('Matemáticas')
    expect(text).toContain('Lengua')
    expect(text).toContain('Jornada intensiva · Del')
    expect(text.match(/Jornada intensiva/g)).toHaveLength(1)
  })
})
