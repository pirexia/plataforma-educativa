/**
 * `docs/modulos/REQ-ALUM/funcional.md §4.2 a §4.4, §10` (`1.15-d` T2a), `CA-ALUM-070` (sin acciones de escritura con un
 * curso de solo lectura, `RN-ACAD-42`; la ficha respeta los permisos), `CA-ALUM-071` (etiquetas, errores asociados,
 * foco en diálogos), `RN-ALUM-21`, `RN-ALUM-22`, `RN-ALUM-25`, `RN-ALUM-40`, `RN-ALUM-41`, `INV-002`.
 * Sección «Matrículas» de la ficha: alta, baja, anulación y solicitud de cambio de grupo. `REQ-ALUM-003`.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { setLocale } from '@/i18n'
import * as alumApi from '../api'
import * as acadApi from '@/modules/acad/api'
import * as cursoApi from '@/modules/curso/api'
import * as famApi from '@/modules/fam-unit/api'
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
  student,
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
const { default: StudentDetailView } = await import('./StudentDetailView.vue')

const wrappers: VueWrapper[] = []

const READ = ['alumno.leer', 'matricula.leer']
const WRITE = ['matricula.crear', 'matricula.actualizar', 'matricula.eliminar']

/** Matrícula del curso académico activo (`Y2`), que es el que elige el *shell* sin `?academic_year=`. */
const active = (over: Record<string, unknown> = {}) =>
  enrollment({ academic_year: { public_id: 'Y2', code: '2026-2027' }, ...over })

async function open(url = '/alumnado/S1') {
  const mounted = await mountView('alum-student-detail', StudentDetailView, url)

  wrappers.push(mounted.wrapper)

  return mounted
}

function dialog(slot: string): HTMLElement | null {
  return document.body.querySelector<HTMLElement>(`[data-slot="${slot}"]`)
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
  vi.mocked(alumApi.getStudent).mockResolvedValue(student({ enrollments: [active()] }) as never)
  vi.mocked(acadApi.listCourses).mockResolvedValue(
    page([course(), course({ public_id: 'C2', code: '2ESO', name: 'Segundo de ESO' })]) as never,
  )
  vi.mocked(acadApi.listGroups).mockResolvedValue(
    page([
      { ...group(), course: 'C1' },
      { ...group({ public_id: 'G2', code: '1ESO-B', name: '1º ESO B' }), course: 'C1' },
      { ...group({ public_id: 'G3', code: '2ESO-A', name: '2º ESO A' }), course: 'C2' },
    ]) as never,
  )
  session.__setPermissions([...READ, ...WRITE])
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-ALUM-070 / INV-002: la sección de matrículas depende de sus permisos', () => {
  it('sin matricula.leer no hay sección ni acciones de matrícula', async () => {
    session.__setPermissions(['alumno.leer', ...WRITE])

    const { wrapper } = await open()

    expect(wrapper.find('#alum-enrollments').exists()).toBe(false)
    expect(buttonByText('Matricular')).toBeUndefined()
  })

  it('con solo matricula.leer se listan las matrículas y no hay ninguna acción', async () => {
    session.__setPermissions(READ)

    const { wrapper } = await open()

    expect(wrapper.find('#alum-enrollments').exists()).toBe(true)
    expect(wrapper.text()).toContain('Primero de ESO')
    expect(wrapper.text()).toContain('1º ESO A')
    expect(buttonByText('Matricular')).toBeUndefined()
    expect(buttonByText('Solicitar cambio de grupo')).toBeUndefined()
    expect(buttonByText('Dar de baja la matrícula')).toBeUndefined()
    expect(buttonByText('Anular matrícula')).toBeUndefined()
  })

  it('cada acción sale solo con su permiso', async () => {
    session.__setPermissions([...READ, 'matricula.actualizar'])

    await open()

    expect(buttonByText('Matricular')).toBeUndefined()
    expect(buttonByText('Solicitar cambio de grupo')).toBeDefined()
    expect(buttonByText('Dar de baja la matrícula')).toBeDefined()
    expect(buttonByText('Anular matrícula')).toBeUndefined()
  })

  it('una matrícula en baja no ofrece baja ni cambio de grupo', async () => {
    vi.mocked(alumApi.getStudent).mockResolvedValue(
      student({
        enrollments: [
          active({ status: 'baja', withdrawn_on: '2026-10-01', withdrawal_reason: 'otro' }),
        ],
      }) as never,
    )

    await open()

    expect(buttonByText('Solicitar cambio de grupo')).toBeUndefined()
    expect(buttonByText('Dar de baja la matrícula')).toBeUndefined()
    expect(buttonByText('Anular matrícula')).toBeDefined()
  })
})

