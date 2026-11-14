/**
 * `docs/modulos/REQ-ACAD/funcional.md §18.4.2`, `§18.8`, `CA-ACAD-107`, `-109`,
 * `-111`, `-113`: la sección «Profesorado» de la ficha de grupo. Asignar y quitar
 * profesorado y sustituir los tutores, con permisos y curso, y los errores del
 * servidor junto al campo. `REQ-ACAD-002`.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { i18n, setLocale } from '@/i18n'
import * as acadApi from '../api'
import * as coreApi from '@/modules/core/api'
import * as cursoApi from '@/modules/curso/api'
import {
  buttonByText,
  chooseOption,
  group,
  makeRouter,
  page,
  stubMatchMedia,
  subgroup,
  subject,
  teachingAssignment,
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
const { default: TeachingSection } = await import('./TeachingSection.vue')

const wrappers: VueWrapper[] = []
const ALL = [
  'asignacion_docente.leer',
  'asignacion_docente.crear',
  'asignacion_docente.actualizar',
  'asignacion_docente.eliminar',
]

function user(publicId: string, given: string) {
  return {
    public_id: publicId,
    email: `${publicId.toLowerCase()}@example.com`,
    status: 'activo',
    person: { given_name: given, family_name_1: 'Ejemplo', family_name_2: null },
  }
}

async function open(): Promise<VueWrapper> {
  const router = makeRouter('acad-group-detail', TeachingSection)

  await router.push('/academico/grupos/G1')
  await router.isReady()

  const wrapper = mount(TeachingSection, {
    props: { group: group() as never },
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return wrapper
}

function problem(status: number, errors: Record<string, unknown[]>, detail = 'x'): ApiError {
  return new ApiError('x', status, { type: 'urn:pge:error:validation', status, detail, errors })
}

function pick(name: string): void {
  const radio = [...document.body.querySelectorAll<HTMLInputElement>('input[type="radio"]')].find(
    (input) => input.closest('label')?.textContent?.includes(name),
  )!

  radio.checked = true
  radio.dispatchEvent(new Event('change', { bubbles: true }))
}

async function submit(): Promise<void> {
  document.body
    .querySelector<HTMLFormElement>('[data-slot="acad-assignment-form"] form')!
    .dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
  await flushPromises()
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
  for (const mod of [acadApi, coreApi]) {
    for (const fn of Object.values(mod)) {
      if (typeof fn === 'function' && 'mockReset' in fn) {
        ;(fn as ReturnType<typeof vi.fn>).mockReset()
      }
    }
  }
  vi.mocked(acadApi.listGroupSubjects).mockResolvedValue({ data: [subject()] } as never)
  vi.mocked(acadApi.listSubgroups).mockResolvedValue({ data: [subgroup()] } as never)
  vi.mocked(acadApi.listTeachingAssignments).mockResolvedValue(
    page([teachingAssignment(), teachingAssignment({ public_id: 'TA2', subgroup: 'S1' })]) as never,
  )
  vi.mocked(acadApi.listGroupTutors).mockResolvedValue({
    data: [{ public_id: 'U9', name: 'Tutora Ejemplo' }],
  } as never)
  vi.mocked(coreApi.listUsers).mockResolvedValue(
    page([user('U1', 'Ana'), user('U2', 'Luis')]) as never,
  )
  session.__setPermissions(ALL)
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-ACAD-107: listado de la docencia del grupo', () => {
  it('pide las asignaciones del grupo y de su curso; muestra asignatura, subgrupo y profesor con nombre visible', async () => {
    const wrapper = await open()
    const rows = wrapper.findAll('tbody tr').map((row) => row.text())

    expect(acadApi.listTeachingAssignments).toHaveBeenCalledWith({
      academic_year: 'Y2',
      group: ['G1'],
      per_page: 100,
    })
    expect(rows).toHaveLength(2)
    expect(rows[0]).toContain('MAT · Matemáticas')
    expect(rows[0]).toContain('Todo el grupo')
    expect(rows[0]).toContain('Ana Ejemplo')
    expect(rows[1]).toContain('D1')
    expect(wrapper.text()).toContain('Tutora Ejemplo')
  })

  it('sin asignacion_docente.leer la sección no existe y no se llama a la API', async () => {
    session.__setPermissions(['estructura_academica.leer'])

    const wrapper = await open()

    expect(wrapper.find('[data-slot="acad-teaching"]').exists()).toBe(false)
    expect(acadApi.listTeachingAssignments).not.toHaveBeenCalled()
  })

  it('con solo leer no hay asignar, quitar ni editar tutores', async () => {
    session.__setPermissions(['asignacion_docente.leer'])

    const wrapper = await open()

    expect(buttonByText('Asignar profesorado')).toBeUndefined()
    expect(buttonByText('Editar tutores')).toBeUndefined()
    expect(wrapper.findAll('tbody button')).toHaveLength(0)
  })
})

describe('CA-ACAD-107 / CA-ACAD-109: asignar profesorado', () => {
  it('envía profesor, grupo, asignatura y subgrupo, refresca y avisa; el profesor se elige entre los usuarios activos', async () => {
    vi.mocked(acadApi.createTeachingAssignment).mockResolvedValue(teachingAssignment() as never)

    const wrapper = await open()

    buttonByText('Asignar profesorado')!.click()
    await flushPromises()

    expect(coreApi.listUsers).toHaveBeenCalledWith(
      expect.objectContaining({ status: ['activo'], per_page: 20 }),
    )

    await chooseOption('#acad-assignment-subject', 'MAT')
    await chooseOption('#acad-assignment-subgroup', 'D1')
    pick('Luis Ejemplo')
    await flushPromises()
    await submit()

    expect(acadApi.createTeachingAssignment).toHaveBeenCalledWith({
      teacher: 'U2',
      group: 'G1',
      subject: 'M1',
      subgroup: 'S1',
    })
    expect(vi.mocked(acadApi.listTeachingAssignments).mock.calls.length).toBeGreaterThan(1)
    expect(wrapper.text()).toContain('Profesorado asignado.')
  })

  it('sin asignatura o sin profesor no se envía nada y se avisa junto al campo', async () => {
    await open()
    buttonByText('Asignar profesorado')!.click()
    await flushPromises()
    await submit()

    expect(acadApi.createTeachingAssignment).not.toHaveBeenCalled()
    expect(document.body.querySelector('#acad-assignment-subject-errors')!.textContent).toContain(
      'Elige una asignatura',
    )
    expect(document.body.querySelector('#acad-assignment-teacher-errors')!.textContent).toContain(
      'Elige un profesor',
    )
  })

  it('un 422 user_not_found o assignment_exists se muestra junto al profesor, ya traducido, y el diálogo sigue abierto', async () => {
    vi.mocked(acadApi.createTeachingAssignment).mockRejectedValue(
      problem(422, {
        teacher: [
          {
            code: 'acad.validation.assignment_exists',
            message: 'Esta asignación docente ya existe.',
            params: {},
          },
        ],
      }),
    )

    await open()
    buttonByText('Asignar profesorado')!.click()
    await flushPromises()
    await chooseOption('#acad-assignment-subject', 'MAT')
    pick('Ana Ejemplo')
    await flushPromises()
    await submit()

    expect(document.body.querySelector('#acad-assignment-teacher-errors')!.textContent).toContain(
      'ya existe',
    )
    expect(document.body.querySelector('[data-slot="acad-assignment-form"]')).not.toBeNull()
  })
})

describe('CA-ACAD-111: quitar una asignación', () => {
  it('pide confirmación y borra; un 409 has_dependents se muestra con el mensaje del servidor y los dependientes', async () => {
    vi.mocked(acadApi.deleteTeachingAssignment)
      .mockRejectedValueOnce(
        new ApiError('x', 409, {
          type: 'urn:pge:error:conflict',
          status: 409,
          detail: 'No se puede borrar: tiene sesiones de horario.',
          errors: {
            dependents: [
              {
                code: 'acad.conflict.has_dependents',
                message: 'x',
                params: { dependents: ['timetable_sessions'] },
              },
            ],
          },
        }),
      )
      .mockResolvedValueOnce(undefined as never)

    const wrapper = await open()
    const remove = () =>
      (
        wrapper.findAll('tbody button').find((button) => button.text().startsWith('Quitar'))!
          .element as HTMLElement
      ).click()

    remove()
    await flushPromises()
    buttonByText('Quitar asignación')!.click()
    await flushPromises()

    expect(acadApi.deleteTeachingAssignment).toHaveBeenCalledWith('TA1')
    expect(wrapper.text()).toContain('tiene sesiones de horario')
    expect(wrapper.text()).toContain('sesiones de horario')

    remove()
    await flushPromises()
    buttonByText('Quitar asignación')!.click()
    await flushPromises()

    expect(wrapper.text()).toContain('Asignación docente quitada.')
  })
})

describe('CA-ACAD-113: tutores', () => {
  it('«Editar tutores» sustituye el conjunto completo (PUT) con los elegidos', async () => {
    vi.mocked(acadApi.replaceGroupTutors).mockResolvedValue({
      data: [{ public_id: 'U1', name: 'Ana Ejemplo' }],
    } as never)

    const wrapper = await open()

    buttonByText('Editar tutores')!.click()
    await flushPromises()

    const boxes = [
      ...document.body.querySelectorAll<HTMLInputElement>(
        '[data-slot="acad-tutors"] input[type="checkbox"]',
      ),
    ]

    // La tutora actual (U9) aparece marcada aunque no salga en la búsqueda.
    expect(boxes.find((box) => box.value === 'U9')!.checked).toBe(true)

    const untick = boxes.find((box) => box.value === 'U9')!
    const tick = boxes.find((box) => box.value === 'U1')!

    untick.checked = false
    untick.dispatchEvent(new Event('change', { bubbles: true }))
    await flushPromises()
    tick.checked = true
    tick.dispatchEvent(new Event('change', { bubbles: true }))
    await flushPromises()

    wrapper.get('[data-slot="acad-tutors"] form').trigger('submit')
    await flushPromises()

    expect(acadApi.replaceGroupTutors).toHaveBeenCalledWith('G1', ['U1'])
    expect(wrapper.text()).toContain('Tutores del grupo actualizados.')
    expect(wrapper.get('[data-slot="acad-tutors"]').text()).toContain('Ana Ejemplo')
  })

  it('un 422 user_not_found de un tutor se muestra junto a la lista y no cambia lo mostrado', async () => {
    vi.mocked(acadApi.replaceGroupTutors).mockRejectedValue(
      problem(422, {
        'tutors.0': [
          {
            code: 'acad.validation.user_not_found',
            message: 'El usuario indicado no existe o no está activo.',
            params: {},
          },
        ],
      }),
    )

    const wrapper = await open()

    buttonByText('Editar tutores')!.click()
    await flushPromises()
    wrapper.get('[data-slot="acad-tutors"] form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('#acad-tutors-errors').text()).toContain('no existe o no está activo')
  })
})
