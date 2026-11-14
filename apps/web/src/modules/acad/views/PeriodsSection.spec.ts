/**
 * `docs/modulos/REQ-ACAD/funcional.md §4.8`, `§10.5`, `§13.16` (1.11d-a, parte
 * web): sección «Períodos lectivos» de la ficha de etapa — `CA-ACAD-098` (la
 * sección aparece solo con `configuracion_academica.leer`; lo de las escalas es
 * de 1.11d-b), `CA-ACAD-099` (acciones por permiso y curso escribible, estados,
 * `422` junto a su campo, solape con nombre), `CA-ACAD-100` (etiquetas de
 * `period_type`, fallback al código en crudo) y `CA-ACAD-103` (la petición lleva
 * siempre `academic_year` y `education_level`). `REQ-ACAD-001`.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { setLocale } from '@/i18n'
import * as acadApi from '../api'
import * as cursoApi from '@/modules/curso/api'
import {
  buttonByText,
  fill,
  level,
  mountView,
  page,
  period,
  stubMatchMedia,
  submitForm,
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

const wrappers: VueWrapper[] = []

const STRUCTURE = ['estructura_academica.leer']
const CONFIG_ALL = [
  'configuracion_academica.leer',
  'configuracion_academica.crear',
  'configuracion_academica.actualizar',
  'configuracion_academica.eliminar',
]

async function open(): Promise<VueWrapper> {
  const wrapper = await mountView('acad-level-detail', LevelDetailView, '/academico/etapas/L1')

  wrappers.push(wrapper)

  return wrapper
}

function rowButton(label: string): HTMLButtonElement | undefined {
  return (
    document.body.querySelector<HTMLButtonElement>(`button[aria-label="${label}"]`) ?? undefined
  )
}

function problem(status: number, errors: Record<string, unknown[]>, detail = 'x'): ApiError {
  return new ApiError('x', status, {
    type: 'urn:pge:error:validation',
    status,
    detail,
    errors,
  })
}

/** Abre el `Select` de Reka UI (teclado: no necesita captura de puntero) y elige la opción por su texto. */
async function chooseType(label: string): Promise<void> {
  const trigger = document.body.querySelector<HTMLElement>('#acad-period-type')!

  trigger.focus()
  trigger.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
  await flushPromises()

  const option = [...document.body.querySelectorAll<HTMLElement>('[role="option"]')].find(
    (item) => item.textContent?.trim() === label,
  )

  if (!option) {
    throw new Error(`No hay opción ${label}`)
  }

  option.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerType: 'mouse' }))
  option.click()
  await flushPromises()
}

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  // jsdom no implementa `scrollIntoView` (lo usa el `Select` de Reka UI al abrirse).
  Element.prototype.scrollIntoView = () => undefined
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
  for (const fn of Object.values(acadApi)) {
    if (typeof fn === 'function' && 'mockReset' in fn) (fn as ReturnType<typeof vi.fn>).mockReset()
  }
  vi.mocked(acadApi.getEducationLevel).mockResolvedValue(level() as never)
  vi.mocked(acadApi.listCourses).mockResolvedValue(page([]) as never)
  vi.mocked(acadApi.listAcademicPeriods).mockResolvedValue(
    page([
      period(),
      period({
        public_id: 'P2',
        position: 2,
        name: 'Segundo trimestre',
        name_translations: { 'es-ES': 'Segundo trimestre' },
        starts_on: '2026-01-08',
        ends_on: '2026-03-20',
      }),
    ]) as never,
  )
  session.__setPermissions([...STRUCTURE, ...CONFIG_ALL])
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-ACAD-098: la sección «Períodos lectivos» aparece solo con configuracion_academica.leer', () => {
  it('con el permiso: título, períodos de la etapa y sus datos', async () => {
    const wrapper = await open()
    const section = wrapper.find('[data-slot="acad-periods"]')

    expect(section.exists()).toBe(true)
    expect(section.find('h2').text()).toBe('Períodos lectivos')
    expect(section.text()).toContain('Primer trimestre')
    expect(section.text()).toContain('Segundo trimestre')
    expect(section.text()).toContain('Trimestre')
    expect(section.text()).toContain('8 sept 2025')
  })

  it('sin configuracion_academica.leer: ni sección ni petición', async () => {
    session.__setPermissions([...STRUCTURE, 'configuracion_academica.crear'])

    const wrapper = await open()

    expect(wrapper.find('[data-slot="acad-periods"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Períodos lectivos')
    expect(acadApi.listAcademicPeriods).not.toHaveBeenCalled()
  })
})

