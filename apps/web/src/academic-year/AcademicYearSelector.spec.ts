/**
 * `CA-ACAD-047`, `CA-ACAD-049` (REQ-ACAD-001, `RN-ACAD-40..42`): selector de
 * curso académico. Aparece solo con `meta.academicYearScoped`, ofrece lo que
 * devuelve `GET /academic-years/selectable`, el curso vive en la URL y nada se
 * escribe en `localStorage`.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'

const listSelectableAcademicYears = vi.fn()

vi.mock('@/modules/curso/api', () => ({
  listSelectableAcademicYears: (...args: unknown[]) => listSelectableAcademicYears(...args),
}))

vi.mock('@/session/useSession', async () => {
  const { shallowRef } = await import('vue')
  const user = shallowRef<{ public_id: string; permissions: string[] } | null>({
    public_id: 'ME',
    permissions: [],
  })

  return { useSession: () => ({ user }) }
})

const { default: AcademicYearSelector } = await import('./AcademicYearSelector.vue')
const { resetSelectableYears, usePinAcademicYear } = await import('./useAcademicYear')

function year(id: string, code: string, status: string) {
  return {
    public_id: id,
    code,
    starts_on: '2026-09-01',
    ends_on: '2027-06-30',
    status,
    created_at: '',
    updated_at: '',
  }
}

const wrappers: VueWrapper[] = []

async function mountAt(url: string) {
  const stub = { template: '<div/>' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/academico/estructura',
        name: 'scoped',
        component: stub,
        meta: { academicYearScoped: true },
      },
      { path: '/otra', name: 'other', component: stub, meta: {} },
    ],
  })

  await router.push(url)
  await router.isReady()

  const wrapper = mount(AcademicYearSelector, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return { wrapper, router }
}

beforeEach(() => {
  setLocale('es')
  resetSelectableYears()
  window.localStorage.clear()
  listSelectableAcademicYears.mockReset().mockResolvedValue({
    data: [
      year('Y3', '2026-2027', 'planificacion'),
      year('Y2', '2025-2026', 'activo'),
      year('Y1', '2024-2025', 'cerrado'),
    ],
  })
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-ACAD-047: el selector solo aparece en rutas con meta.academicYearScoped', () => {
  it('aparece en una ruta con academicYearScoped, etiquetado', async () => {
    const { wrapper } = await mountAt('/academico/estructura')

    expect(wrapper.find('[data-slot="academic-year-selector"]').exists()).toBe(true)
    expect(wrapper.find('label[for="academic-year-select"]').text()).toBe('Curso académico')
  })

  it('no aparece en una ruta sin la marca', async () => {
    const { wrapper } = await mountAt('/otra')

    expect(wrapper.find('[data-slot="academic-year-selector"]').exists()).toBe(false)
  })
})

describe('CA-ACAD-047: opciones, URL y almacenamiento', () => {
  it('pide las opciones a /academic-years/selectable una sola vez', async () => {
    await mountAt('/academico/estructura')

    expect(listSelectableAcademicYears).toHaveBeenCalledTimes(1)
  })

  it('sin parámetro muestra el curso activo; con ?academic_year, el de la URL', async () => {
    const first = await mountAt('/academico/estructura')

    expect(first.wrapper.find('[role="combobox"]').text()).toContain('2025-2026')

    const second = await mountAt('/academico/estructura?academic_year=Y3')

    expect(second.wrapper.find('[role="combobox"]').text()).toContain('2026-2027')
  })

  it('elegir otro curso navega con ?academic_year, descarta el resto de la query y no escribe en localStorage', async () => {
    const { router } = await mountAt('/academico/estructura?page=3&sort=-code')
    const setItem = vi.spyOn(Storage.prototype, 'setItem')

    const { useAcademicYear } = await import('./useAcademicYear')
    const holder = mount(
      { setup: () => useAcademicYear(), template: '<div/>' },
      { global: { plugins: [i18n, router] } },
    )
    wrappers.push(holder as VueWrapper)

    await (holder.vm as unknown as { select: (id: string) => Promise<unknown> }).select('Y1')
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({ academic_year: 'Y1' })
    expect(setItem).not.toHaveBeenCalled()
    setItem.mockRestore()
  })

  it('un curso cerrado se rotula con texto «solo lectura», no solo con color', async () => {
    const { wrapper } = await mountAt('/academico/estructura?academic_year=Y1')

    expect(wrapper.find('[role="combobox"]').text()).toContain('solo lectura')
  })
})

describe('§10.1 [DERIVADA]: en una ficha el selector queda fijo en el curso de la entidad', () => {
  it('muestra el curso fijado como texto, sin control', async () => {
    const { wrapper } = await mountAt('/academico/estructura')
    const pinned = mount(
      { setup: () => usePinAcademicYear(ref('Y1')), template: '<div/>' },
      { global: { plugins: [i18n] } },
    )
    wrappers.push(pinned as VueWrapper)
    await flushPromises()

    expect(wrapper.find('[data-slot="academic-year-pinned"]').text()).toContain('2024-2025')
    expect(wrapper.find('[role="combobox"]').exists()).toBe(false)
  })
})
