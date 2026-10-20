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

/** Router de memoria con las rutas de `acad` (las de la vista bajo prueba usan su componente). */
export function makeRouter(name: string, component: Component) {
  const routes = [
    ['acad-structure', '/academico/estructura'],
    ['acad-level-detail', '/academico/etapas/:publicId'],
    ['acad-course-detail', '/academico/cursos/:publicId'],
    ['acad-group-detail', '/academico/grupos/:publicId'],
    ['acad-subject-detail', '/academico/asignaturas/:publicId'],
    ['acad-grading-scales', '/academico/escalas'],
    ['acad-grading-scale-new', '/academico/escalas/nueva'],
    ['acad-grading-scale-detail', '/academico/escalas/:publicId'],
    ['acad-grading-scale-edit', '/academico/escalas/:publicId/editar'],
    ['acad-facilities', '/academico/espacios'],
    ['acad-timetable', '/academico/horarios'],
    ['acad-timetable-frame', '/academico/marcos/:publicId'],
    ['acad-bookings', '/academico/reservas'],
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

export function scaleValue(over: Record<string, unknown> = {}) {
  return {
    public_id: 'V1',
    code: 'IN',
    position: 1,
    label: 'Insuficiente',
    label_translations: { 'es-ES': 'Insuficiente' },
    is_passing: false,
    range_min: null,
    range_max: null,
    ...over,
  }
}

/** Escala cualitativa de dos valores por defecto. */
export function scale(over: Record<string, unknown> = {}) {
  return {
    public_id: 'E1',
    academic_year: 'Y2',
    code: 'CUAL',
    name: 'Insuficiente a Sobresaliente',
    name_translations: { 'es-ES': 'Insuficiente a Sobresaliente' },
    scale_type: 'cualitativa',
    min_value: null,
    max_value: null,
    decimals: null,
    passing_min_value: null,
    values: [
      scaleValue(),
      scaleValue({
        public_id: 'V2',
        code: 'SU',
        position: 2,
        label: 'Suficiente',
        label_translations: { 'es-ES': 'Suficiente' },
        is_passing: true,
      }),
    ],
    ...AUDIT,
    ...over,
  }
}

/** Escala mixta 0-10 con un decimal y dos tramos contiguos. */
export function bandScale(over: Record<string, unknown> = {}) {
  return scale({
    public_id: 'E2',
    code: 'MIX10',
    name: 'De 0 a 10 con calificación',
    name_translations: { 'es-ES': 'De 0 a 10 con calificación' },
    scale_type: 'mixta',
    min_value: '0.0',
    max_value: '10.0',
    decimals: 1,
    passing_min_value: '5.0',
    values: [
      scaleValue({ range_min: '0.0', range_max: '4.9' }),
      scaleValue({
        public_id: 'V2',
        code: 'SU',
        position: 2,
        label: 'Suficiente',
        label_translations: { 'es-ES': 'Suficiente' },
        is_passing: true,
        range_min: '5.0',
        range_max: '10.0',
      }),
    ],
    ...over,
  })
}

export function facility(over: Record<string, unknown> = {}) {
  return {
    public_id: 'F1',
    facility_type: 'aula',
    code: 'A21',
    name: 'Aula 2.1',
    ...AUDIT,
    ...over,
  }
}

export function slot(over: Record<string, unknown> = {}) {
  return { public_id: 'T1', weekday: 1, starts_at: '08:30', ends_at: '09:25', ...over }
}

/** Marco ordinario de la etapa `L1` con dos franjas el lunes. */
export function frame(over: Record<string, unknown> = {}) {
  return {
    public_id: 'M1',
    academic_year: 'Y2',
    education_level: 'L1',
    frame_type: 'ordinaria',
    name: 'Horario general',
    name_translations: { 'es-ES': 'Horario general' },
    valid_from: null,
    valid_to: null,
    slots: [slot(), slot({ public_id: 'T2', starts_at: '09:25', ends_at: '10:20' })],
    ...AUDIT,
    ...over,
  }
}

/** `api.md §12.1`: sesión con los resúmenes de la rejilla. */
export function timetableSession(over: Record<string, unknown> = {}) {
  return {
    public_id: 'SE1',
    academic_year: 'Y2',
    frame: 'FR1',
    slot: slot(),
    teaching_assignment: 'TA1',
    teacher: { public_id: 'U1', name: 'Ana Ejemplo' },
    group: { public_id: 'G1', code: 'A', name: '2.º ESO A' },
    subgroup: null,
    subject: { public_id: 'M1', code: 'MAT', name: 'Matemáticas' },
    facility: { public_id: 'F1', code: 'A21', name: 'Aula 2.1', deleted: false },
    ...AUDIT,
    ...over,
  }
}

/** `api.md §12.1`: asignación docente. */
export function teachingAssignment(over: Record<string, unknown> = {}) {
  return {
    public_id: 'TA1',
    academic_year: 'Y2',
    teacher: { public_id: 'U1', name: 'Ana Ejemplo' },
    group: 'G1',
    subject: 'M1',
    subgroup: null,
    ...AUDIT,
    ...over,
  }
}

/** `api.md §12.1` (1.12-b): reserva del recurso `CARRO1` el 12 nov 2026 de 10:00 a 11:00. */
export function facilityBooking(over: Record<string, unknown> = {}) {
  return {
    public_id: 'B1',
    academic_year: 'Y2',
    facility: {
      public_id: 'F2',
      facility_type: 'recurso',
      code: 'CARRO1',
      name: 'Carro de portátiles',
      deleted: false,
    },
    booked_on: '2026-11-12',
    starts_at: '10:00',
    ends_at: '11:00',
    booked_by: { public_id: 'ME', name: 'Ana Ejemplo' },
    created_at: '2026-06-01T10:00:00Z',
    ...over,
  }
}

/**
 * Elige una opción de un `Select` de Reka UI (se abre con teclado, `role="option"`)
 * por su texto exacto; el contenido se renderiza en `document.body` por el portal.
 */
export async function chooseOption(triggerSelector: string, optionText: string): Promise<void> {
  const trigger = document.body.querySelector<HTMLElement>(triggerSelector)

  if (!trigger) {
    throw new Error(`No existe ${triggerSelector}`)
  }

  trigger.focus()
  trigger.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
  await flushPromises()

  const option = [...document.body.querySelectorAll<HTMLElement>('[role="option"]')].find((item) =>
    item.textContent?.trim().includes(optionText),
  )

  if (!option) {
    throw new Error(`No existe la opción «${optionText}»`)
  }

  option.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerType: 'mouse' }))
  option.click()
  await flushPromises()
}
