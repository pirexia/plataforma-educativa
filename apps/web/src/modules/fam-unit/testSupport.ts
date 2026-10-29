/**
 * Utilidades compartidas por los tests de las pantallas de `fam-unit` (`1.14-d`). Datos de ejemplo según `REQ-SEED-005`:
 * nombres ficticios, dominios `@example.com`, sin datos reales.
 */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { nextTick, type Component } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n } from '@/i18n'

const stub = { template: '<div/>' }

const AUDIT = { created_at: '2026-10-26T09:00:00Z', updated_at: '2026-10-26T09:00:00Z' }

export function familyUnit(over: Record<string, unknown> = {}) {
  return {
    public_id: 'U1',
    code: 'UF-000123',
    principal_guardian: 'G1',
    guardians_count: 2,
    students_count: 1,
    ...AUDIT,
    ...over,
  }
}

export function guardian(over: Record<string, unknown> = {}) {
  return {
    public_id: 'G1',
    family_unit: 'U1',
    user: 'USR1',
    display_name: 'Marta Ejemplo Ruiz',
    is_principal: true,
    ...AUDIT,
    ...over,
  }
}

export function student(over: Record<string, unknown> = {}) {
  return {
    public_id: 'M1',
    family_unit: 'U1',
    student_person: 'P1',
    display_name: 'Lucas Ejemplo Ruiz',
    created_at: AUDIT.created_at,
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
    revocation: null,
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

export function decision(over: Record<string, unknown> = {}) {
  return {
    public_id: 'D1',
    student_person: 'P1',
    level: 'web',
    decision: 'autorizo',
    guardian: 'G1',
    recorded_by: 'USR1',
    channel: 'presencial',
    consent_text_version: { public_id: 'V1', kind: 'consentimiento_imagen_web', version_number: 2 },
    shown_locale: null,
    occurred_at: '2026-10-27T10:00:00Z',
    ...over,
  }
}

export function termsVersion(over: Record<string, unknown> = {}) {
  return {
    public_id: 'V1',
    kind: 'condiciones_uso',
    version_number: 1,
    title: 'Condiciones de uso del centro',
    title_translations: { 'es-ES': 'Condiciones de uso del centro' },
    body: 'Texto de las condiciones.',
    body_translations: { 'es-ES': 'Texto de las condiciones.' },
    status: 'borrador',
    requires_reacceptance: false,
    published_at: null,
    ...AUDIT,
    ...over,
  }
}

export function page<T>(rows: T[]) {
  return { data: rows, meta: { current_page: 1, per_page: 25, total: rows.length, last_page: 1 } }
}

export function cursorPage<T>(rows: T[], next: string | null = null) {
  return { data: rows, meta: { next_cursor: next, has_more: next !== null } }
}

/** Router de memoria con las rutas de `fam-unit` (la de la vista bajo prueba usa su componente). */
export function makeRouter(name: string, component: Component) {
  const routes = [
    ['fam-unit-list', '/familias'],
    ['fam-unit-consents', '/familias/consentimientos'],
    ['fam-unit-detail', '/familias/:publicId'],
    ['fam-unit-terms', '/configuracion/condiciones-de-uso'],
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

export function buttonByLabel(label: string): HTMLButtonElement | undefined {
  return (
    document.body.querySelector<HTMLButtonElement>(`button[aria-label="${label}"]`) ?? undefined
  )
}

/** Elige una opción de un `Select` de Reka UI (se abre con teclado) por su texto exacto. */
export async function chooseOption(triggerSelector: string, optionText: string): Promise<void> {
  const trigger = document.body.querySelector<HTMLElement>(triggerSelector)

  if (!trigger) {
    throw new Error(`No existe ${triggerSelector}`)
  }

  trigger.focus()
  trigger.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
  await flushPromises()

  const option = [...document.body.querySelectorAll<HTMLElement>('[role="option"]')].find(
    (item) => item.textContent?.trim() === optionText,
  )

  if (!option) {
    throw new Error(`No existe la opción «${optionText}»`)
  }

  option.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerType: 'mouse' }))
  option.click()
  await flushPromises()
}
