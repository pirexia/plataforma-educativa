/**
 * Utilidades compartidas por los tests de las pantallas de `alum` (`1.15-d`). Datos de ejemplo según `REQ-SEED-005`:
 * nombres ficticios, sin datos reales; documento ya enmascarado como lo entrega la API (`api.md §1`).
 */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { nextTick, type Component } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError } from '@/api/client'
import { i18n } from '@/i18n'

const stub = { template: '<div/>' }

const AUDIT = { created_at: '2026-11-01T09:00:00Z', updated_at: '2026-11-01T09:00:00Z' }

export function enrollment(over: Record<string, unknown> = {}) {
  return {
    public_id: 'E1',
    student: { public_id: 'S1', display_name: 'Lucas Ejemplo Ruiz' },
    academic_year: { public_id: 'AY1', code: '2026-2027' },
    course: { public_id: 'C1', code: '1ESO', name_translations: { 'es-ES': 'Primero de ESO' } },
    group: { public_id: 'G1', code: '1ESO-A', name: '1º ESO A' },
    status: 'activa',
    enrolled_on: '2026-09-10',
    withdrawn_on: null,
    withdrawal_reason: null,
    ...AUDIT,
    ...over,
  }
}

export function studentSummary(over: Record<string, unknown> = {}) {
  return {
    public_id: 'S1',
    person: { public_id: 'P1' },
    display_name: 'Lucas Ejemplo Ruiz',
    birth_date: '2015-04-12',
    enrollment: null,
    ...AUDIT,
    ...over,
  }
}

export function student(over: Record<string, unknown> = {}) {
  return {
    public_id: 'S1',
    person: { public_id: 'P1' },
    display_name: 'Lucas Ejemplo Ruiz',
    given_name: 'Lucas',
    family_name_1: 'Ejemplo',
    family_name_2: 'Ruiz',
    birth_date: '2015-04-12',
    locale: 'es-ES',
    document_type: 'dni',
    document_number: '•••••6789',
    has_photo: false,
    enrollments: [],
    ...AUDIT,
    ...over,
  }
}

export function link(over: Record<string, unknown> = {}) {
  return {
    public_id: 'L1',
    guardian: 'G1',
    student_person: 'P1',
    relationship: 'madre',
    is_legal_guardian: true,
    legal_guardian_verified_at: AUDIT.created_at,
    access_level: 'lectura_escritura',
    restricted_categories: [],
    is_emergency_contact: true,
    status: 'activo',
    ...AUDIT,
    ...over,
  }
}

export function consent(over: Record<string, unknown> = {}) {
  return {
    student_person: 'P1',
    display_name: 'Lucas Ejemplo Ruiz',
    family_unit: 'U1',
    level: 'web',
    state: 'autorizo',
    last_decision_at: '2026-10-27T10:00:00Z',
    ...over,
  }
}

export function page<T>(rows: T[]) {
  return { data: rows, meta: { current_page: 1, per_page: 25, total: rows.length, last_page: 1 } }
}

/** Router de memoria con las rutas de `alum` (la de la vista bajo prueba usa su componente). */
export function makeRouter(name: string, component: Component) {
  const routes = [
    ['alum-students', '/alumnado'],
    ['alum-student-detail', '/alumnado/:publicId'],
  ].map(([routeName, routePath]) => ({
    path: routePath!,
    name: routeName!,
    component: routeName === name ? component : stub,
    meta: { layout: 'app' as const },
  }))

  return createRouter({ history: createMemoryHistory(), routes })
}

export async function mountView(
  name: string,
  component: Component,
  url: string,
): Promise<{ wrapper: VueWrapper; router: ReturnType<typeof makeRouter> }> {
  const router = makeRouter(name, component)

  await router.push(url)
  await router.isReady()

  const wrapper = mount(component, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  await flushPromises()

  return { wrapper, router }
}

/** `matchMedia` y `scrollIntoView` no existen en jsdom y los usan la tabla de datos y el `Select` de Reka UI. */
export function stubMatchMedia(): void {
  Element.prototype.scrollIntoView = () => undefined
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

/** Rellena un campo (el diálogo se renderiza en `document.body` por el portal) y notifica a Vue. */
export async function fill(selector: string, value: string): Promise<void> {
  const input = document.body.querySelector<HTMLInputElement | HTMLTextAreaElement>(selector)

  if (!input) {
    throw new Error(`No existe ${selector}`)
  }

  input.value = value
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
}

export async function submitForm(slot: string): Promise<void> {
  const form = document.body.querySelector<HTMLFormElement>(`[data-slot="${slot}"] form`)

  if (!form) {
    throw new Error(`No hay formulario ${slot} abierto`)
  }

  form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
  await flushPromises()
}

export function buttonByText(text: string): HTMLButtonElement | undefined {
  return [...document.body.querySelectorAll<HTMLButtonElement>('button')].find((button) =>
    button.textContent?.trim().includes(text),
  )
}

/** Un curso académico de `GET /academic-years/selectable`. */
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

/** Curso de etapa y grupo de `acad` (solo lo que usa el listado). */
export function course(over: Record<string, unknown> = {}) {
  return { public_id: 'C1', code: '1ESO', name: 'Primero de ESO', ...over }
}

export function group(over: Record<string, unknown> = {}) {
  return { public_id: 'G1', code: '1ESO-A', name: '1º ESO A', ...over }
}

/** Un error de la API (`ADR-038 §6`): `detail` ya traducido por el servidor y errores por campo con su `code`. */
export function problem(
  status: number,
  detail?: string,
  errors?: Record<string, { code: string; message: string }[]>,
): ApiError {
  return new ApiError('x', status, { type: 'urn:pge:error:x', status, detail, errors })
}

/** Abre un `Select` de Reka UI por su disparador (`id`) y elige la opción por su texto. */
export async function chooseOption(triggerSelector: string, label: string): Promise<void> {
  const trigger = document.body.querySelector<HTMLElement>(triggerSelector)

  if (!trigger) {
    throw new Error(`No existe ${triggerSelector}`)
  }

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

/** Una solicitud de cambio de grupo tal como la entrega la API (`api.md §3`). */
export function groupChangeRequest(over: Record<string, unknown> = {}) {
  return {
    public_id: 'R1',
    enrollment: {
      public_id: 'E1',
      student: { public_id: 'S1', display_name: 'Lucas Ejemplo Ruiz' },
    },
    academic_year: { public_id: 'Y2', code: '2026-2027' },
    from_group: { public_id: 'G1', code: '1ESO-A', name: '1º ESO A' },
    to_group: { public_id: 'G2', code: '1ESO-B', name: '1º ESO B' },
    reason: 'Motivo de ejemplo',
    status: 'pendiente',
    requested_by: { public_id: 'U1', display_name: 'Secretaría Ejemplo' },
    decided_by: null,
    decided_at: null,
    decision_note: null,
    ...AUDIT,
    ...over,
  }
}
