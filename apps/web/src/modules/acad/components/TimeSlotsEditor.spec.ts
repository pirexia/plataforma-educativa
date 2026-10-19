/**
 * `docs/modulos/REQ-ACAD/funcional.md §18.8`, `CA-ACAD-118`, `CA-ACAD-119`,
 * `CA-ACAD-134` (parte del editor): lista de franjas accesible — un `fieldset`
 * con leyenda por franja, campos de hora nativos, errores del servidor junto al
 * campo y asociados con `aria-describedby`, añadir y quitar como botones (sin
 * arrastre, WCAG 2.5.7) y modo de solo lectura sin acciones. `REQ-ACAD-002`.
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { i18n, setLocale } from '@/i18n'
import TimeSlotsEditor, { type SlotRow } from './TimeSlotsEditor.vue'

const wrappers: VueWrapper[] = []

const ROWS: SlotRow[] = [
  { key: 1, public_id: 'T1', weekday: '1', starts_at: '08:30', ends_at: '09:25' },
  { key: 2, weekday: '2', starts_at: '09:25', ends_at: '10:20' },
]

function mountEditor(
  props: {
    modelValue: SlotRow[]
    errors?: Record<string, string[]>
    disabled?: boolean
  },
  stubSelects = false,
) {
  const wrapper = mount(TimeSlotsEditor, {
    props,
    // Con muchas filas, los Select de Reka UI dominan el coste del montaje: se sustituyen
    // solo en la prueba que cuenta filas (no comprueba el desplegable).
    global: {
      plugins: [i18n],
      stubs: stubSelects
        ? {
            Select: true,
            SelectTrigger: true,
            SelectValue: true,
            SelectContent: true,
            SelectItem: true,
          }
        : {},
    },
    attachTo: document.body,
  })

  wrappers.push(wrapper)

  return wrapper
}

beforeEach(() => {
  setLocale('es')
  Element.prototype.scrollIntoView = () => undefined
})

afterEach(() => {
  wrappers.splice(0).forEach((wrapper) => wrapper.unmount())
})

describe('CA-ACAD-134: estructura accesible del editor de franjas', () => {
  it('cada franja es un fieldset con su leyenda y tres campos con etiqueta; las horas son type=time', () => {
    const wrapper = mountEditor({ modelValue: ROWS })
    const sets = wrapper.findAll('fieldset')

    expect(sets).toHaveLength(2)
    expect(sets[0]!.find('legend').text()).toBe('Franja 1')
    expect(sets[1]!.find('legend').text()).toBe('Franja 2')

    for (const input of wrapper.findAll('input[type="time"]')) {
      const label = wrapper.find(`label[for="${input.attributes('id')}"]`)

      expect(label.exists()).toBe(true)
    }

    expect(wrapper.findAll('input[type="time"]')).toHaveLength(4)
    expect((wrapper.find('#acad-slot-1-starts').element as HTMLInputElement).value).toBe('08:30')
  })

  it('sin franjas se explica con texto y quedan solo el botón de añadir', () => {
    const wrapper = mountEditor({ modelValue: [] })

    expect(wrapper.text()).toContain('Este marco no tiene franjas.')
    expect(wrapper.findAll('fieldset')).toHaveLength(0)
    expect(wrapper.findAll('button').map((b) => b.text())).toEqual(['Añadir franja'])
  })
})

describe('CA-ACAD-118 CA-ACAD-119: edición del conjunto', () => {
  it('añadir propone la franja siguiente (mismo día, empieza donde acaba la última) sin public_id', async () => {
    const wrapper = mountEditor({ modelValue: ROWS })

    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Añadir franja')!
      .trigger('click')

    const emitted = wrapper.emitted('update:modelValue')!.at(-1)![0] as SlotRow[]

    expect(emitted).toHaveLength(3)
    expect(emitted[2]).toMatchObject({ weekday: '2', starts_at: '10:20', ends_at: '' })
    expect(emitted[2]!.public_id).toBeUndefined()
    expect(emitted[2]!.key).toBe(3)
  })

  it('quitar elimina la fila indicada y conserva el public_id de las demás', async () => {
    const wrapper = mountEditor({ modelValue: ROWS })

    await wrapper.find('button[aria-label="Quitar la franja 2"]').trigger('click')

    const emitted = wrapper.emitted('update:modelValue')!.at(-1)![0] as SlotRow[]

    expect(emitted).toEqual([ROWS[0]])
  })

  it('editar una hora emite la fila modificada sin tocar el public_id', async () => {
    const wrapper = mountEditor({ modelValue: ROWS })

    await wrapper.find('#acad-slot-1-starts').setValue('08:45')

    const emitted = wrapper.emitted('update:modelValue')!.at(-1)![0] as SlotRow[]

    expect(emitted[0]).toEqual({ ...ROWS[0], starts_at: '08:45' })
    expect(emitted[1]).toEqual(ROWS[1])
  })

  it('el tope de 100 franjas desactiva añadir (RN-ACAD-113)', () => {
    const many = Array.from({ length: 100 }, (_, i) => ({
      key: i + 1,
      weekday: '1',
      starts_at: '08:00',
      ends_at: '08:50',
    }))
    const wrapper = mountEditor({ modelValue: many }, true)

    expect(
      wrapper
        .findAll('button')
        .find((b) => b.text() === 'Añadir franja')!
        .attributes('disabled'),
    ).toBeDefined()
  })
})

describe('CA-ACAD-134: errores del servidor junto al campo, con texto', () => {
  it('slots.<n>.<campo> se pinta bajo su campo, con aria-invalid y aria-describedby, y slots en general arriba', () => {
    const wrapper = mountEditor({
      modelValue: ROWS,
      errors: {
        'slots.0.ends_at': ['La hora de fin debe ser posterior a la de inicio.'],
        'slots.1.starts_at': ['La franja se solapa con otra del mismo día.'],
        'slots.1.weekday': ['El día de la semana no es válido.'],
        slots: ['Un marco admite como máximo 100 franjas.'],
      },
    })

    const ends = wrapper.find('#acad-slot-1-ends')
    const starts = wrapper.find('#acad-slot-2-starts')

    expect(ends.attributes('aria-invalid')).toBe('true')
    expect(ends.attributes('aria-describedby')).toBe('acad-slot-1-ends-errors')
    expect(wrapper.find('#acad-slot-1-ends-errors').text()).toContain('posterior a la de inicio')
    expect(starts.attributes('aria-describedby')).toBe('acad-slot-2-starts-errors')
    expect(wrapper.find('#acad-slot-2-weekday-errors').text()).toContain('día de la semana')
    expect(
      wrapper
        .findAll('[role="alert"]')
        .map((node) => node.text())
        .join(' '),
    ).toContain('máximo 100 franjas')
    expect(wrapper.find('#acad-slot-1-starts').attributes('aria-invalid')).toBeUndefined()
  })
})

describe('modo de solo lectura', () => {
  it('disabled deshabilita los campos y oculta añadir y quitar', () => {
    const wrapper = mountEditor({ modelValue: ROWS, disabled: true })

    // Los selectores de día son botones (`combobox`); no hay botones de acción.
    expect(wrapper.findAll('button:not([role="combobox"])')).toHaveLength(0)
    expect(wrapper.find('#acad-slot-1-starts').attributes('disabled')).toBeDefined()
  })
})
