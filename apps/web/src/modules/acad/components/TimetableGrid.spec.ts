/**
 * `docs/modulos/REQ-ACAD/funcional.md §18.8`, `CA-ACAD-134` (rejilla): semántica
 * de tabla con cabeceras de día y de hora, recorrido con teclado, cada sesión con
 * su texto (asignatura, grupo, profesor, aula), lista por día a 320 px sin
 * desplazamiento horizontal y acciones solo con permiso. `REQ-ACAD-002`.
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { i18n, setLocale } from '@/i18n'
import TimetableGrid from './TimetableGrid.vue'
import { slot, timetableSession } from '../testSupport'
import type { TimeSlot, TimetableSession } from '../types'

const wrappers: VueWrapper[] = []

const SLOTS = [
  slot({ public_id: 'T1', weekday: 1, starts_at: '08:30', ends_at: '09:25' }),
  slot({ public_id: 'T2', weekday: 2, starts_at: '08:30', ends_at: '09:25' }),
  slot({ public_id: 'T3', weekday: 1, starts_at: '09:25', ends_at: '10:20' }),
] as TimeSlot[]

function mountGrid(
  props: Partial<{
    sessions: TimetableSession[]
    canCreate: boolean
    canEdit: boolean
    frameNotes: Record<string, string>
  }> = {},
) {
  const wrapper = mount(TimetableGrid, {
    props: {
      slots: SLOTS,
      sessions: [timetableSession() as TimetableSession],
      canCreate: false,
      canEdit: false,
      caption: 'Horario semanal por grupo',
      ...props,
    },
    global: { plugins: [i18n] },
    attachTo: document.body,
  })

  wrappers.push(wrapper)

  return wrapper
}

function matchMedia(narrow: boolean): void {
  Object.defineProperty(window, 'matchMedia', {
    configurable: true,
    writable: true,
    value: (query: string) => ({
      matches: narrow,
      media: query,
      addEventListener: () => undefined,
      removeEventListener: () => undefined,
    }),
  })
}

beforeEach(() => {
  setLocale('es')
  matchMedia(false)
})

afterEach(() => {
  wrappers.splice(0).forEach((wrapper) => wrapper.unmount())
})

describe('CA-ACAD-134: semántica de tabla y contenido de las sesiones', () => {
  it('es una tabla con cabeceras de día y de hora, filas, y celdas por día e intervalo', () => {
    const wrapper = mountGrid()
    const table = wrapper.get('[role="table"]')

    expect(table.attributes('aria-label')).toBe('Horario semanal por grupo')
    expect(wrapper.findAll('[role="columnheader"]').map((node) => node.text())).toEqual(
      expect.arrayContaining(['Lunes', 'Martes']),
    )
    expect(wrapper.findAll('[role="rowheader"]').map((node) => node.text())).toEqual([
      '08:30–09:25',
      '09:25–10:20',
    ])
    expect(wrapper.findAll('[role="row"]')).toHaveLength(3)
    expect(wrapper.findAll('[role="cell"]')).toHaveLength(4)
  })

  it('cada sesión muestra como texto asignatura, grupo, profesor y aula; un subgrupo se añade al grupo', () => {
    const wrapper = mountGrid({
      sessions: [
        timetableSession({ subgroup: { public_id: 'S1', code: 'D1', name: 'Desdoble 1' } }),
      ] as TimetableSession[],
    })
    const cell = wrapper.get('[data-cell="0-0"]')

    expect(cell.text()).toContain('Matemáticas')
    expect(cell.text()).toContain('A / D1')
    expect(cell.text()).toContain('Ana Ejemplo')
    expect(cell.text()).toContain('A21')
  })

  it('un aula dada de baja se marca con texto (RN-ACAD-102)', () => {
    const wrapper = mountGrid({
      sessions: [
        timetableSession({
          facility: { public_id: 'F1', code: 'A21', name: 'Aula 2.1', deleted: true },
        }),
      ] as TimetableSession[],
    })

    expect(wrapper.get('[data-cell="0-0"]').text()).toContain('aula dada de baja')
  })

  it('una celda sin franja avisa con texto para lectores de pantalla', () => {
    const wrapper = mountGrid({ sessions: [] })

    expect(wrapper.get('[data-cell="1-1"]').text()).toContain('Sin franja')
  })

  it('sin franjas ni sesiones muestra un estado vacío', () => {
    const wrapper = mountGrid({ sessions: [] as TimetableSession[] })

    expect(wrapper.find('[role="table"]').exists()).toBe(true)

    const empty = mount(TimetableGrid, {
      props: { slots: [], sessions: [], canCreate: false, canEdit: false, caption: 'x' },
      global: { plugins: [i18n] },
    })

    expect(empty.get('[data-slot="acad-timetable-empty"]').text()).toContain('No hay franjas')
    empty.unmount()
  })
})

describe('CA-ACAD-134: teclado', () => {
  it('una sola celda está en el orden de tabulación y las flechas, Inicio y Fin mueven el foco', async () => {
    const wrapper = mountGrid()
    const cells = wrapper.findAll('[role="cell"]')

    expect(cells.filter((cell) => cell.attributes('tabindex') === '0')).toHaveLength(1)
    expect(wrapper.get('[data-cell="0-0"]').attributes('tabindex')).toBe('0')

    await wrapper.get('[data-cell="0-0"]').trigger('keydown', { key: 'ArrowRight' })
    expect(document.activeElement).toBe(wrapper.get('[data-cell="0-1"]').element)
    expect(wrapper.get('[data-cell="0-1"]').attributes('tabindex')).toBe('0')
    expect(wrapper.get('[data-cell="0-0"]').attributes('tabindex')).toBe('-1')

    await wrapper.get('[data-cell="0-1"]').trigger('keydown', { key: 'ArrowDown' })
    expect(document.activeElement).toBe(wrapper.get('[data-cell="1-1"]').element)

    await wrapper.get('[data-cell="1-1"]').trigger('keydown', { key: 'Home' })
    expect(document.activeElement).toBe(wrapper.get('[data-cell="1-0"]').element)

    await wrapper.get('[data-cell="1-0"]').trigger('keydown', { key: 'End' })
    expect(document.activeElement).toBe(wrapper.get('[data-cell="1-1"]').element)

    await wrapper.get('[data-cell="1-1"]').trigger('keydown', { key: 'ArrowUp' })
    await wrapper.get('[data-cell="0-1"]').trigger('keydown', { key: 'ArrowLeft' })
    expect(document.activeElement).toBe(wrapper.get('[data-cell="0-0"]').element)

    // No se sale de la rejilla.
    await wrapper.get('[data-cell="0-0"]').trigger('keydown', { key: 'ArrowLeft' })
    expect(document.activeElement).toBe(wrapper.get('[data-cell="0-0"]').element)
  })

  it('Intro en una celda vacía con franja pide crear (solo con permiso); en una con sesión, no', async () => {
    const wrapper = mountGrid({ canCreate: true })

    await wrapper.get('[data-cell="0-1"]').trigger('keydown', { key: 'Enter' })
    expect(wrapper.emitted('create')).toEqual([[SLOTS[1]]])

    await wrapper.get('[data-cell="0-0"]').trigger('keydown', { key: 'Enter' })
    expect(wrapper.emitted('create')).toHaveLength(1)

    const readOnly = mountGrid({ canCreate: false })

    await readOnly.get('[data-cell="0-1"]').trigger('keydown', { key: 'Enter' })
    expect(readOnly.emitted('create')).toBeUndefined()
  })
})

describe('CA-ACAD-134: acciones por permiso', () => {
  it('sin permisos no hay botones; con ellos, «Añadir sesión» en las celdas libres con franja y «Editar» en las sesiones', async () => {
    const readOnly = mountGrid()

    expect(readOnly.findAll('button')).toHaveLength(0)

    const writer = mountGrid({ canCreate: true, canEdit: true })
    const buttons = writer.findAll('button').map((button) => button.text())

    expect(buttons.filter((text) => text.startsWith('Añadir sesión'))).toHaveLength(2)
    expect(buttons.filter((text) => text.startsWith('Editar'))).toHaveLength(1)

    await writer
      .findAll('button')
      .find((button) => button.text().startsWith('Editar'))!
      .trigger('click')
    expect(writer.emitted('edit')).toHaveLength(1)

    await writer
      .findAll('button')
      .find((button) => button.text().startsWith('Añadir'))!
      .trigger('click')
    expect(writer.emitted('create')).toHaveLength(1)
  })
})

describe('CA-ACAD-134: a 320 px, lista por día sin desplazamiento horizontal', () => {
  it('con pantalla estrecha no hay tabla: hay una sección por día con una lista de intervalos', () => {
    matchMedia(true)

    const wrapper = mountGrid({ canCreate: true, canEdit: true })

    expect(wrapper.find('[role="table"]').exists()).toBe(false)
    expect(wrapper.find('[data-slot="acad-timetable-days"]').exists()).toBe(true)

    const headings = wrapper.findAll('h3').map((node) => node.text())

    expect(headings).toEqual(['Lunes', 'Martes'])

    const monday = wrapper.findAll('section')[0]!

    expect(monday.findAll('li')).toHaveLength(2)
    expect(monday.text()).toContain('Matemáticas')
    expect(monday.text()).toContain('09:25–10:20')
    expect(monday.text()).toContain('Libre')
    expect(wrapper.html()).not.toContain('overflow-x')
  })
})

describe('CA-ACAD-137 (RN-ACAD-124, 1.12-b): la sesión de una jornada especial lleva su vigencia en texto', () => {
  const NOTES = { FR2: 'Jornada intensiva · Del 7 dic 2026 al 11 dic 2026' }
  const SESSIONS = [
    timetableSession(),
    timetableSession({ public_id: 'SE2', frame: 'FR2' }),
  ] as TimetableSession[]

  it('en la rejilla, solo las sesiones de un marco especial llevan la nota; las del ordinario, no', () => {
    const wrapper = mountGrid({ sessions: SESSIONS, frameNotes: NOTES })
    const cell = wrapper.get('[data-cell="0-0"]')

    expect(cell.text().match(/Jornada intensiva/g)).toHaveLength(1)
    expect(cell.text()).toContain('Del 7 dic 2026 al 11 dic 2026')
    expect(cell.findAll('span').filter((node) => node.text().startsWith('Jornada'))).toHaveLength(1)
  })

  it('en la lista por día (320 px) también', () => {
    matchMedia(true)

    const wrapper = mountGrid({ sessions: SESSIONS, frameNotes: NOTES })

    expect(
      wrapper
        .get('[data-slot="acad-timetable-days"]')
        .text()
        .match(/Jornada intensiva/g),
    ).toHaveLength(1)
  })

  it('sin notas no se pinta ninguna', () => {
    const wrapper = mountGrid({ sessions: SESSIONS })

    expect(wrapper.text()).not.toContain('Jornada')
  })
})
