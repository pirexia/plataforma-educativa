/**
 * `docs/modulos/REQ-CURSO/funcional.md §13.5` (1.10, 1.11c): `CA-CURSO-082`
 * (acciones por estado y permiso, también «Reabrir»), `CA-CURSO-083` (`409
 * active_exists` con enlace al curso activo, sin cambios en pantalla hasta
 * recargar), `CA-CURSO-084` (diálogo de cierre: advierte de que solo puede
 * deshacerse mientras no se active otro curso, exige confirmación y cancelar no
 * envía ninguna petición) y `CA-CURSO-087` (diálogo de reapertura con motivo,
 * `ADR-059`). `RN-CURSO-14`: el aviso de fecha de fin pasada no impide activar.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'

const getAcademicYear = vi.fn()
const transitionAcademicYear = vi.fn()
const reopenAcademicYear = vi.fn()

vi.mock('../api', () => ({
  getAcademicYear: (...args: unknown[]) => getAcademicYear(...args),
  transitionAcademicYear: (...args: unknown[]) => transitionAcademicYear(...args),
  reopenAcademicYear: (...args: unknown[]) => reopenAcademicYear(...args),
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

function problem(status: number, body: Record<string, unknown>): ApiError {
  return new ApiError('Error', status, body)
}

const wrappers: VueWrapper[] = []

async function mountView(): Promise<VueWrapper> {
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

function actionLabels(): string[] {
  return [
    ...document.body.querySelectorAll(
      'section[aria-labelledby="curso-actions-title"] a, section[aria-labelledby="curso-actions-title"] button',
    ),
  ].map((control) => control.textContent?.trim() ?? '')
}

function dialogButton(label: string): Element | undefined {
  return [...document.body.querySelectorAll('[role="alertdialog"] button')].find(
    (button) => button.textContent?.trim() === label,
  )
}

function buttonByText(label: string): Element | undefined {
  return [...document.body.querySelectorAll('button')].find(
    (button) => button.textContent?.trim() === label,
  )
}

const ALL = [
  'curso_academico.leer',
  'curso_academico.actualizar',
  'estado_curso_academico.actualizar',
]

beforeEach(() => {
  setLocale('es')
  getAcademicYear.mockReset().mockResolvedValue(year('planificacion'))
  transitionAcademicYear
    .mockReset()
    .mockImplementation((_id: string, status: string) => Promise.resolve(year(status)))
  reopenAcademicYear
    .mockReset()
    .mockImplementation((id: string) => Promise.resolve({ ...year('activo'), public_id: id }))
  window.localStorage.clear()
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
  session.__setPermissions(ALL)
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('CA-CURSO-082 (funcional.md §10.1, RN-CORE-61): acciones por estado y permiso', () => {
  it('en planificacion: Editar y Activar, nunca Cerrar', async () => {
    await mountView()

    expect(actionLabels()).toEqual(['Editar', 'Activar curso'])
  })

  it('en activo: solo Cerrar', async () => {
    getAcademicYear.mockResolvedValue(year('activo'))
    await mountView()

    expect(actionLabels()).toEqual(['Cerrar curso'])
  })

  it('en cerrado y en archivado, sin el permiso de reapertura: ninguna acción y un aviso de solo lectura', async () => {
    for (const status of ['cerrado', 'archivado']) {
      getAcademicYear.mockResolvedValue(year(status))
      const wrapper = await mountView()

      expect(actionLabels(), status).toEqual([])
      expect(wrapper.find('[role="note"]').text()).toContain('solo lectura')

      wrapper.unmount()
      wrappers.pop()
      document.body.innerHTML = ''
    }
  })

  it('en cerrado: solo «Reabrir curso», y solo con reapertura_curso_academico.actualizar (ADR-059)', async () => {
    getAcademicYear.mockResolvedValue(year('cerrado'))
    session.__setPermissions([...ALL, 'reapertura_curso_academico.actualizar'])
    await mountView()

    expect(actionLabels()).toEqual(['Reabrir curso'])
  })

  it('en cerrado, con estado_curso_academico.actualizar pero sin el permiso de reapertura: ninguna acción', async () => {
    getAcademicYear.mockResolvedValue(year('cerrado'))
    session.__setPermissions(['curso_academico.leer', 'estado_curso_academico.actualizar'])
    await mountView()

    expect(actionLabels()).toEqual([])
  })

  it('en archivado, aun con el permiso de reapertura: ninguna acción', async () => {
    getAcademicYear.mockResolvedValue(year('archivado'))
    session.__setPermissions([...ALL, 'reapertura_curso_academico.actualizar'])
    await mountView()

    expect(actionLabels()).toEqual([])
  })

  it('un estado no anticipado se trata como solo lectura y muestra su código (ADR-038 §7.3)', async () => {
    getAcademicYear.mockResolvedValue(year('suspendido'))
    const wrapper = await mountView()

    expect(actionLabels()).toEqual([])
    expect(wrapper.text()).toContain('suspendido')
  })

  it('«Editar» y «Activar» aparecen solo con su permiso respectivo', async () => {
    session.__setPermissions(['curso_academico.leer', 'curso_academico.actualizar'])
    await mountView()
    expect(actionLabels()).toEqual(['Editar'])
    wrappers.pop()!.unmount()
    document.body.innerHTML = ''

    session.__setPermissions(['curso_academico.leer', 'estado_curso_academico.actualizar'])
    await mountView()
    expect(actionLabels()).toEqual(['Activar curso'])
  })

  it('«Cerrar» exige estado_curso_academico.actualizar, no curso_academico.actualizar', async () => {
    getAcademicYear.mockResolvedValue(year('activo'))
    session.__setPermissions(['curso_academico.leer', 'curso_academico.actualizar'])
    await mountView()

    expect(actionLabels()).toEqual([])
  })

  it('sin curso_academico.actualizar, el enlace «Editar» no existe', async () => {
    session.__setPermissions(['curso_academico.leer'])
    const wrapper = await mountView()

    expect(wrapper.find('a[href$="/editar"]').exists()).toBe(false)
  })
})

describe('activar (funcional.md §4.3, RN-CURSO-14)', () => {
  it('pide confirmación, envía activo y deja el curso activo', async () => {
    const wrapper = await mountView()

    await click(buttonByText('Activar curso'))

    expect(transitionAcademicYear).not.toHaveBeenCalled()
    expect(document.body.querySelector('[role="alertdialog"]')).not.toBeNull()

    await click(dialogButton('Activar el curso 2026-2027'))

    expect(transitionAcademicYear).toHaveBeenCalledWith('Y1', 'activo')
    expect(wrapper.find('[role="status"]').text()).toContain('activado')
    expect(actionLabels()).toEqual(['Cerrar curso'])
  })

  it('avisa, sin impedirlo, de que la fecha de fin ya pasó', async () => {
    getAcademicYear.mockResolvedValue(year('planificacion', { ends_on: '2020-06-30' }))
    await mountView()

    await click(buttonByText('Activar curso'))

    expect(document.body.querySelector('[role="alertdialog"]')!.textContent).toContain(
      'ya ha pasado',
    )

    await click(dialogButton('Activar el curso 2026-2027'))

    expect(transitionAcademicYear).toHaveBeenCalledWith('Y1', 'activo')
  })
})

describe('CA-CURSO-083 (OPEN-CURSO-06): 409 active_exists', () => {
  it('muestra el mensaje del servidor y un enlace al curso activo; nada cambia hasta recargar', async () => {
    transitionAcademicYear.mockRejectedValue(
      problem(409, {
        type: 'urn:pge:error:conflict',
        title: 'Conflicto',
        status: 409,
        detail: 'Ya hay un curso activo («2025-2026»). Ciérralo antes de activar otro.',
        errors: {
          academic_year: [
            {
              code: 'curso.conflict.active_exists',
              message: 'Ya hay un curso activo («2025-2026»).',
              params: { public_id: 'ACTIVE1', code: '2025-2026' },
            },
          ],
        },
      }),
    )
    const wrapper = await mountView()
    const loads = getAcademicYear.mock.calls.length

    await click(buttonByText('Activar curso'))
    await click(dialogButton('Activar el curso 2026-2027'))

    const alert = wrapper.find('[role="alert"]')

    expect(alert.text()).toContain('Ya hay un curso activo')
    expect(alert.find('a').attributes('href')).toBe('/administracion/cursos/ACTIVE1')
    expect(alert.find('a').text()).toContain('2025-2026')
    // Nada cambia en pantalla hasta recargar: ni se vuelve a pedir ni cambia el estado.
    expect(getAcademicYear.mock.calls.length).toBe(loads)
    expect(actionLabels()).toEqual(['Editar', 'Activar curso'])

    await click(buttonByText('Volver a cargar'))

    expect(getAcademicYear.mock.calls.length).toBe(loads + 1)
  })
})

describe('CA-CURSO-084 (ADR-059, OPEN-CURSO-20): cerrar', () => {
  beforeEach(() => {
    getAcademicYear.mockResolvedValue(year('activo'))
  })

  it('el diálogo advierte de que bloquea la escritura y de que solo puede deshacerse mientras no se active otro curso, y requiere confirmación explícita', async () => {
    const wrapper = await mountView()

    await click(buttonByText('Cerrar curso'))

    const dialog = document.body.querySelector('[role="alertdialog"]')!

    expect(dialog.textContent).toContain('mientras no se active otro curso')
    expect(dialog.textContent).toContain('permiso de reapertura')
    expect(dialog.textContent).not.toContain('no se puede deshacer')
    expect(dialog.textContent).toContain('solo lectura')
    expect(transitionAcademicYear).not.toHaveBeenCalled()

    await click(dialogButton('Cerrar el curso 2026-2027'))

    expect(transitionAcademicYear).toHaveBeenCalledWith('Y1', 'cerrado')
    expect(wrapper.find('[role="status"]').text()).toContain('cerrado')
    expect(actionLabels()).toEqual([])
  })

  it('cancelar no envía ninguna petición', async () => {
    await mountView()

    await click(buttonByText('Cerrar curso'))
    await click(dialogButton('Cancelar'))

    expect(transitionAcademicYear).not.toHaveBeenCalled()
    expect(document.body.querySelector('[role="alertdialog"]')).toBeNull()
  })

  it('409 closure_checks_failed: lista las validaciones que fallan y el curso sigue activo', async () => {
    transitionAcademicYear.mockRejectedValue(
      problem(409, {
        type: 'urn:pge:error:conflict',
        title: 'Conflicto',
        status: 409,
        detail: 'El curso no se puede cerrar: hay validaciones de cierre que no se cumplen.',
        errors: {
          closure: [
            { code: 'calif.closure.unpublished', message: 'Hay calificaciones sin publicar.' },
            { code: 'asist.closure.open', message: 'Hay asistencia sin cerrar.' },
          ],
        },
      }),
    )
    const wrapper = await mountView()

    await click(buttonByText('Cerrar curso'))
    await click(dialogButton('Cerrar el curso 2026-2027'))

    const items = wrapper.findAll('[role="alert"] li').map((li) => li.text())

    expect(items).toEqual(['Hay calificaciones sin publicar.', 'Hay asistencia sin cerrar.'])
    expect(actionLabels()).toEqual(['Cerrar curso'])
  })
})

describe('CA-CURSO-087 (funcional.md §4.6 paso 1, ADR-059): reabrir', () => {
  beforeEach(() => {
    getAcademicYear.mockResolvedValue(year('cerrado'))
    session.__setPermissions([...ALL, 'reapertura_curso_academico.actualizar'])
  })

  function reasonField(): HTMLTextAreaElement {
    return document.body.querySelector('[role="alertdialog"] textarea') as HTMLTextAreaElement
  }

  async function typeReason(value: string): Promise<void> {
    reasonField().value = value
    reasonField().dispatchEvent(new Event('input', { bubbles: true }))
    await flushPromises()
  }

  it('el diálogo pide el motivo y advierte de los tres efectos', async () => {
    await mountView()

    await click(buttonByText('Reabrir curso'))

    const dialog = document.body.querySelector('[role="alertdialog"]')!

    expect(reasonField()).not.toBeNull()
    expect(document.body.querySelector('label[for="' + reasonField().id + '"]')).not.toBeNull()
    expect(dialog.textContent).toContain('volverán a admitir escritura en todos los módulos')
    expect(dialog.textContent).toContain('no sirve para corregir un dato concreto')
    expect(dialog.textContent).toContain('No incluyas datos personales')
    expect(reopenAcademicYear).not.toHaveBeenCalled()
  })

  it('con el motivo vacío o en blanco no envía la petición y avisa en el campo', async () => {
    await mountView()

    await click(buttonByText('Reabrir curso'))
    await click(dialogButton('Reabrir el curso 2026-2027'))

    expect(reopenAcademicYear).not.toHaveBeenCalled()
    expect(
      document.body.querySelector('[role="alertdialog"] [role="alert"]')!.textContent,
    ).toContain('Indica el motivo')
    expect(reasonField().getAttribute('aria-invalid')).toBe('true')

    await typeReason('   ')
    await click(dialogButton('Reabrir el curso 2026-2027'))

    expect(reopenAcademicYear).not.toHaveBeenCalled()
  })

  it('con motivo, envía POST …/reopen con el motivo recortado y deja el curso activo', async () => {
    const wrapper = await mountView()

    await click(buttonByText('Reabrir curso'))
    await typeReason('  Cierre lanzado por error.  ')
    await click(dialogButton('Reabrir el curso 2026-2027'))

    expect(reopenAcademicYear).toHaveBeenCalledWith('Y1', 'Cierre lanzado por error.')
    expect(wrapper.find('[role="status"]').text()).toContain('reabierto')
    expect(document.body.querySelector('[role="alertdialog"]')).toBeNull()
    // Reabierto: ya es activo, y quien solo tiene el permiso de reapertura no ve «Cerrar».
    expect(actionLabels()).toEqual(['Cerrar curso'])
  })

  it('cancelar no envía ninguna petición', async () => {
    await mountView()

    await click(buttonByText('Reabrir curso'))
    await typeReason('algo escrito')
    await click(dialogButton('Cancelar'))

    expect(reopenAcademicYear).not.toHaveBeenCalled()
    expect(document.body.querySelector('[role="alertdialog"]')).toBeNull()
  })

  it('al volver a abrir el diálogo, el motivo y el error empiezan limpios', async () => {
    await mountView()

    await click(buttonByText('Reabrir curso'))
    await click(dialogButton('Reabrir el curso 2026-2027'))
    await typeReason('borrador')
    await click(dialogButton('Cancelar'))
    await click(buttonByText('Reabrir curso'))

    expect(reasonField().value).toBe('')
    expect(document.body.querySelector('[role="alertdialog"] [role="alert"]')).toBeNull()
  })

  it.each([
    ['active_exists', 'Ya hay un curso activo («2026-2027»). Ciérralo antes de activar otro.'],
    [
      'reopen_not_latest',
      'Solo se puede reabrir el curso cerrado más reciente: el curso «2026-2027» es posterior.',
    ],
    ['invalid_transition', 'No se puede pasar un curso de «activo» a «activo».'],
  ])(
    '409 %s: muestra el motivo del servidor y el curso sigue cerrado en pantalla',
    async (code, detail) => {
      reopenAcademicYear.mockRejectedValue(
        problem(409, {
          type: 'urn:pge:error:conflict',
          title: 'Conflicto',
          status: 409,
          detail,
          errors: {
            academic_year: [
              {
                code: 'curso.conflict.' + code,
                message: detail,
                params: { public_id: 'OTHER1', code: '2026-2027' },
              },
            ],
          },
        }),
      )
      const wrapper = await mountView()

      await click(buttonByText('Reabrir curso'))
      await typeReason('Cierre por error')
      await click(dialogButton('Reabrir el curso 2026-2027'))

      expect(wrapper.find('[role="alert"]').text()).toContain(detail)
      expect(wrapper.find('h1 + p').text()).toContain('Cerrado')
      expect(actionLabels()).toEqual(['Reabrir curso'])
      expect(wrapper.find('[role="status"]').exists()).toBe(false)
    },
  )

  it('409 active_exists lleva un enlace al curso activo', async () => {
    reopenAcademicYear.mockRejectedValue(
      problem(409, {
        status: 409,
        detail: 'Ya hay un curso activo («2026-2027»).',
        errors: {
          academic_year: [
            {
              code: 'curso.conflict.active_exists',
              message: 'Ya hay un curso activo',
              params: { public_id: 'ACTIVE1', code: '2026-2027' },
            },
          ],
        },
      }),
    )
    const wrapper = await mountView()

    await click(buttonByText('Reabrir curso'))
    await typeReason('x')
    await click(dialogButton('Reabrir el curso 2026-2027'))

    expect(wrapper.find('[role="alert"] a').attributes('href')).toBe(
      '/administracion/cursos/ACTIVE1',
    )
  })

  it('409 reopen_checks_failed: lista las validaciones que fallan y el curso sigue cerrado', async () => {
    reopenAcademicYear.mockRejectedValue(
      problem(409, {
        status: 409,
        detail: 'El curso no se puede reabrir: hay validaciones de reapertura que no se cumplen.',
        errors: {
          reopen: [
            { code: 'prom.reopen.applied', message: 'La promoción ya está aplicada.' },
            { code: 'doc.reopen.signed', message: 'Hay actas firmadas.' },
          ],
        },
      }),
    )
    const wrapper = await mountView()

    await click(buttonByText('Reabrir curso'))
    await typeReason('x')
    await click(dialogButton('Reabrir el curso 2026-2027'))

    const alert = wrapper.find('[role="alert"]')

    expect(alert.text()).toContain('El curso no se puede reabrir todavía')
    expect(alert.findAll('li').map((li) => li.text())).toEqual([
      'La promoción ya está aplicada.',
      'Hay actas firmadas.',
    ])
    expect(actionLabels()).toEqual(['Reabrir curso'])
  })
})

describe('errores genéricos', () => {
  it('un fallo inesperado muestra el mensaje genérico traducido', async () => {
    transitionAcademicYear.mockRejectedValue(new Error('boom'))
    const wrapper = await mountView()

    await click(buttonByText('Activar curso'))
    await click(dialogButton('Activar el curso 2026-2027'))

    expect(wrapper.find('[role="alert"]').text()).toContain('No se ha podido completar')
  })

  it('un 404 de la ficha pinta el estado de aplicación, no la ficha', async () => {
    getAcademicYear.mockRejectedValue(
      problem(404, { type: 'urn:pge:error:not-found', status: 404 }),
    )
    const wrapper = await mountView()

    expect(wrapper.find('h1').exists()).toBe(false)
    expect(actionLabels()).toEqual([])
  })
})
