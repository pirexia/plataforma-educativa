/**
 * `docs/modulos/REQ-CURSO/funcional.md §10.6`, `§13.8` (1.12b): `CA-CURSO-143` (el asistente: pasos, requisitos al marcar y
 * desmarcar con aviso textual, elementos no permitidos, «Confirmar» según `executable`, foco en el encabezado de cada
 * paso), `CA-CURSO-153` y `CA-ACAD-153` (la casilla «Copiar la asignación docente»: desmarcada por defecto, solo si el
 * catálogo trae elementos del paquete, deshabilitada con su motivo, envía `copy_teaching_assignments`).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'

const getAcademicYear = vi.fn()
const listSelectableAcademicYears = vi.fn()
const listRolloverElements = vi.fn()
const previewRollover = vi.fn()
const requestRollover = vi.fn()

vi.mock('../api', () => ({
  getAcademicYear: (...args: unknown[]) => getAcademicYear(...args),
  listSelectableAcademicYears: (...args: unknown[]) => listSelectableAcademicYears(...args),
  listRolloverElements: (...args: unknown[]) => listRolloverElements(...args),
  previewRollover: (...args: unknown[]) => previewRollover(...args),
  requestRollover: (...args: unknown[]) => requestRollover(...args),
}))

vi.mock('@/session/useSession', async () => {
  const { shallowRef } = await import('vue')
  const user = shallowRef<{ public_id: string; permissions: string[] } | null>({
    public_id: 'ME',
    permissions: ['apertura_curso_academico.crear'],
  })

  return { useSession: () => ({ user }) }
})

const { default: RolloverWizardView } = await import('./RolloverWizardView.vue')

function year(publicId: string, code: string, status: string, startsOn: string, endsOn: string) {
  return {
    public_id: publicId,
    code,
    status,
    starts_on: startsOn,
    ends_on: endsOn,
    created_at: '2026-06-01T10:00:00Z',
    updated_at: '2026-06-01T10:00:00Z',
  }
}

const TARGET = year('DEST', '2027-2028', 'planificacion', '2027-09-01', '2028-08-31')
const ACTIVE = year('ACT', '2026-2027', 'activo', '2026-09-01', '2027-08-31')
const CLOSED = year('CLO', '2025-2026', 'cerrado', '2025-09-01', '2026-08-31')

function element(
  code: string,
  label: string,
  requires: string[] = [],
  overrides: Record<string, unknown> = {},
) {
  return { code, module: 'acad', label, requires, allowed: true, bundle: null, ...overrides }
}

const BUNDLE = { bundle: 'teaching_assignments' }

function catalog(overrides: Record<string, Record<string, unknown>> = {}) {
  const items = [
    element('acad.education_levels', 'Etapas'),
    element('acad.courses', 'Cursos de etapa', ['acad.education_levels']),
    element('acad.groups', 'Grupos', ['acad.courses']),
    element('acad.subgroups', 'Subgrupos', ['acad.groups']),
    element('acad.subjects', 'Asignaturas', ['acad.courses']),
    element('acad.group_subjects', 'Asignaturas de cada grupo', ['acad.groups', 'acad.subjects']),
    element('acad.academic_periods', 'Períodos lectivos', ['acad.education_levels']),
    element(
      'acad.teaching_assignments',
      'Asignaciones docentes',
      ['acad.group_subjects', 'acad.subgroups'],
      BUNDLE,
    ),
    element('acad.group_tutors', 'Tutores de grupo', ['acad.groups'], BUNDLE),
    element(
      'acad.timetable_sessions',
      'Sesiones de horario',
      ['acad.teaching_assignments'],
      BUNDLE,
    ),
  ]

  return items.map((item) => ({ ...item, ...(overrides[item.code] ?? {}) }))
}

function previewOf(overrides: Record<string, unknown> = {}) {
  return {
    academic_year: TARGET,
    source: ACTIVE,
    date_offset_days: 365,
    copy_teaching_assignments: false,
    elements: [
      {
        code: 'acad.education_levels',
        label: 'Etapas',
        to_copy: 2,
        skipped: [],
        adjusted: [],
        date_range: null,
        blocking: [],
      },
      {
        code: 'acad.academic_periods',
        label: 'Períodos lectivos',
        to_copy: 3,
        skipped: [
          { reason: 'teacher_inactive', label: 'Profesorado que ya no está activo', count: 1 },
        ],
        adjusted: [
          { reason: 'facility_removed', label: 'Se copian sin aula (aula dada de baja)', count: 2 },
        ],
        date_range: { starts_on: '2027-09-08', ends_on: '2028-06-21' },
        blocking: [],
      },
    ],
    blocking: [],
    executable: true,
    ...overrides,
  }
}

const wrappers: VueWrapper[] = []
let router: ReturnType<typeof createRouter>

async function mountView(): Promise<VueWrapper> {
  const stub = { template: '<div/>' }

  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/administracion/cursos/:publicId',
        name: 'curso-academic-year-detail',
        component: stub,
      },
      {
        path: '/administracion/cursos/:publicId/copiar-estructura',
        name: 'curso-rollover-wizard',
        component: RolloverWizardView,
      },
      {
        path: '/administracion/cursos/:publicId/copias/:rolloverId',
        name: 'curso-rollover-detail',
        component: stub,
      },
    ],
  })

  await router.push('/administracion/cursos/DEST/copiar-estructura')
  await router.isReady()

  const wrapper = mount(RolloverWizardView, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return wrapper
}

async function click(el: Element | undefined | null): Promise<void> {
  if (!el) {
    throw new Error('Control inexistente')
  }

  el.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await flushPromises()
}

function button(label: string): HTMLButtonElement | undefined {
  return [...document.body.querySelectorAll('button')].find(
    (candidate) => candidate.textContent?.trim() === label,
  )
}

function checkboxByLabel(label: string): HTMLInputElement {
  const found = [...document.body.querySelectorAll('label')].find((candidate) =>
    candidate.textContent?.includes(label),
  )

  return found!.querySelector('input[type="checkbox"]') as HTMLInputElement
}

function status(): string {
  return (
    document.body.querySelector('[role="status"][aria-live="polite"]')?.textContent?.trim() ?? ''
  )
}

beforeEach(() => {
  setLocale('es')
  getAcademicYear.mockReset().mockResolvedValue(TARGET)
  listSelectableAcademicYears.mockReset().mockResolvedValue({ data: [TARGET, ACTIVE, CLOSED] })
  listRolloverElements.mockReset().mockResolvedValue({ data: catalog() })
  previewRollover.mockReset().mockResolvedValue(previewOf())
  requestRollover.mockReset().mockResolvedValue({ public_id: 'COPY1' })
  Object.defineProperty(window, 'matchMedia', {
    configurable: true,
    writable: true,
    value: (query: string) => ({
      matches: false,
      media: query,
      addEventListener: () => undefined,
      removeEventListener: () => undefined,
    }),
  })
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('CA-CURSO-143 (funcional.md §10.6): el asistente de apertura del curso siguiente', () => {
  it('paso 1: ofrece como origen el curso activo (por defecto) y el cerrado, anteriores al destino, y no el propio destino', async () => {
    await mountView()

    const radios = [...document.body.querySelectorAll('input[type="radio"]')] as HTMLInputElement[]
    const labels = radios.map((radio) =>
      radio.closest('label')?.textContent?.replace(/\s+/g, ' ').trim(),
    )

    expect(radios).toHaveLength(2)
    expect(labels[0]).toContain('2026-2027')
    expect(radios[0]!.checked).toBe(true)
    expect(labels[1]).toContain('2025-2026')
  })

  it('el foco va al encabezado de cada paso al avanzar y al retroceder', async () => {
    await mountView()

    await click(button('Siguiente'))
    expect(document.activeElement?.id).toBe('rollover-step-2')

    await click(button('Atrás'))
    expect(document.activeElement?.id).toBe('rollover-step-1')
  })

  it('marcar acad.groups marca acad.courses y acad.education_levels con un aviso textual; desmarcar acad.courses desmarca los que lo requieren y lo dice', async () => {
    await mountView()
    await click(button('Siguiente'))

    await click(checkboxByLabel('Grupos'))

    expect(checkboxByLabel('Grupos').checked).toBe(true)
    expect(checkboxByLabel('Cursos de etapa').checked).toBe(true)
    expect(checkboxByLabel('Etapas').checked).toBe(true)
    expect(status()).toContain('Se han marcado también')
    expect(status()).toContain('Cursos de etapa')

    await click(checkboxByLabel('Cursos de etapa'))

    expect(checkboxByLabel('Cursos de etapa').checked).toBe(false)
    expect(checkboxByLabel('Grupos').checked).toBe(false)
    expect(checkboxByLabel('Etapas').checked).toBe(true)
    expect(status()).toContain('Se han desmarcado también')
    expect(status()).toContain('Grupos')
  })

  it('los elementos allowed:false están deshabilitados con su motivo', async () => {
    listRolloverElements.mockResolvedValue({
      data: catalog({ 'acad.academic_periods': { allowed: false } }),
    })
    await mountView()
    await click(button('Siguiente'))

    const periods = checkboxByLabel('Períodos lectivos')

    expect(periods.disabled).toBe(true)
    expect(document.body.textContent).toContain('No tienes permiso para crear este elemento.')
    expect(periods.getAttribute('aria-describedby')).toBeTruthy()
  })

  it('«Confirmar» está deshabilitado con executable:false y muestra el message de las incidencias', async () => {
    previewRollover.mockResolvedValue(
      previewOf({
        executable: false,
        elements: [
          {
            code: 'acad.education_levels',
            label: 'Etapas',
            to_copy: 2,
            skipped: [],
            adjusted: [],
            date_range: null,
            blocking: [
              {
                code: 'acad.rollover.target_not_empty',
                message: 'El curso de destino ya tiene datos de «Etapas» (1).',
                params: { element: 'acad.education_levels', count: 1 },
                element: 'acad.education_levels',
              },
            ],
          },
        ],
        blocking: [
          {
            code: 'curso.rollover.exists',
            message: 'Ya hay una copia.',
            params: {},
            element: null,
          },
        ],
      }),
    )
    await mountView()
    await click(button('Siguiente'))
    await click(checkboxByLabel('Etapas'))
    await click(button('Siguiente'))

    const confirm = button('Confirmar')!

    expect(confirm.disabled).toBe(true)
    expect(document.body.textContent).toContain(
      'El curso de destino ya tiene datos de «Etapas» (1).',
    )
    expect(document.body.textContent).toContain('Ya hay una copia.')
    expect(document.body.textContent).toContain('No se puede confirmar mientras haya incidencias.')
    expect(document.activeElement?.id).toBe('rollover-step-3')
  })

  it('la simulación muestra el desplazamiento de fechas, el rango resultante y las omisiones y ajustes con su motivo', async () => {
    await mountView()
    await click(button('Siguiente'))
    await click(checkboxByLabel('Etapas'))
    await click(button('Siguiente'))

    expect(previewRollover).toHaveBeenCalledWith({
      academic_year: 'DEST',
      source: 'ACT',
      elements: ['acad.education_levels'],
      copy_teaching_assignments: false,
    })
    expect(document.body.textContent).toContain('Las fechas se desplazan 365 días.')
    expect(document.body.textContent).toContain('Profesorado que ya no está activo: 1')
    expect(document.body.textContent).toContain('Se copian sin aula (aula dada de baja): 2')
    expect(button('Confirmar')!.disabled).toBe(false)
  })

  it('Confirmar abre el diálogo con las tres advertencias; cancelar no envía nada; aceptar envía la solicitud y va al detalle', async () => {
    await mountView()
    await click(button('Siguiente'))
    await click(checkboxByLabel('Etapas'))
    await click(button('Siguiente'))
    await click(button('Confirmar'))

    const dialog = document.body.querySelector('[role="alertdialog"]')!

    expect(dialog.textContent).toContain('se ejecuta en segundo plano')
    expect(dialog.textContent).toContain(
      'Se puede revertir mientras el curso 2027-2028 siga en planificación.',
    )
    expect(dialog.textContent).toContain('se pierden también los cambios')
    expect(requestRollover).not.toHaveBeenCalled()

    // Cancelar: ninguna petición y se vuelve a la simulación.
    await click(
      [...dialog.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Cancelar'),
    )
    expect(requestRollover).not.toHaveBeenCalled()
    expect(document.activeElement?.id).toBe('rollover-step-3')

    await click(button('Confirmar'))
    await click(
      [...document.body.querySelectorAll('[role="alertdialog"] button')].find(
        (b) => b.textContent?.trim() === 'Copiar a 2027-2028',
      ),
    )

    expect(requestRollover).toHaveBeenCalledWith({
      academic_year: 'DEST',
      source: 'ACT',
      elements: ['acad.education_levels'],
      copy_teaching_assignments: false,
    })
    expect(router.currentRoute.value.name).toBe('curso-rollover-detail')
    expect(router.currentRoute.value.params).toMatchObject({
      publicId: 'DEST',
      rolloverId: 'COPY1',
    })
  })

  it('un 409 rollover_exists al confirmar muestra el motivo con enlace a la copia existente', async () => {
    requestRollover.mockRejectedValue(
      new ApiError('Conflicto', 409, {
        type: 'urn:pge:error:conflict',
        detail: 'Este curso académico ya tiene una copia en curso o completada.',
        errors: {
          rollover: [
            {
              code: 'curso.conflict.rollover_exists',
              message: 'Este curso académico ya tiene una copia en curso o completada.',
              params: { public_id: 'OLD1', status: 'completado' },
            },
          ],
        },
      }),
    )
    await mountView()
    await click(button('Siguiente'))
    await click(checkboxByLabel('Etapas'))
    await click(button('Siguiente'))
    await click(button('Confirmar'))
    await click(
      [...document.body.querySelectorAll('[role="alertdialog"] button')].find(
        (b) => b.textContent?.trim() === 'Copiar a 2027-2028',
      ),
    )

    expect(document.body.textContent).toContain('ya tiene una copia en curso o completada')
    expect(document.body.textContent).toContain('Abrir la copia existente')
  })

  it('un destino que no está en planificación no ofrece el asistente', async () => {
    getAcademicYear.mockResolvedValue({ ...TARGET, status: 'activo' })
    await mountView()

    expect(document.body.textContent).toContain('Este curso no admite copia')
    expect(button('Siguiente')).toBeUndefined()
  })
})

describe('CA-CURSO-153 / CA-ACAD-153 (RN-CURSO-82): la casilla «Copiar la asignación docente»', () => {
  const TEACHING = 'Copiar la asignación docente'

  it('los elementos del paquete no aparecen como casillas sueltas; la casilla aparece desmarcada por defecto', async () => {
    await mountView()
    await click(button('Siguiente'))

    const labels = [...document.body.querySelectorAll('label')].map((label) =>
      label.textContent?.trim(),
    )

    expect(labels.some((label) => label?.includes('Asignaciones docentes'))).toBe(false)
    expect(labels.some((label) => label?.includes('Tutores de grupo'))).toBe(false)
    expect(labels.some((label) => label?.includes('Sesiones de horario'))).toBe(false)
    expect(checkboxByLabel(TEACHING).checked).toBe(false)
    // El texto de ayuda: omite a quien ya no está activo y las sesiones dependen de los marcos.
    expect(document.body.textContent).toContain('Se omiten las personas que ya no están activas')
    expect(document.body.textContent).toContain('si copias también los marcos horarios')
  })

  it('sin elementos del paquete en el catálogo no hay casilla', async () => {
    listRolloverElements.mockResolvedValue({
      data: catalog().filter((item) => item.bundle === null),
    })
    await mountView()
    await click(button('Siguiente'))

    expect(document.body.textContent).not.toContain(TEACHING)
  })

  it('marcarla marca asignaturas de cada grupo, subgrupos y sus requisitos con un aviso textual, y envía copy_teaching_assignments: true en la simulación y en la solicitud; desmarcarla envía false', async () => {
    await mountView()
    await click(button('Siguiente'))
    await click(checkboxByLabel(TEACHING))

    expect(checkboxByLabel('Asignaturas de cada grupo').checked).toBe(true)
    expect(checkboxByLabel('Subgrupos').checked).toBe(true)
    expect(checkboxByLabel('Grupos').checked).toBe(true)
    expect(status()).toContain('Se han marcado también')

    await click(button('Siguiente'))

    expect(previewRollover.mock.calls[0]![0]).toMatchObject({ copy_teaching_assignments: true })
    expect(previewRollover.mock.calls[0]![0].elements).not.toContain('acad.teaching_assignments')

    await click(button('Confirmar'))
    await click(
      [...document.body.querySelectorAll('[role="alertdialog"] button')].find(
        (b) => b.textContent?.trim() === 'Copiar a 2027-2028',
      ),
    )

    expect(requestRollover.mock.calls[0]![0]).toMatchObject({ copy_teaching_assignments: true })
  })

  it('desmarcar la casilla envía false', async () => {
    await mountView()
    await click(button('Siguiente'))
    await click(checkboxByLabel(TEACHING))
    await click(checkboxByLabel(TEACHING))
    await click(button('Siguiente'))

    expect(previewRollover.mock.calls[0]![0]).toMatchObject({ copy_teaching_assignments: false })
  })

  it('desmarcar un requisito de la casilla la desmarca y lo avisa', async () => {
    await mountView()
    await click(button('Siguiente'))
    await click(checkboxByLabel(TEACHING))
    await click(checkboxByLabel('Subgrupos'))

    expect(checkboxByLabel(TEACHING).checked).toBe(false)
    expect(status()).toContain('Se han desmarcado también')
    expect(status()).toContain(TEACHING)
  })

  it('está deshabilitada con su motivo si algún elemento del paquete llega con allowed:false', async () => {
    listRolloverElements.mockResolvedValue({
      data: catalog({ 'acad.group_tutors': { allowed: false } }),
    })
    await mountView()
    await click(button('Siguiente'))

    expect(checkboxByLabel(TEACHING).disabled).toBe(true)
    expect(document.body.textContent).toContain(
      'No tienes permiso para crear asignaciones docentes, tutores de grupo o sesiones de horario.',
    )
  })

  it.each(['es', 'en', 'de', 'fr'] as const)(
    'los textos de la casilla existen en %s',
    async (locale) => {
      setLocale(locale)
      await mountView()
      await click(button(i18n.global.t('curso.rollover.wizard.next')))

      const label = i18n.global.t('curso.rollover.wizard.teachingAssignments.label')

      expect(label).not.toBe('curso.rollover.wizard.teachingAssignments.label')
      expect(checkboxByLabel(label)).toBeTruthy()
      expect(i18n.global.t('curso.rollover.wizard.teachingAssignments.help')).not.toContain(
        'curso.rollover',
      )
      expect(i18n.global.t('curso.rollover.wizard.teachingAssignments.forbidden')).not.toContain(
        'curso.rollover',
      )
    },
  )
})
