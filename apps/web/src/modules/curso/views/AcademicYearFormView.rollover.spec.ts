/**
 * `docs/modulos/REQ-CURSO/funcional.md §10.6` entrada (a), `CA-CURSO-143` (1.12b): «Abrir curso siguiente» abre el alta de
 * curso y, al crear, lleva al asistente de copia del curso recién creado — solo con `apertura_curso_academico.crear`; sin él
 * (o sin `?next=rollover`) el alta ordinaria sigue yendo a la ficha.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'

const createAcademicYear = vi.fn()

vi.mock('../api', () => ({
  createAcademicYear: (...args: unknown[]) => createAcademicYear(...args),
  getAcademicYear: vi.fn(),
  updateAcademicYear: vi.fn(),
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
const { default: AcademicYearFormView } = await import('./AcademicYearFormView.vue')

const wrappers: VueWrapper[] = []

async function createFrom(url: string): Promise<string> {
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
        path: '/administracion/cursos/:publicId/copiar-estructura',
        name: 'curso-rollover-wizard',
        component: stub,
      },
    ],
  })

  await router.push(url)
  await router.isReady()

  const wrapper = mount(AcademicYearFormView, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  for (const [field, value] of Object.entries({
    code: '2027-2028',
    starts_on: '2027-09-01',
    ends_on: '2028-06-30',
  })) {
    await wrapper.find(`#curso-form-${field}`).setValue(value)
  }

  await wrapper.find('form').trigger('submit')
  await flushPromises()

  return router.currentRoute.value.fullPath
}

beforeEach(() => {
  setLocale('es')
  createAcademicYear.mockReset().mockResolvedValue({
    public_id: 'NEW1',
    code: '2027-2028',
    starts_on: '2027-09-01',
    ends_on: '2028-06-30',
    status: 'planificacion',
    created_at: '2026-06-01T10:00:00Z',
    updated_at: '2026-06-01T10:00:00Z',
  })
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('CA-CURSO-143 (funcional.md §10.6 entrada (a)): alta encadenada con el asistente', () => {
  it('con ?next=rollover y apertura_curso_academico.crear, al crear lleva al asistente del curso nuevo', async () => {
    session.__setPermissions(['curso_academico.crear', 'apertura_curso_academico.crear'])

    expect(await createFrom('/administracion/cursos/nuevo?next=rollover')).toBe(
      '/administracion/cursos/NEW1/copiar-estructura',
    )
  })

  it('sin apertura_curso_academico.crear, o sin ?next, lleva a la ficha como siempre', async () => {
    session.__setPermissions(['curso_academico.crear'])
    expect(await createFrom('/administracion/cursos/nuevo?next=rollover')).toBe(
      '/administracion/cursos/NEW1',
    )

    wrappers.pop()!.unmount()
    document.body.innerHTML = ''
    session.__setPermissions(['curso_academico.crear', 'apertura_curso_academico.crear'])
    expect(await createFrom('/administracion/cursos/nuevo')).toBe('/administracion/cursos/NEW1')
  })
})
