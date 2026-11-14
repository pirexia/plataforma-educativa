/**
 * `docs/modulos/REQ-CURSO/funcional.md §10.6`, `§13.8` (1.12b): `CA-CURSO-144` (detalle de la copia: consulta con espera
 * creciente de 2 s a 30 s hasta un estado final, texto «sigue en cola» sin error tras 10 min, región `aria-live`,
 * «Revertir» y «Cancelar copia» por permiso y estado, advertencia de pérdida de cambios, cancelar no envía nada).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'

const getRollover = vi.fn()
const getAcademicYear = vi.fn()
const revertRollover = vi.fn()
const cancelRollover = vi.fn()

vi.mock('../api', () => ({
  getRollover: (...args: unknown[]) => getRollover(...args),
  getAcademicYear: (...args: unknown[]) => getAcademicYear(...args),
  revertRollover: (...args: unknown[]) => revertRollover(...args),
  cancelRollover: (...args: unknown[]) => cancelRollover(...args),
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
const { default: RolloverDetailView } = await import('./RolloverDetailView.vue')

function rollover(status: string, overrides: Record<string, unknown> = {}) {
  return {
    public_id: 'COPY1',
    academic_year: 'DEST',
    source_academic_year: 'ACT',
    status,
    elements: ['acad.education_levels'],
    copy_teaching_assignments: false,
    date_offset_days: 365,
    result: null,
    failure: null,
    revert_failure: null,
    revertible: false,
    cancellable_at: null,
    started_at: null,
    finished_at: null,
    reverted_at: null,
    created_at: '2027-05-10T08:00:00Z',
    updated_at: '2027-05-10T08:00:00Z',
    ...overrides,
  }
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
        component: stub,
      },
      {
        path: '/administracion/cursos/:publicId/copias/:rolloverId',
        name: 'curso-rollover-detail',
        component: RolloverDetailView,
      },
    ],
  })

  await router.push('/administracion/cursos/DEST/copias/COPY1')
  await router.isReady()

  const wrapper = mount(RolloverDetailView, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return wrapper
}

async function advance(ms: number): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms)
  await flushPromises()
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

function statusText(): string {
  return (
    document.body
      .querySelector('[data-testid="rollover-status"]')
      ?.textContent?.replace(/\s+/g, ' ')
      .trim() ?? ''
  )
}

beforeEach(() => {
  vi.useFakeTimers()
  setLocale('es')
  getRollover.mockReset()
  getAcademicYear
    .mockReset()
    .mockResolvedValue({ public_id: 'DEST', code: '2027-2028', status: 'planificacion' })
  revertRollover.mockReset()
  cancelRollover.mockReset()
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
  session.__setPermissions(['apertura_curso_academico.leer', 'apertura_curso_academico.eliminar'])
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
  vi.useRealTimers()
})

describe('CA-CURSO-144 (funcional.md §10.6): seguimiento del estado de la copia', () => {
  it('consulta con espera creciente (2 s, 4 s, 8 s…) y deja de hacerlo al llegar a completado; el cambio se anuncia en la región aria-live', async () => {
    getRollover
      .mockResolvedValueOnce(rollover('pendiente'))
      .mockResolvedValueOnce(rollover('en_curso'))
      .mockResolvedValueOnce(rollover('en_curso'))
      .mockResolvedValue(rollover('completado', { revertible: true, result: { elements: [] } }))

    await mountView()
    expect(getRollover).toHaveBeenCalledTimes(1)
    expect(statusText()).toContain('En cola')

    await advance(1_900)
    expect(getRollover).toHaveBeenCalledTimes(1)
    await advance(200)
    expect(getRollover).toHaveBeenCalledTimes(2)
    expect(statusText()).toContain('En curso')
    expect(
      document.body.querySelector('[data-testid="rollover-announcement"]')?.textContent,
    ).toContain('En curso')

    // La segunda espera es de 4 s (consulta en el segundo 6).
    await advance(3_800)
    expect(getRollover).toHaveBeenCalledTimes(2)
    await advance(200)
    expect(getRollover).toHaveBeenCalledTimes(3)

    // La tercera, de 8 s (segundo 14); la cuarta consulta devuelve completado.
    await advance(7_800)
    expect(getRollover).toHaveBeenCalledTimes(3)
    await advance(300)
    expect(getRollover).toHaveBeenCalledTimes(4)
    expect(statusText()).toContain('Completada')
    expect(
      document.body.querySelector('[data-testid="rollover-announcement"]')?.textContent,
    ).toContain('Completada')

    // Ya no se consulta más.
    await advance(120_000)
    expect(getRollover).toHaveBeenCalledTimes(4)
  })

  it('la espera crece hasta un tope de 30 s', async () => {
    getRollover.mockResolvedValue(rollover('pendiente'))
    await mountView()

    // Esperas de 2, 4, 8, 16 y 30 s (tope), luego 30 s: consultas en los segundos 2, 6, 14, 30, 60, 90…
    await advance(30_100)
    expect(getRollover).toHaveBeenCalledTimes(5)
    await advance(30_000)
    expect(getRollover).toHaveBeenCalledTimes(6)
    await advance(30_000)
    expect(getRollover).toHaveBeenCalledTimes(7)
  })

  it('tras 10 min en pendiente muestra «sigue en cola» sin error y «Comprobar de nuevo» reinicia la consulta', async () => {
    getRollover.mockResolvedValue(rollover('pendiente'))
    await mountView()

    await advance(10 * 60 * 1000 + 60_000)

    expect(document.body.textContent).toContain(
      'La copia sigue en cola; vuelve a consultar más tarde.',
    )
    expect(document.body.querySelector('[role="alert"]')).toBeNull()

    const calls = getRollover.mock.calls.length

    await advance(60_000)
    expect(getRollover.mock.calls.length).toBe(calls)

    await click(button('Comprobar de nuevo'))
    expect(getRollover.mock.calls.length).toBe(calls + 1)
    expect(document.body.textContent).not.toContain('sigue en cola')
  })

  it('un 404 de la carga inicial se trata como estado de error de la pantalla', async () => {
    getRollover.mockRejectedValue(
      new ApiError('No encontrado', 404, { type: 'urn:pge:error:not-found' }),
    )
    await mountView()

    expect(document.body.querySelector('h1')).toBeNull()
  })
})

describe('CA-CURSO-144 RN-CURSO-74, RN-CURSO-85: Revertir y Cancelar copia', () => {
  it('«Revertir» solo con apertura_curso_academico.eliminar, copia completada y destino en planificación; su diálogo advierte de la pérdida de cambios y cancelar no envía nada', async () => {
    getRollover.mockResolvedValue(
      rollover('completado', { revertible: true, result: { elements: [] } }),
    )
    await mountView()

    expect(button('Revertir copia')).toBeDefined()

    await click(button('Revertir copia'))

    const dialog = document.body.querySelector('[role="alertdialog"]')!

    expect(dialog.textContent).toContain('se pierden los cambios hechos sobre lo copiado')

    await click(
      [...dialog.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Cancelar'),
    )
    expect(revertRollover).not.toHaveBeenCalled()

    revertRollover.mockResolvedValue(rollover('revirtiendo'))
    await click(button('Revertir copia'))
    await click(
      [...document.body.querySelectorAll('[role="alertdialog"] button')].find(
        (b) => b.textContent?.trim() === 'Revertir la copia en 2027-2028',
      ),
    )

    expect(revertRollover).toHaveBeenCalledWith('COPY1')
    expect(statusText()).toContain('Revirtiendo')
  })

  it('«Revertir» no se ofrece sin el permiso, ni con la copia no revertible', async () => {
    getRollover.mockResolvedValue(
      rollover('completado', { revertible: false, result: { elements: [] } }),
    )
    await mountView()
    expect(button('Revertir copia')).toBeUndefined()

    wrappers.pop()!.unmount()
    document.body.innerHTML = ''
    getRollover.mockResolvedValue(
      rollover('completado', { revertible: true, result: { elements: [] } }),
    )
    session.__setPermissions(['apertura_curso_academico.leer'])
    await mountView()

    expect(button('Revertir copia')).toBeUndefined()
  })

  it('un 409 rollover_revert_blocked muestra el message de cada entrada', async () => {
    getRollover.mockResolvedValue(
      rollover('completado', { revertible: true, result: { elements: [] } }),
    )
    revertRollover.mockRejectedValue(
      new ApiError('Conflicto', 409, {
        type: 'urn:pge:error:conflict',
        detail: 'No se puede revertir: hay datos añadidos después que dependen de lo copiado.',
        errors: {
          rollover_revert: [
            {
              code: 'acad.rollover.revert_has_dependents',
              message: 'Hay datos añadidos después de la copia que dependen de lo copiado (1).',
              params: { dependents: ['group'], count: 1 },
            },
          ],
        },
      }),
    )
    await mountView()
    await click(button('Revertir copia'))
    await click(
      [...document.body.querySelectorAll('[role="alertdialog"] button')].find(
        (b) => b.textContent?.trim() === 'Revertir la copia en 2027-2028',
      ),
    )

    expect(document.body.querySelector('[role="alert"]')?.textContent).toContain(
      'No se puede revertir',
    )
    expect(document.body.querySelector('[role="alert"]')?.textContent).toContain(
      'dependen de lo copiado (1)',
    )
  })

  it('«Cancelar copia» solo se ofrece pasados 30 minutos del último cambio (cancellable_at); antes, no', async () => {
    vi.setSystemTime(new Date('2027-05-10T08:10:00Z'))
    getRollover.mockResolvedValue(rollover('pendiente', { cancellable_at: '2027-05-10T08:30:00Z' }))
    await mountView()

    expect(button('Cancelar copia')).toBeUndefined()

    // Pasa el tiempo: la acción aparece sin recargar.
    await advance(25 * 60 * 1000)
    expect(button('Cancelar copia')).toBeDefined()
  })

  it('«Cancelar copia» pide confirmación («se marcará como fallida; no se ha copiado nada»), cancelar no envía nada y aceptar la envía', async () => {
    vi.setSystemTime(new Date('2027-05-10T09:00:00Z'))
    getRollover.mockResolvedValue(rollover('pendiente', { cancellable_at: '2027-05-10T08:30:00Z' }))
    cancelRollover.mockResolvedValue(
      rollover('fallido', { failure: { code: 'cancelled', errors: [] }, cancellable_at: null }),
    )
    await mountView()

    await click(button('Cancelar copia'))
    const dialog = document.body.querySelector('[role="alertdialog"]')!

    expect(dialog.textContent).toContain('se marcará como fallida; no se ha copiado nada')
    await click(
      [...dialog.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Cancelar'),
    )
    expect(cancelRollover).not.toHaveBeenCalled()

    await click(button('Cancelar copia'))
    await click(
      [...document.body.querySelectorAll('[role="alertdialog"] button')].find(
        (b) => b.textContent?.trim() === 'Cancelar la copia',
      ),
    )

    expect(cancelRollover).toHaveBeenCalledWith('COPY1')
    expect(statusText()).toContain('Fallida')
    expect(document.body.textContent).toContain('Cancelada; no se ha copiado nada')
  })

  it('una copia fallida muestra el motivo y las entradas del fallo, sin acciones', async () => {
    getRollover.mockResolvedValue(
      rollover('fallido', {
        failure: {
          code: 'rollover_blocked',
          errors: [
            {
              code: 'acad.rollover.target_not_empty',
              message: 'El curso de destino ya tiene datos de «Etapas» (1).',
              params: {},
              element: 'acad.education_levels',
            },
          ],
        },
      }),
    )
    await mountView()

    expect(document.body.textContent).toContain('Algo impedía copiar')
    expect(document.body.textContent).toContain(
      'El curso de destino ya tiene datos de «Etapas» (1).',
    )
    expect(button('Revertir copia')).toBeUndefined()
    expect(button('Cancelar copia')).toBeUndefined()
  })

  it('una copia completada muestra el resultado por elemento con omisiones y ajustes', async () => {
    getRollover.mockResolvedValue(
      rollover('completado', {
        result: {
          elements: [
            {
              code: 'acad.timetable_sessions',
              label: 'Sesiones del horario',
              copied: 4,
              skipped: [
                {
                  reason: 'teacher_inactive',
                  label: 'Profesorado que ya no está activo',
                  count: 2,
                },
              ],
              adjusted: [],
            },
          ],
        },
      }),
    )
    await mountView()

    // La columna «Elemento» muestra la etiqueta traducida, nunca el código crudo.
    expect(document.body.textContent).toContain('Sesiones del horario')
    expect(document.body.textContent).not.toContain('acad.timetable_sessions')
    expect(document.body.textContent).toContain('Profesorado que ya no está activo: 2')
  })
})

describe('revisión de seguridad B4 (1.12b): lo que viene del servidor se muestra como texto, nunca como HTML', () => {
  it('un mensaje de incidencia y un código de fallo con marcado HTML se pintan literales, sin crear elementos', async () => {
    getRollover.mockResolvedValue(
      rollover('fallido', {
        failure: {
          code: '<img src=x onerror="window.__xss=1">',
          errors: [{ code: 'x', message: '<script>window.__xss=1</script><b>negrita</b>' }],
        },
        revert_failure: {
          code: 'rollover_revert_blocked',
          errors: [{ code: 'y', message: '<img src=x onerror="window.__xss=1">' }],
        },
      }),
    )

    await mountView()

    const text = document.body.textContent ?? ''

    expect(text).toContain('<script>window.__xss=1</script><b>negrita</b>')
    expect(text).toContain('<img src=x onerror="window.__xss=1">')
    expect(document.body.querySelector('script')).toBeNull()
    expect(document.body.querySelector('img')).toBeNull()
    expect(document.body.querySelector('b')).toBeNull()
    expect((window as unknown as { __xss?: number }).__xss).toBeUndefined()
  })
})
