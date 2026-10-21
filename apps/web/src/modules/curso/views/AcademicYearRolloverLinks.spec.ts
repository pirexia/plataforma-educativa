/**
 * `docs/modulos/REQ-CURSO/funcional.md §10.6`, `§13.8` (1.12b): `CA-CURSO-145` (los avisos en otros diálogos: la activación
 * muestra el `409 rollover_in_progress` con enlace a la copia; la reapertura advierte, sin bloquear, de una copia
 * completada desde ese curso hacia el que está en planificación, y solo con `apertura_curso_academico.leer`) y `CA-CURSO-143`
 * (entradas al asistente: «Abrir curso siguiente», sección «Copia de estructura» de la ficha).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'

const getAcademicYear = vi.fn()
const transitionAcademicYear = vi.fn()
const reopenAcademicYear = vi.fn()
const listRollovers = vi.fn()
const listSelectableAcademicYears = vi.fn()
const listAcademicYears = vi.fn()

vi.mock('../api', () => ({
  getAcademicYear: (...args: unknown[]) => getAcademicYear(...args),
  transitionAcademicYear: (...args: unknown[]) => transitionAcademicYear(...args),
  reopenAcademicYear: (...args: unknown[]) => reopenAcademicYear(...args),
  listRollovers: (...args: unknown[]) => listRollovers(...args),
  listSelectableAcademicYears: (...args: unknown[]) => listSelectableAcademicYears(...args),
  listAcademicYears: (...args: unknown[]) => listAcademicYears(...args),
}))

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
const { default: AcademicYearDetailView } = await import('./AcademicYearDetailView.vue')
const { default: AcademicYearsListView } = await import('./AcademicYearsListView.vue')

function year(status: string, overrides: Record<string, unknown> = {}) {
  return {
    public_id: 'Y1',
    code: '2026-2027',
    starts_on: '2026-09-01',
    ends_on: '2099-06-30',
    status,
    created_at: '2026-06-01T10:00:00Z',
    updated_at: '2026-06-01T10:00:00Z',
    ...overrides,
  }
}

function copy(status: string, overrides: Record<string, unknown> = {}) {
  return {
    public_id: 'COPY1',
    academic_year: 'Y1',
    source_academic_year: 'SRC',
    status,
    elements: ['acad.education_levels'],
    copy_teaching_assignments: false,
    date_offset_days: 365,
    result: {
      elements: [
        { code: 'acad.education_levels', label: 'Etapas', copied: 2, skipped: [], adjusted: [] },
      ],
    },
    failure: null,
    revert_failure: null,
    revertible: true,
    cancellable_at: null,
    started_at: null,
    finished_at: null,
    reverted_at: null,
    created_at: '2027-05-10T08:00:00Z',
    updated_at: '2027-05-10T08:05:00Z',
    ...overrides,
  }
}

const wrappers: VueWrapper[] = []

async function mountDetail(): Promise<VueWrapper> {
  const stub = { template: '<div/>' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/administracion/cursos/:publicId',
        name: 'curso-academic-year-detail',
        component: AcademicYearDetailView,
      },
      {
        path: '/administracion/cursos/:publicId/editar',
        name: 'curso-academic-year-edit',
        component: stub,
      },
      {
        path: '/administracion/cursos/:publicId/copiar-estructura',
        name: 'curso-rollover-wizard',
        component: stub,
      },
      {
        path: '/administracion/cursos/:publicId/copias/:rolloverId',
        name: 'curso-rollover-detail',
        component: stub,
      },
    ],
  })

  await router.push('/administracion/cursos/Y1')
  await router.isReady()

  const wrapper = mount(AcademicYearDetailView, {
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

function buttonByText(label: string): Element | undefined {
  return [...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === label)
}

function link(label: string): HTMLAnchorElement | undefined {
  return [...document.body.querySelectorAll('a')].find((a) => a.textContent?.trim() === label)
}

beforeEach(() => {
  setLocale('es')
  getAcademicYear.mockReset().mockResolvedValue(year('planificacion'))
  transitionAcademicYear.mockReset()
  reopenAcademicYear.mockReset()
  listRollovers.mockReset().mockResolvedValue({ data: [], meta: { total: 0 } })
  listSelectableAcademicYears.mockReset().mockResolvedValue({ data: [] })
  listAcademicYears.mockReset().mockResolvedValue({ data: [], meta: { total: 0 } })
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
  session.__setPermissions([])
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('CA-CURSO-145 RN-CURSO-71, RN-CURSO-77 (funcional.md §10.6): avisos en los diálogos de activación y reapertura', () => {
  it('activar con una copia en vuelo muestra el 409 rollover_in_progress con enlace a la copia', async () => {
    session.__setPermissions(['curso_academico.leer', 'estado_curso_academico.actualizar'])
    transitionAcademicYear.mockRejectedValue(
      new ApiError('Conflicto', 409, {
        type: 'urn:pge:error:conflict',
        detail:
          'Hay una copia o una reversión en curso sobre este curso académico. Espera a que termine.',
        errors: {
          rollover: [
            {
              code: 'curso.conflict.rollover_in_progress',
              message:
                'Hay una copia o una reversión en curso sobre este curso académico. Espera a que termine.',
              params: { public_id: 'COPY1', status: 'pendiente' },
            },
          ],
        },
      }),
    )
    await mountDetail()

    await click(buttonByText('Activar curso'))
    await click(
      [...document.body.querySelectorAll('[role="alertdialog"] button')].find(
        (b) => b.textContent?.trim() === 'Activar el curso 2026-2027',
      ),
    )

    expect(document.body.querySelector('[role="alert"]')?.textContent).toContain(
      'Hay una copia o una reversión en curso',
    )

    const open = link('Abrir la copia en curso')!

    expect(open).toBeDefined()
    expect(open.getAttribute('href')).toBe('/administracion/cursos/Y1/copias/COPY1')
  })

  it('reabrir el origen de una copia completada hacia el curso en planificación advierte en el diálogo, sin bloquear', async () => {
    session.__setPermissions([
      'curso_academico.leer',
      'reapertura_curso_academico.actualizar',
      'apertura_curso_academico.leer',
    ])
    getAcademicYear.mockResolvedValue(year('cerrado'))
    listSelectableAcademicYears.mockResolvedValue({
      data: [year('planificacion', { public_id: 'PLAN', code: '2027-2028' }), year('cerrado')],
    })
    listRollovers.mockImplementation((params: { academic_year?: string }) =>
      Promise.resolve({
        data: params.academic_year === 'PLAN' ? [copy('completado')] : [],
        meta: { total: 1 },
      }),
    )
    reopenAcademicYear.mockResolvedValue(year('activo'))
    await mountDetail()

    await click(buttonByText('Reabrir curso'))

    expect(listRollovers).toHaveBeenCalledWith({
      academic_year: 'PLAN',
      source: 'Y1',
      status: ['completado'],
    })

    const dialog = document.body.querySelector('[role="alertdialog"]')!

    expect(dialog.textContent).toContain('origen de una copia de estructura completada')
    expect(dialog.textContent).toContain('«2027-2028»')

    // No bloquea: con motivo, se reabre.
    const textarea = dialog.querySelector('textarea') as HTMLTextAreaElement

    textarea.value = 'Cierre por error.'
    textarea.dispatchEvent(new Event('input', { bubbles: true }))
    await click(
      [...dialog.querySelectorAll('button')].find(
        (b) => b.textContent?.trim() === 'Reabrir el curso 2026-2027',
      ),
    )

    expect(reopenAcademicYear).toHaveBeenCalledWith('Y1', 'Cierre por error.')
  })

  it('sin apertura_curso_academico.leer no hay aviso (y ni se consulta)', async () => {
    session.__setPermissions(['curso_academico.leer', 'reapertura_curso_academico.actualizar'])
    getAcademicYear.mockResolvedValue(year('cerrado'))
    await mountDetail()

    await click(buttonByText('Reabrir curso'))

    const dialog = document.body.querySelector('[role="alertdialog"]')!

    expect(listRollovers).not.toHaveBeenCalled()
    expect(listSelectableAcademicYears).not.toHaveBeenCalled()
    expect(dialog.textContent).not.toContain('origen de una copia')
  })

  it('sin copia completada hacia el curso en planificación tampoco hay aviso', async () => {
    session.__setPermissions([
      'curso_academico.leer',
      'reapertura_curso_academico.actualizar',
      'apertura_curso_academico.leer',
    ])
    getAcademicYear.mockResolvedValue(year('cerrado'))
    listSelectableAcademicYears.mockResolvedValue({
      data: [year('planificacion', { public_id: 'PLAN', code: '2027-2028' })],
    })
    await mountDetail()

    await click(buttonByText('Reabrir curso'))

    expect(document.body.querySelector('[role="alertdialog"]')!.textContent).not.toContain(
      'origen de una copia',
    )
  })
})

describe('CA-CURSO-143 (funcional.md §10.6): entradas al asistente', () => {
  it('la ficha de un curso en planificación ofrece «Copiar estructura de otro curso» con apertura_curso_academico.crear y sin copia viva', async () => {
    session.__setPermissions(['curso_academico.leer', 'apertura_curso_academico.crear'])
    await mountDetail()

    expect(link('Copiar estructura de otro curso')?.getAttribute('href')).toBe(
      '/administracion/cursos/Y1/copiar-estructura',
    )
  })

  it('sin el permiso no se ofrece, y con una copia viva en la sección se enlaza su detalle en vez de ofrecer otra', async () => {
    session.__setPermissions(['curso_academico.leer', 'apertura_curso_academico.leer'])
    listRollovers.mockResolvedValue({ data: [copy('completado')], meta: { total: 1 } })
    await mountDetail()

    expect(link('Copiar estructura de otro curso')).toBeUndefined()
    expect(document.body.textContent).toContain('Completada')
    expect(link('Ver el detalle de la copia')?.getAttribute('href')).toBe(
      '/administracion/cursos/Y1/copias/COPY1',
    )

    // Con crear y una copia viva tampoco se ofrece otra (una copia viva por destino, RN-CURSO-67).
    wrappers.pop()!.unmount()
    document.body.innerHTML = ''
    session.__setPermissions([
      'curso_academico.leer',
      'apertura_curso_academico.leer',
      'apertura_curso_academico.crear',
    ])
    await mountDetail()

    expect(link('Copiar estructura de otro curso')).toBeUndefined()
  })

  it('un curso que no está en planificación y no tiene copias no pinta la sección', async () => {
    session.__setPermissions([
      'curso_academico.leer',
      'apertura_curso_academico.leer',
      'apertura_curso_academico.crear',
    ])
    getAcademicYear.mockResolvedValue(year('activo'))
    await mountDetail()

    expect(document.body.textContent).not.toContain('Copia de estructura')
  })

  async function mountList(): Promise<VueWrapper> {
    const stub = { template: '<div/>' }
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        {
          path: '/administracion/cursos',
          name: 'curso-academic-years',
          component: AcademicYearsListView,
        },
        { path: '/administracion/cursos/nuevo', name: 'curso-academic-year-new', component: stub },
        {
          path: '/administracion/cursos/:publicId',
          name: 'curso-academic-year-detail',
          component: stub,
        },
      ],
    })

    await router.push('/administracion/cursos')
    await router.isReady()

    const wrapper = mount(AcademicYearsListView, {
      global: { plugins: [i18n, router] },
      attachTo: document.body,
    }) as VueWrapper

    wrappers.push(wrapper)
    await flushPromises()

    return wrapper
  }

  it('«Abrir curso siguiente» solo con ambos permisos y si no hay curso en planificación; lleva al alta con next=rollover', async () => {
    session.__setPermissions([
      'curso_academico.leer',
      'curso_academico.crear',
      'apertura_curso_academico.crear',
    ])
    listAcademicYears.mockResolvedValue({
      data: [],
      meta: { total: 0, last_page: 1, current_page: 1, per_page: 25 },
    })
    await mountList()

    expect(link('Abrir curso siguiente')?.getAttribute('href')).toBe(
      '/administracion/cursos/nuevo?next=rollover',
    )

    // Con un curso en planificación, no se ofrece.
    wrappers.pop()!.unmount()
    document.body.innerHTML = ''
    listAcademicYears.mockResolvedValue({
      data: [year('planificacion')],
      meta: { total: 1, last_page: 1, current_page: 1, per_page: 25 },
    })
    await mountList()

    expect(link('Abrir curso siguiente')).toBeUndefined()

    // Sin el permiso de apertura, tampoco.
    wrappers.pop()!.unmount()
    document.body.innerHTML = ''
    session.__setPermissions(['curso_academico.leer', 'curso_academico.crear'])
    listAcademicYears.mockResolvedValue({
      data: [],
      meta: { total: 0, last_page: 1, current_page: 1, per_page: 25 },
    })
    await mountList()

    expect(link('Abrir curso siguiente')).toBeUndefined()
  })
})