describe('CA-ACAD-103: la petición lleva siempre academic_year y education_level de la etapa', () => {
  it('una etapa en planificación mientras otro curso es el activo: pide el curso de la etapa', async () => {
    vi.mocked(acadApi.getEducationLevel).mockResolvedValue(level({ academic_year: 'Y3' }) as never)
    vi.mocked(acadApi.listAcademicPeriods).mockResolvedValue(
      page([period({ academic_year: 'Y3' })]) as never,
    )

    const wrapper = await open()
    const params = vi.mocked(acadApi.listAcademicPeriods).mock.calls[0]![0]!

    expect(params).toMatchObject({
      academic_year: 'Y3',
      education_level: ['L1'],
      per_page: 100,
    })
    expect(wrapper.find('[data-slot="acad-periods"]').text()).toContain('Primer trimestre')
  })

  it('una etapa de un curso cerrado: la misma petición, sección en solo lectura sin acciones', async () => {
    vi.mocked(acadApi.getEducationLevel).mockResolvedValue(level({ academic_year: 'Y1' }) as never)

    const wrapper = await open()

    expect(vi.mocked(acadApi.listAcademicPeriods).mock.calls[0]![0]).toMatchObject({
      academic_year: 'Y1',
      education_level: ['L1'],
    })
    expect(wrapper.find('[data-slot="acad-periods"]').text()).toContain('Primer trimestre')
    expect(buttonByText('Nuevo período')).toBeUndefined()
    expect(rowButton('Editar el período Primer trimestre')).toBeUndefined()
    expect(rowButton('Borrar el período Primer trimestre')).toBeUndefined()
  })

  it('un centro sin curso activo: una etapa en planificación carga igual, sin estado «sin curso activo»', async () => {
    vi.mocked(cursoApi.listSelectableAcademicYears).mockResolvedValue({
      data: [year('Y3', '2026-2027', 'planificacion')],
    } as never)
    vi.mocked(acadApi.getEducationLevel).mockResolvedValue(level({ academic_year: 'Y3' }) as never)

    const wrapper = await open()

    expect(vi.mocked(acadApi.listAcademicPeriods).mock.calls[0]![0]).toMatchObject({
      academic_year: 'Y3',
    })
    expect(wrapper.text()).not.toContain('No hay un curso académico activo')
    expect(wrapper.find('[data-slot="acad-periods"]').text()).toContain('Primer trimestre')
  })
})

describe('CA-ACAD-099: acciones por permiso y curso escribible; estados', () => {
  it('con los cuatro permisos y un curso activo: Nuevo período, Editar y Borrar por fila', async () => {
    await open()

    expect(buttonByText('Nuevo período')).toBeDefined()
    expect(rowButton('Editar el período Primer trimestre')).toBeDefined()
    expect(rowButton('Borrar el período Primer trimestre')).toBeDefined()
  })

  it.each([
    ['configuracion_academica.crear', true, false, false],
    ['configuracion_academica.actualizar', false, true, false],
    ['configuracion_academica.eliminar', false, false, true],
  ])('solo %s: únicamente su acción', async (permission, create, edit, remove) => {
    session.__setPermissions([...STRUCTURE, 'configuracion_academica.leer', permission])

    await open()

    expect(buttonByText('Nuevo período') !== undefined).toBe(create)
    expect(rowButton('Editar el período Primer trimestre') !== undefined).toBe(edit)
    expect(rowButton('Borrar el período Primer trimestre') !== undefined).toBe(remove)
  })

  it('solo leer: tabla sin columna de acciones', async () => {
    session.__setPermissions([...STRUCTURE, 'configuracion_academica.leer'])

    const wrapper = await open()

    expect(wrapper.find('[data-slot="acad-periods"]').text()).toContain('Primer trimestre')
    expect(buttonByText('Nuevo período')).toBeUndefined()
    expect(document.body.querySelector('button[aria-label^="Editar el período"]')).toBeNull()
  })

  it('con un curso cerrado: aviso persistente de texto y ninguna acción de escritura', async () => {
    vi.mocked(acadApi.getEducationLevel).mockResolvedValue(level({ academic_year: 'Y1' }) as never)

    const wrapper = await open()

    expect(wrapper.find('[data-slot="acad-periods-read-only"]').text()).toContain('solo lectura')
    expect(wrapper.find('[data-slot="acad-read-only"]').exists()).toBe(true)
    expect(buttonByText('Nuevo período')).toBeUndefined()
  })

  it('estado vacío cuando la etapa no tiene períodos', async () => {
    vi.mocked(acadApi.listAcademicPeriods).mockResolvedValue(page([]) as never)

    const wrapper = await open()

    expect(wrapper.find('[data-slot="acad-periods"]').text()).toContain(
      'Esta etapa no tiene períodos lectivos',
    )
  })

  it('error de carga: estado de error propio de la tabla, sin romper la ficha', async () => {
    vi.mocked(acadApi.listAcademicPeriods).mockRejectedValue(
      new ApiError('x', 500, { type: 'urn:pge:error:server', status: 500 }),
    )

    const wrapper = await open()

    expect(wrapper.find('h1').text()).toContain('ESO')
    expect(wrapper.find('[data-slot="acad-periods"] [role="alert"]').exists()).toBe(true)
  })
})

