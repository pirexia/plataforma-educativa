/**
 * `docs/modulos/REQ-CURSO/funcional.md §10.1`, §4.1, §4.2 (1.10): formulario de
 * alta y edición. `422` bajo cada campo con `aria-invalid` y foco en el primero
 * (WCAG 2.2), `409 planning_exists` con enlace al curso en planificación,
 * `PATCH` solo con lo modificado y estado propio para un curso no editable.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'

const createAcademicYear = vi.fn()
const getAcademicYear = vi.fn()
const updateAcademicYear = vi.fn()

vi.mock('../api', () => ({
  createAcademicYear: (...args: unknown[]) => createAcademicYear(...args),
  getAcademicYear: (...args: unknown[]) => getAcademicYear(...args),
  updateAcademicYear: (...args: unknown[]) => updateAcademicYear(...args),
}))

const { default: AcademicYearFormView } = await import('./AcademicYearFormView.vue')

function year(status = 'planificacion') {
  return {
    public_id: 'Y1',
    code: '2026-2027',
    starts_on: '2026-09-01',
    ends_on: '2027-06-30',
    status,
    created_at: '2026-06-01T10:00:00Z',
    updated_at: '2026-06-01T10:00:00Z',
  }
}

function problem(status: number, body: Record<string, unknown>): ApiError {
  return new ApiError('Error', status, body)
}

const wrappers: VueWrapper[] = []

async function mountView(url: string): Promise<{ wrapper: VueWrapper; pushed: string[] }> {
  const stub = { template: '<div/>' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/administracion/cursos', name: 'curso-academic-years', component: stub },
      {
        path: '/administracion/cursos/nuevo',
        name: 'curso-academic-year-new',
        component: AcademicYearFormView,
      },
      {
        path: '/administracion/cursos/:publicId',
        name: 'curso-academic-year-detail',
        component: stub,
      },
      {
        path: '/administracion/cursos/:publicId/editar',
        name: 'curso-academic-year-edit',
        component: AcademicYearFormView,
      },
    ],
  })
  const pushed: string[] = []

  router.afterEach((to) => {
    pushed.push(to.fullPath)
  })

  await router.push(url)
  await router.isReady()
  pushed.length = 0

  const wrapper = mount(AcademicYearFormView, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return { wrapper, pushed }
}

async function fill(wrapper: VueWrapper, values: Record<string, string>): Promise<void> {
  for (const [field, value] of Object.entries(values)) {
    await wrapper.find(`#curso-form-${field}`).setValue(value)
  }
}

beforeEach(() => {
  setLocale('es')
  createAcademicYear.mockReset().mockResolvedValue(year())
  getAcademicYear.mockReset().mockResolvedValue(year())
  updateAcademicYear.mockReset().mockResolvedValue(year())
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('alta (funcional.md §4.1)', () => {
  it('envía código recortado y fechas, y navega a la ficha del curso creado', async () => {
    createAcademicYear.mockResolvedValue({ ...year(), public_id: 'NEW1', code: '2027-2028' })
    const { wrapper, pushed } = await mountView('/administracion/cursos/nuevo')

    await fill(wrapper, { code: '  2027-2028  ', starts_on: '2027-09-01', ends_on: '2028-06-30' })
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(createAcademicYear).toHaveBeenCalledWith({
      code: '2027-2028',
      starts_on: '2027-09-01',
      ends_on: '2028-06-30',
    })
    expect(pushed).toEqual(['/administracion/cursos/NEW1'])
  })

  it('no ofrece ningún campo de estado (RN-CURSO-03)', async () => {
    const { wrapper } = await mountView('/administracion/cursos/nuevo')

    expect(wrapper.find('select').exists()).toBe(false)
    expect(wrapper.find('#curso-form-status').exists()).toBe(false)
  })

  it('un 422 pinta cada mensaje del servidor bajo su campo, con aria-invalid y foco en el primero', async () => {
    createAcademicYear.mockRejectedValue(
      problem(422, {
        type: 'urn:pge:error:validation',
        status: 422,
        errors: {
          code: [
            { code: 'curso.validation.code_taken', message: 'Ya existe un curso con ese código.' },
          ],
          ends_on: [
            {
              code: 'curso.validation.ends_before_start',
              message: 'La fecha de fin debe ser posterior a la de inicio.',
            },
          ],
        },
      }),
    )
    const { wrapper } = await mountView('/administracion/cursos/nuevo')

    await fill(wrapper, { code: '2025-2026', starts_on: '2027-09-01', ends_on: '2027-01-01' })
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('#curso-form-code-error').text()).toBe('Ya existe un curso con ese código.')
    expect(wrapper.find('#curso-form-ends_on-error').text()).toContain('posterior')
    expect(wrapper.find('#curso-form-code').attributes('aria-invalid')).toBe('true')
    expect(wrapper.find('#curso-form-code').attributes('aria-describedby')).toContain(
      'curso-form-code-error',
    )
    expect(wrapper.find('#curso-form-starts_on').attributes('aria-invalid')).toBeUndefined()
    expect(wrapper.find('[role="alert"]').text()).toContain('Revisa estos datos')
    expect(document.activeElement?.id).toBe('curso-form-code')
  })

  it('un 409 planning_exists muestra el mensaje y un enlace al curso en planificación', async () => {
    createAcademicYear.mockRejectedValue(
      problem(409, {
        type: 'urn:pge:error:conflict',
        status: 409,
        detail:
          'Ya hay un curso en planificación («2026-2027»). Edítalo o actívalo antes de crear otro.',
        errors: {
          academic_year: [
            {
              code: 'curso.conflict.planning_exists',
              message: 'Ya hay un curso en planificación.',
              params: { public_id: 'PLAN1', code: '2026-2027' },
            },
          ],
        },
      }),
    )
    const { wrapper } = await mountView('/administracion/cursos/nuevo')

    await fill(wrapper, { code: '2027-2028', starts_on: '2027-09-01', ends_on: '2028-06-30' })
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const alert = wrapper.find('[role="alert"]')

    expect(alert.text()).toContain('Ya hay un curso en planificación')
    expect(alert.find('a').attributes('href')).toBe('/administracion/cursos/PLAN1')
    expect(alert.find('a').text()).toContain('2026-2027')
  })
})

describe('edición (funcional.md §4.2, RN-CURSO-06)', () => {
  it('carga el curso y envía solo las claves modificadas (ADR-038 §9.2)', async () => {
    const { wrapper, pushed } = await mountView('/administracion/cursos/Y1/editar')

    expect((wrapper.find('#curso-form-code').element as HTMLInputElement).value).toBe('2026-2027')

    await fill(wrapper, { ends_on: '2027-06-29' })
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(updateAcademicYear).toHaveBeenCalledWith('Y1', { ends_on: '2027-06-29' })
    expect(pushed).toEqual(['/administracion/cursos/Y1'])
  })

  it('sin cambios no envía ninguna petición', async () => {
    const { wrapper, pushed } = await mountView('/administracion/cursos/Y1/editar')

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(updateAcademicYear).not.toHaveBeenCalled()
    expect(pushed).toEqual(['/administracion/cursos/Y1'])
  })

  it.each(['activo', 'cerrado', 'archivado'])(
    'un curso %s no se edita: estado propio sin formulario (la API daría 409)',
    async (status) => {
      getAcademicYear.mockResolvedValue(year(status))
      const { wrapper } = await mountView('/administracion/cursos/Y1/editar')

      expect(wrapper.find('form').exists()).toBe(false)
      expect(wrapper.text()).toContain('ya no se puede editar')
      expect(wrapper.find('a[href="/administracion/cursos/Y1"]').exists()).toBe(true)
    },
  )
})
