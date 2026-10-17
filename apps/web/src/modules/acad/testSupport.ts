/**
 * Utilidades compartidas por los tests de las pantallas de `acad`. Datos de
 * ejemplo según `REQ-SEED-005` (nombres ficticios, sin datos reales).
 */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { nextTick, type Component } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n } from '@/i18n'

const stub = { template: '<div/>' }

export function year(id: string, code: string, status: string) {
  return {
    public_id: id,
    code,
    starts_on: '2026-09-01',
    ends_on: '2027-06-30',
    status,
    created_at: '2026-06-01T10:00:00Z',
    updated_at: '2026-06-01T10:00:00Z',
  }
}

const AUDIT = { created_at: '2026-06-01T10:00:00Z', updated_at: '2026-06-01T10:00:00Z' }

export function level(over: Record<string, unknown> = {}) {
  return {
    public_id: 'L1',
    academic_year: 'Y2',
    code: 'ESO',
    name: 'Educación Secundaria Obligatoria',
    name_translations: {
      'es-ES': 'Educación Secundaria Obligatoria',
      en: 'Compulsory Secondary Education',
    },
    stage: 'eso',
    regime: 'concertado',
    position: 3,
    ...AUDIT,
    ...over,
  }
}

export function course(over: Record<string, unknown> = {}) {
  return {
    public_id: 'C1',
    academic_year: 'Y2',
    education_level: 'L1',
    code: '2ESO',
    name: '2.º ESO',
    name_translations: { 'es-ES': '2.º ESO' },
    position: 2,
    ...AUDIT,
    ...over,
  }
}

export function group(over: Record<string, unknown> = {}) {
  return {
    public_id: 'G1',
    academic_year: 'Y2',
    course: 'C1',
    code: 'A',
    name: '2.º ESO A',
    position: 1,
    ...AUDIT,
    ...over,
  }
}

export function subgroup(over: Record<string, unknown> = {}) {
  return {
    public_id: 'S1',
    academic_year: 'Y2',
    group: 'G1',
    code: 'D1',
    name: 'Desdoble 1',
    position: 1,
    ...AUDIT,
    ...over,
  }
}

export function subject(over: Record<string, unknown> = {}) {
  return {
    public_id: 'M1',
    academic_year: 'Y2',
    course: 'C1',
    code: 'MAT',
    name: 'Matemáticas',
    name_translations: { 'es-ES': 'Matemáticas', en: 'Mathematics' },
    position: 1,
    ...AUDIT,
    ...over,
  }
}

export function page<T>(rows: T[]) {
  return { data: rows, meta: { current_page: 1, per_page: 25, total: rows.length, last_page: 1 } }
}

/** Router de memoria con las cinco rutas de `acad` (las de la vista bajo prueba usan su componente). */
export function makeRouter(name: string, component: Component) {
  const routes = [
    ['acad-structure', '/academico/estructura'],
    ['acad-level-detail', '/academico/etapas/:publicId'],
    ['acad-course-detail', '/academico/cursos/:publicId'],
    ['acad-group-detail', '/academico/grupos/:publicId'],
    ['acad-subject-detail', '/academico/asignaturas/:publicId'],
  ].map(([routeName, routePath]) => ({
    path: routePath!,
    name: routeName!,
    component: routeName === name ? component : stub,
    meta: { layout: 'app' as const, academicYearScoped: true },
  }))

  return createRouter({ history: createMemoryHistory(), routes })
}

export async function mountView(
  name: string,
  component: Component,
  url: string,
): Promise<VueWrapper> {
  const router = makeRouter(name, component)

  await router.push(url)
  await router.isReady()

  const wrapper = mount(component, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  await flushPromises()

  return wrapper
}

export function stubMatchMedia(): void {
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
}

/** Rellena un campo del diálogo (renderizado en `document.body` por el portal) y notifica a Vue. */
export async function fill(selector: string, value: string): Promise<void> {
  const input = document.body.querySelector<HTMLInputElement>(selector)

  if (!input) {
    throw new Error(`No existe ${selector}`)
  }

  input.value = value
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
}

export function dialogForm(): HTMLFormElement {
  const form = document.body.querySelector<HTMLFormElement>('[data-slot="acad-entity-form"] form')

  if (!form) {
    throw new Error('No hay formulario abierto')
  }

  return form
}

export async function submitForm(): Promise<void> {
  dialogForm().dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
  await flushPromises()
}

export function buttonByText(text: string): HTMLButtonElement | undefined {
  return [...document.body.querySelectorAll<HTMLButtonElement>('button')].find((button) =>
    button.textContent?.includes(text),
  )
}

export function period(over: Record<string, unknown> = {}) {
  return {
    public_id: 'P1',
    academic_year: 'Y2',
    education_level: 'L1',
    period_type: 'trimestre',
    position: 1,
    name: 'Primer trimestre',
    name_translations: { 'es-ES': 'Primer trimestre', en: 'First term' },
    starts_on: '2025-09-08',
    ends_on: '2025-12-22',
    ...AUDIT,
    ...over,
  }
}