describe('CA-ALUM-070 / RN-ACAD-42: curso de solo lectura', () => {
  it('con un curso cerrado no hay ninguna acción de matrícula aunque se tengan los permisos', async () => {
    vi.mocked(alumApi.getStudent).mockResolvedValue(
      student({
        enrollments: [enrollment({ academic_year: { public_id: 'Y1', code: '2025-2026' } })],
      }) as never,
    )

    const { wrapper } = await open('/alumnado/S1?academic_year=Y1')

    expect(wrapper.text()).toContain('Primero de ESO')
    expect(buttonByText('Matricular')).toBeUndefined()
    expect(buttonByText('Solicitar cambio de grupo')).toBeUndefined()
    expect(buttonByText('Dar de baja la matrícula')).toBeUndefined()
    expect(buttonByText('Anular matrícula')).toBeUndefined()
  })

  it('mientras el curso no se ha resuelto no es escribible: ni matrículas ni corrección (year.writable)', async () => {
    session.__setPermissions([...READ, ...WRITE, 'alumno.actualizar', 'alumno.eliminar'])
    // La carga de cursos seleccionables no termina: el curso sigue sin resolverse.
    vi.mocked(cursoApi.listSelectableAcademicYears).mockReturnValue(new Promise(() => undefined))

    const { wrapper } = await open()

    expect(wrapper.text()).toContain('Primero de ESO')
    expect(buttonByText('Matricular')).toBeUndefined()
    expect(buttonByText('Solicitar cambio de grupo')).toBeUndefined()
    expect(buttonByText('Corregir datos')).toBeUndefined()
    expect(buttonByText('Dar de baja')).toBeUndefined()
  })

  it('una matrícula de otro curso académico que el elegido no se toca', async () => {
    vi.mocked(alumApi.getStudent).mockResolvedValue(
      student({
        enrollments: [enrollment({ academic_year: { public_id: 'Y9', code: '2024-2025' } })],
      }) as never,
    )

    await open()

    expect(buttonByText('Anular matrícula')).toBeUndefined()
  })
})

describe('RN-ALUM-21 / RN-ALUM-29: alta de matrícula', () => {
  it('envía curso, grupo del curso y fecha, y recarga la ficha', async () => {
    vi.mocked(alumApi.createEnrollment).mockResolvedValue(active() as never)
    vi.mocked(alumApi.getStudent)
      .mockResolvedValueOnce(student() as never)
      .mockResolvedValue(student({ enrollments: [active()] }) as never)

    const { wrapper } = await open()

    await buttonByText('Matricular')!.click()
    await flushPromises()

    expect(acadApi.listCourses).toHaveBeenCalledWith({ academic_year: 'Y2', per_page: 100 })

    await chooseOption('#alum-enrollment-course', 'Primero de ESO')
    // Solo los grupos del curso elegido.
    await chooseOption('#alum-enrollment-group', '1º ESO B')
    await fill('#alum-enrollment-enrolled_on', '2026-09-10')
    await submitForm('alum-enrollment-form')

    expect(alumApi.createEnrollment).toHaveBeenCalledWith({
      student: 'S1',
      academic_year: 'Y2',
      course: 'C1',
      group: 'G2',
      enrolled_on: '2026-09-10',
    })
    expect(wrapper.text()).toContain('La matrícula se ha creado.')
    expect(wrapper.text()).toContain('Primero de ESO')
    expect(dialog('alum-enrollment-form')).toBeNull()
  })

  it('el grupo es opcional y los grupos de otro curso no se ofrecen', async () => {
    vi.mocked(alumApi.createEnrollment).mockResolvedValue(active() as never)

    await open()
    await buttonByText('Matricular')!.click()
    await flushPromises()
    await chooseOption('#alum-enrollment-course', 'Primero de ESO')

    document.body
      .querySelector<HTMLElement>('#alum-enrollment-group')!
      .dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
    await flushPromises()

    const options = [...document.body.querySelectorAll('[role="option"]')].map((o) =>
      o.textContent?.trim(),
    )

    expect(options).toContain('1º ESO A')
    expect(options).not.toContain('2º ESO A')
  })

  it('CA-ALUM-071: un 422 se pinta junto al campo con aria-describedby y aria-invalid', async () => {
    vi.mocked(alumApi.createEnrollment).mockRejectedValue(
      problem(422, 'Validación', {
        enrolled_on: [
          {
            code: 'alum.validation.date_outside_academic_year',
            message: 'La fecha cae fuera del curso.',
          },
        ],
      }),
    )

    await open()
    await buttonByText('Matricular')!.click()
    await flushPromises()
    await chooseOption('#alum-enrollment-course', 'Primero de ESO')
    await fill('#alum-enrollment-enrolled_on', '2027-12-01')
    await submitForm('alum-enrollment-form')

    const input = document.body.querySelector('#alum-enrollment-enrolled_on')!
    const errors = document.getElementById(input.getAttribute('aria-describedby')!)

    expect(input.getAttribute('aria-invalid')).toBe('true')
    expect(errors?.getAttribute('role')).toBe('alert')
    expect(errors?.textContent).toContain('La fecha cae fuera del curso.')
    expect(dialog('alum-enrollment-form')).not.toBeNull()
  })

  it('un 422 already_enrolled (en el pie) deja el diálogo abierto con el mensaje del servidor', async () => {
    vi.mocked(alumApi.createEnrollment).mockRejectedValue(
      problem(422, 'El alumno ya está matriculado en este curso académico.'),
    )

    await open()
    await buttonByText('Matricular')!.click()
    await flushPromises()
    await chooseOption('#alum-enrollment-course', 'Primero de ESO')
    await fill('#alum-enrollment-enrolled_on', '2026-09-10')
    await submitForm('alum-enrollment-form')

    expect(dialog('alum-enrollment-form')!.textContent).toContain('ya está matriculado')
  })

  it('el diálogo tiene título y descripción accesibles y campos etiquetados', async () => {
    await open()
    await buttonByText('Matricular')!.click()
    await flushPromises()

    const content = document.body.querySelector('[role="dialog"]')!

    expect(content.getAttribute('aria-labelledby')).not.toBeNull()
    expect(content.getAttribute('aria-describedby')).not.toBeNull()

    for (const id of [
      'alum-enrollment-course',
      'alum-enrollment-group',
      'alum-enrollment-enrolled_on',
    ]) {
      expect(document.body.querySelector(`label[for="${id}"]`)).not.toBeNull()
    }
  })
})