describe('CA-ACAD-099 (§10.5.2): alta, edición y borrado', () => {
  it('el alta envía la etapa y no envía academic_year (RN-ACAD-51); nombre en el idioma por defecto', async () => {
    vi.mocked(acadApi.createAcademicPeriod).mockResolvedValue(period() as never)

    const wrapper = await open()

    buttonByText('Nuevo período')!.click()
    await flushPromises()
    await chooseType('Trimestre')
    await fill('#acad-period-name-es-ES', 'Tercer trimestre')
    await fill('#acad-period-starts_on', '2026-04-06')
    await fill('#acad-period-ends_on', '2026-06-20')
    await submitForm()

    expect(acadApi.createAcademicPeriod).toHaveBeenCalledTimes(1)

    const body = vi.mocked(acadApi.createAcademicPeriod).mock.calls[0]![0] as unknown as Record<
      string,
      unknown
    >

    expect(body).toEqual({
      education_level: 'L1',
      period_type: 'trimestre',
      name_translations: { 'es-ES': 'Tercer trimestre' },
      starts_on: '2026-04-06',
      ends_on: '2026-06-20',
    })
    expect(body).not.toHaveProperty('academic_year')
    expect(body).not.toHaveProperty('name')
    expect(wrapper.find('[role="status"]').text()).toContain('Período creado.')
    expect(vi.mocked(acadApi.listAcademicPeriods).mock.calls.length).toBe(2)
  })

  it('el diálogo ayuda con las fechas del curso académico', async () => {
    await open()

    buttonByText('Nuevo período')!.click()
    await flushPromises()

    expect(document.body.querySelector('#acad-period-range')!.textContent).toContain(
      'Entre el 1 sept 2026 y el 30 jun 2027',
    )
  })

  it('la edición envía solo las claves modificadas (fusión por idioma)', async () => {
    vi.mocked(acadApi.updateAcademicPeriod).mockResolvedValue(period() as never)

    const wrapper = await open()

    rowButton('Editar el período Primer trimestre')!.click()
    await flushPromises()
    await fill('#acad-period-ends_on', '2025-12-19')
    await submitForm()

    expect(acadApi.updateAcademicPeriod).toHaveBeenCalledWith('P1', { ends_on: '2025-12-19' })
    expect(wrapper.find('[role="status"]').text()).toContain('Período actualizado.')
  })

  it('un 422 por campo se pinta junto a su campo, enlazado con aria-describedby', async () => {
    vi.mocked(acadApi.updateAcademicPeriod).mockRejectedValue(
      problem(422, {
        ends_on: [
          {
            code: 'acad.validation.period_ends_before_start',
            message: 'La fecha de fin debe ser posterior a la de inicio.',
          },
        ],
      }),
    )

    await open()

    rowButton('Editar el período Primer trimestre')!.click()
    await flushPromises()
    await fill('#acad-period-ends_on', '2025-01-01')
    await submitForm()

    const input = document.body.querySelector('#acad-period-ends_on')!
    const errorId = input.getAttribute('aria-describedby')!.split(' ').pop()!

    expect(input.getAttribute('aria-invalid')).toBe('true')
    expect(document.getElementById(errorId)!.textContent).toContain(
      'La fecha de fin debe ser posterior a la de inicio.',
    )
  })

  it('period_overlap nombra el período con el que solapa (acad.periods.overlapWith)', async () => {
    vi.mocked(acadApi.updateAcademicPeriod).mockRejectedValue(
      problem(422, {
        starts_on: [
          {
            code: 'acad.validation.period_overlap',
            message: 'El período se solapa con otro de la misma etapa.',
            params: { period: 'P2' },
          },
        ],
      }),
    )

    await open()

    rowButton('Editar el período Primer trimestre')!.click()
    await flushPromises()
    await fill('#acad-period-ends_on', '2026-02-01')
    await submitForm()

    const errors = document.body.querySelector('#acad-period-starts_on-errors')!

    expect(errors.textContent).toContain(
      'El período se solapa con «Segundo trimestre», de la misma etapa.',
    )
    expect(errors.textContent).not.toContain('otro de la misma etapa')
  })

  it('period_overlap con un período que no está en la tabla: el message de la API', async () => {
    vi.mocked(acadApi.updateAcademicPeriod).mockRejectedValue(
      problem(422, {
        starts_on: [
          {
            code: 'acad.validation.period_overlap',
            message: 'El período se solapa con otro de la misma etapa.',
            params: { period: 'FUERA' },
          },
        ],
      }),
    )

    await open()

    rowButton('Editar el período Primer trimestre')!.click()
    await flushPromises()
    await fill('#acad-period-ends_on', '2026-02-01')
    await submitForm()

    expect(document.body.querySelector('#acad-period-starts_on-errors')!.textContent).toContain(
      'El período se solapa con otro de la misma etapa.',
    )
  })

  it('un 409 academic-year-closed al guardar se muestra junto a la acción, dentro del diálogo', async () => {
    vi.mocked(acadApi.updateAcademicPeriod).mockRejectedValue(
      new ApiError('x', 409, {
        type: 'urn:pge:error:academic-year-closed',
        status: 409,
        detail: 'El curso académico está cerrado.',
      }),
    )

    await open()

    rowButton('Editar el período Primer trimestre')!.click()
    await flushPromises()
    await fill('#acad-period-ends_on', '2025-12-19')
    await submitForm()

    expect(
      document.body.querySelector('[data-slot="acad-entity-form"] [role="alert"]')!.textContent,
    ).toContain('El curso académico está cerrado.')
  })

  it('borrar pide confirmación y no envía nada si se cancela', async () => {
    await open()

    rowButton('Borrar el período Primer trimestre')!.click()
    await flushPromises()
    expect(document.body.textContent).toContain('Borrar el período Primer trimestre')
    buttonByText('Cancelar')!.click()
    await flushPromises()

    expect(acadApi.deleteAcademicPeriod).not.toHaveBeenCalled()
  })

  it('borrar confirmado envía DELETE, avisa y recarga la tabla', async () => {
    vi.mocked(acadApi.deleteAcademicPeriod).mockResolvedValue(undefined)

    const wrapper = await open()

    rowButton('Borrar el período Primer trimestre')!.click()
    await flushPromises()
    buttonByText('Borrar Primer trimestre')!.click()
    await flushPromises()

    expect(acadApi.deleteAcademicPeriod).toHaveBeenCalledWith('P1')
    expect(wrapper.find('[data-slot="acad-periods"] [role="status"]').text()).toContain(
      'Período borrado.',
    )
    expect(vi.mocked(acadApi.listAcademicPeriods).mock.calls.length).toBe(2)
  })

  it('un 409 al borrar muestra el mensaje del servidor junto a la acción', async () => {
    vi.mocked(acadApi.deleteAcademicPeriod).mockRejectedValue(
      new ApiError('x', 409, {
        type: 'urn:pge:error:academic-year-closed',
        status: 409,
        detail: 'El curso académico está cerrado.',
      }),
    )

    const wrapper = await open()

    rowButton('Borrar el período Primer trimestre')!.click()
    await flushPromises()
    buttonByText('Borrar Primer trimestre')!.click()
    await flushPromises()

    expect(wrapper.find('[data-slot="acad-periods"] [role="alert"]').text()).toContain(
      'El curso académico está cerrado.',
    )
  })
})

