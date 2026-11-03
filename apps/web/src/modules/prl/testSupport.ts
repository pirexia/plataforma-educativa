/**
 * Utilidades compartidas por los tests de las pantallas de `prl` (`1.14b-c2`). Datos de ejemplo según `REQ-SEED-005`:
 * nombres ficticios, dominios `@example.com`, sin datos reales.
 */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { nextTick, type Component } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n } from '@/i18n'

const stub = { template: '<div/>' }

export function doorStudent(over: Record<string, unknown> = {}) {
  return {
    student_person: 'P1',
    display_name: 'Lucas Ejemplo Ruiz',
    family_unit_code: 'UF-000123',
    ...over,
  }
}

export function pickupEntry(over: Record<string, unknown> = {}) {
  return {
    person: 'PE1',
    display_name: 'Rosa Ejemplo Vidal',
    bases: [{ basis: 'tutor', relationship: 'madre', guardian_link: 'L1' }],
    has_photo: true,
    photo_url: 'https://almacen.example.com/foto-1?firma=abc',
    photo_url_expires_at: '2026-11-03T10:05:00Z',
    contact_phone: '600000001',
    document: { type: 'dni', masked: '•••••123X' },
    ...over,
  }
}

export function doorList(entries: unknown[] = [pickupEntry()]) {
  return { data: entries, meta: { as_of: '2026-11-03T09:30:00Z', protocol_available: true } }
}

export function pickupAlert(over: Record<string, unknown> = {}) {
  return {
    public_id: 'A1',
    student_person: 'P1',
    kind: 'persona_no_autorizada',
    presenter_description: 'Dice llamarse Pedro Ejemplo; enseña un documento ilegible',
    status: 'abierta',
    raised_by: 'USR1',
    raised_at: '2026-11-03T09:00:00Z',
    principal_contacted_at: null,
    resolution: null,
    resolution_notes: null,
    resolved_by: null,
    resolved_at: null,
    protocol: 'No entregues al alumno. Llama al tutor principal.',
    contacts: { principal_phone: '600000001', emergency_phone: null },
    ...over,
  }
}

export function page(rows: unknown[]) {
  return {
    data: rows,
    meta: { current_page: 1, per_page: 25, total: rows.length, last_page: 1 },
  }
}

/** Router de memoria con las rutas de `prl` (la de la vista bajo prueba usa su componente). */
export function makeRouter(name: string, component: Component) {
  const routes = [
    ['prl-door', '/conserjeria'],
    ['prl-door-student', '/conserjeria/alumnos/:studentPersonPublicId'],
    ['prl-alerts', '/conserjeria/alertas'],
    ['prl-alert-detail', '/conserjeria/alertas/:publicId'],
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

/** `matchMedia` y `scrollIntoView` no existen en jsdom y los usa la tabla de datos. */
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

export async function fill(selector: string, value: string): Promise<void> {
  const input = document.body.querySelector<HTMLInputElement>(selector)

  if (!input) {
    throw new Error(`No existe ${selector}`)
  }

  input.value = value
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
}

export function buttonByText(text: string): HTMLButtonElement | undefined {
  return [...document.body.querySelectorAll<HTMLButtonElement>('button')].find((button) =>
    button.textContent?.trim().includes(text),
  )
}