describe('RN-ALUM-22: baja de matrícula', () => {
  it('pide fecha y motivo, llama a withdrawal y recarga', async () => {
    vi.mocked(alumApi.withdrawEnrollment).mockResolvedValue(active({ status: 'baja' }) as never)

    const { wrapper } = await open()

    await buttonByText('Dar de baja la matrícula')!.click()
    await flushPromises()

    expect(
      document.body.querySelector<HTMLButtonElement>(
        '[data-slot="alum-withdraw-form"] button[type="submit"]',
      )!.disabled,
    ).toBe(true)

    await fill('#alum-withdraw-withdrawn_on', '2026-10-01')
    await chooseOption('#alum-withdraw-withdrawal_reason', 'Traslado')
    await submitForm('alum-withdraw-form')

    expect(alumApi.withdrawEnrollment).toHaveBeenCalledWith('E1', {
      withdrawn_on: '2026-10-01',
      withdrawal_reason: 'traslado',
    })
    expect(wrapper.text()).toContain('La matrícula se ha dado de baja.')
  })

  it('un 409 already_withdrawn se muestra en el diálogo', async () => {
    vi.mocked(alumApi.withdrawEnrollment).mockRejectedValue(
      problem(409, 'La matrícula ya está dada de baja.'),
    )

    await open()
    await buttonByText('Dar de baja la matrícula')!.click()
    await flushPromises()
    await fill('#alum-withdraw-withdrawn_on', '2026-10-01')
    await chooseOption('#alum-withdraw-withdrawal_reason', 'Otro')
    await submitForm('alum-withdraw-form')

    expect(dialog('alum-withdraw-form')!.textContent).toContain('ya está dada de baja')
  })
})

describe('RN-ALUM-25: anulación de matrícula', () => {
  it('pide confirmación con el curso y solo entonces anula y recarga', async () => {
    vi.mocked(alumApi.deleteEnrollment).mockResolvedValue(null)

    const { wrapper } = await open()

    await buttonByText('Anular matrícula')!.click()
    await flushPromises()

    expect(alumApi.deleteEnrollment).not.toHaveBeenCalled()

    await buttonByText('Anular matrícula de 1ESO')!.click()
    await flushPromises()

    expect(alumApi.deleteEnrollment).toHaveBeenCalledWith('E1')
    expect(wrapper.text()).toContain('La matrícula se ha anulado.')
    expect(alumApi.getStudent).toHaveBeenCalledTimes(2)
  })

  it('un 409 (veto) se muestra junto a la acción', async () => {
    vi.mocked(alumApi.deleteEnrollment).mockRejectedValue(
      problem(409, 'La matrícula tiene dependencias.'),
    )

    const { wrapper } = await open()

    await buttonByText('Anular matrícula')!.click()
    await flushPromises()
    await buttonByText('Anular matrícula de 1ESO')!.click()
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toContain('tiene dependencias')
  })
})