describe('CA-ACAD-099 (§10.5.3, RN-ACAD-61): borrar una etapa con períodos vivos', () => {
  it('el 409 has_dependents nombra «Períodos lectivos» junto a la acción', async () => {
    session.__setPermissions([
      ...STRUCTURE,
      'estructura_academica.eliminar',
      'configuracion_academica.leer',
    ])
    vi.mocked(acadApi.deleteEducationLevel).mockRejectedValue(
      new ApiError('x', 409, {
        type: 'urn:pge:error:conflict',
        status: 409,
        detail: 'La etapa ESO tiene períodos lectivos.',
        errors: {
          dependents: [
            {
              code: 'acad.conflict.has_dependents',
              message: 'x',
              params: { dependents: ['academic_periods'] },
            },
          ],
        },
      }),
    )

    const wrapper = await open()

    buttonByText('Borrar')!.click()
    await flushPromises()
    buttonByText('Borrar ESO')!.click()
    await flushPromises()

    const alert = wrapper.find('[role="alert"]')

    expect(alert.text()).toContain('La etapa ESO tiene períodos lectivos.')
    expect(alert.find('li').text()).toBe('Períodos lectivos')
  })
})

describe('CA-ACAD-100: period_type se muestra con su etiqueta traducida', () => {
  it('un código sin clave cae al código en crudo (ADR-038 §7.3)', async () => {
    vi.mocked(acadApi.listAcademicPeriods).mockResolvedValue(
      page([period({ period_type: 'bimestre' })]) as never,
    )

    const wrapper = await open()

    expect(wrapper.find('[data-slot="acad-periods"]').text()).toContain('bimestre')
  })

  it('en inglés se muestra la etiqueta del idioma activo', async () => {
    setLocale('en')

    const wrapper = await open()

    expect(wrapper.find('[data-slot="acad-periods"]').text()).toContain('Term (three months)')
    expect(wrapper.find('[data-slot="acad-periods"] h2').text()).toBe('Academic periods')
  })
})