describe('RN-ALUM-40 / RN-ALUM-41: solicitar cambio de grupo desde la ficha', () => {
  it('ofrece solo los grupos del mismo curso, distintos del actual, y envía la solicitud', async () => {
    vi.mocked(alumApi.createGroupChangeRequest).mockResolvedValue({} as never)

    const { wrapper } = await open()

    await buttonByText('Solicitar cambio de grupo')!.click()
    await flushPromises()

    expect(acadApi.listGroups).toHaveBeenLastCalledWith({
      academic_year: 'Y2',
      course: ['C1'],
      per_page: 100,
    })

    const trigger = document.body.querySelector<HTMLElement>('#alum-gcr-to_group')!

    trigger.focus()
    trigger.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
    await flushPromises()

    const options = [...document.body.querySelectorAll('[role="option"]')].map((o) =>
      o.textContent?.trim(),
    )

    expect(options).toEqual(['1º ESO B'])

    document.body
      .querySelector<HTMLElement>('[role="option"]')!
      .dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerType: 'mouse' }))
    document.body.querySelector<HTMLElement>('[role="option"]')!.click()
    await flushPromises()
    await fill('#alum-gcr-reason', '  Cambio de itinerario  ')
    await submitForm('alum-group-change-form')

    expect(alumApi.createGroupChangeRequest).toHaveBeenCalledWith({
      enrollment: 'E1',
      to_group: 'G2',
      reason: 'Cambio de itinerario',
    })
    expect(wrapper.text()).toContain('La solicitud de cambio de grupo se ha enviado.')
    expect(dialog('alum-group-change-form')).toBeNull()
  })

  it('sin grupo de destino o sin motivo no se puede enviar', async () => {
    await open()
    await buttonByText('Solicitar cambio de grupo')!.click()
    await flushPromises()

    const submit = document.body.querySelector<HTMLButtonElement>(
      '[data-slot="alum-group-change-form"] button[type="submit"]',
    )!

    expect(submit.disabled).toBe(true)

    await fill('#alum-gcr-reason', 'Motivo')

    expect(submit.disabled).toBe(true)
  })

  it('CA-ALUM-071: un 422 group_not_in_course va junto al campo del grupo', async () => {
    vi.mocked(alumApi.createGroupChangeRequest).mockRejectedValue(
      problem(422, 'Validación', {
        to_group: [
          {
            code: 'alum.validation.group_not_in_course',
            message: 'El grupo no es del mismo curso.',
          },
        ],
      }),
    )

    await open()
    await buttonByText('Solicitar cambio de grupo')!.click()
    await flushPromises()
    await chooseOption('#alum-gcr-to_group', '1º ESO B')
    await fill('#alum-gcr-reason', 'Motivo')
    await submitForm('alum-group-change-form')

    const trigger = document.body.querySelector('#alum-gcr-to_group')!
    const ids = trigger.getAttribute('aria-describedby')!.split(' ')
    const errors = document.getElementById('alum-gcr-to_group-errors')

    expect(trigger.getAttribute('aria-invalid')).toBe('true')
    expect(ids).toContain('alum-gcr-to_group-errors')
    expect(errors?.textContent).toContain('no es del mismo curso')
  })

  it('un 422 request_already_pending (en la matrícula) y un 403 salen en el pie del diálogo', async () => {
    vi.mocked(alumApi.createGroupChangeRequest).mockRejectedValue(
      problem(422, 'Validación', {
        enrollment: [
          {
            code: 'alum.validation.request_already_pending',
            message: 'Ya hay una solicitud pendiente.',
          },
        ],
      }),
    )

    await open()
    await buttonByText('Solicitar cambio de grupo')!.click()
    await flushPromises()
    await chooseOption('#alum-gcr-to_group', '1º ESO B')
    await fill('#alum-gcr-reason', 'Motivo')
    await submitForm('alum-group-change-form')

    expect(dialog('alum-group-change-form')!.textContent).toContain(
      'Ya hay una solicitud pendiente.',
    )

    vi.mocked(alumApi.createGroupChangeRequest).mockRejectedValue(problem(403, 'No tiene permiso.'))
    await submitForm('alum-group-change-form')

    expect(dialog('alum-group-change-form')!.textContent).toContain('No tiene permiso.')
  })

  it('un 409 se muestra con el mensaje del servidor', async () => {
    vi.mocked(alumApi.createGroupChangeRequest).mockRejectedValue(
      problem(409, 'La matrícula ha cambiado.'),
    )

    await open()
    await buttonByText('Solicitar cambio de grupo')!.click()
    await flushPromises()
    await chooseOption('#alum-gcr-to_group', '1º ESO B')
    await fill('#alum-gcr-reason', 'Motivo')
    await submitForm('alum-group-change-form')

    expect(dialog('alum-group-change-form')!.textContent).toContain('La matrícula ha cambiado.')
  })
})
