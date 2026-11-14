/**
 * `ADR-062 §5.7`, `CA-ACAD-016` (REQ-ACAD-001): una entrada por idioma activo,
 * el idioma por defecto primero y obligatorio, `lang` en cada entrada, errores
 * `translations.*` por idioma y del campo entero (WCAG 3.1.2, 3.3.1).
 */
import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { i18n, setLocale } from '@/i18n'
import TranslatableField from './TranslatableField.vue'
import TranslatedText from './TranslatedText.vue'

beforeEach(() => {
  window.localStorage.clear()
  setLocale('es')
})

function mountField(props: Record<string, unknown> = {}) {
  return mount(TranslatableField, {
    props: {
      modelValue: {},
      label: 'Nombre',
      locales: ['es-ES', 'en', 'fr'],
      defaultLocale: 'es-ES',
      idBase: 'name',
      ...props,
    },
    global: { plugins: [i18n] },
  })
}

describe('CA-ACAD-016: componente de campo traducible', () => {
  it('una entrada por idioma activo, la primera la del idioma por defecto y obligatoria', () => {
    const inputs = mountField().findAll('input')

    expect(inputs).toHaveLength(3)
    expect(inputs.map((input) => input.attributes('lang'))).toEqual(['es-ES', 'en', 'fr'])
    expect(inputs[0]!.attributes('required')).toBeDefined()
    expect(inputs[1]!.attributes('required')).toBeUndefined()
  })

  it('un centro con default en y solo en activo: una entrada, en, obligatoria (OPEN-062-01)', () => {
    const inputs = mountField({ locales: ['en'], defaultLocale: 'en' }).findAll('input')

    expect(inputs).toHaveLength(1)
    expect(inputs[0]!.attributes('lang')).toBe('en')
    expect(inputs[0]!.attributes('required')).toBeDefined()
  })

  it('cada entrada tiene etiqueta asociada con el nombre del idioma', () => {
    const wrapper = mountField()

    expect(wrapper.find('label[for="name-es-ES"]').text()).toContain('Español')
    expect(wrapper.find('label[for="name-en"]').text()).toContain('Inglés')
    expect(wrapper.find('label[for="name-es-ES"]').text()).toContain('obligatorio')
  })

  it('emite el objeto completo al escribir en un idioma', async () => {
    const wrapper = mountField({ modelValue: { 'es-ES': 'Lengua' } })

    await wrapper.find('#name-en').setValue('Language')

    expect(wrapper.emitted('update:modelValue')![0]).toEqual([
      { 'es-ES': 'Lengua', en: 'Language' },
    ])
  })

  it('REQ-FAM-UNIT-003: con multiline cada entrada es un textarea con lang y la primera obligatoria', async () => {
    const wrapper = mountField({ multiline: true })
    const areas = wrapper.findAll('textarea')

    expect(wrapper.findAll('input')).toHaveLength(0)
    expect(areas.map((area) => area.attributes('lang'))).toEqual(['es-ES', 'en', 'fr'])
    expect(areas[0]!.attributes('required')).toBeDefined()

    await wrapper.find('#name-en').setValue('Body')

    expect(wrapper.emitted('update:modelValue')![0]).toEqual([{ en: 'Body' }])
  })

  it('muestra el error de un idioma junto a su entrada, enlazado y como alerta', () => {
    const wrapper = mountField({ localeErrors: { en: ['El valor no puede estar vacío.'] } })
    const input = wrapper.find('#name-en')

    expect(input.attributes('aria-invalid')).toBe('true')
    expect(input.attributes('aria-describedby')).toBe('name-en-errors')
    expect(wrapper.find('#name-en-errors').attributes('role')).toBe('alert')
    expect(wrapper.find('#name-en-errors').text()).toContain('no puede estar vacío')
    expect(wrapper.find('#name-fr').attributes('aria-invalid')).toBeUndefined()
  })

  it('el error del campo entero (default_locale_required) marca la entrada por defecto', () => {
    const wrapper = mountField({ fieldErrors: ['Falta el idioma por defecto.'] })

    expect(wrapper.find('#name-es-ES').attributes('aria-invalid')).toBe('true')
    expect(wrapper.find('#name-es-ES').attributes('aria-describedby')).toBe('name-errors')
    expect(wrapper.find('#name-errors').text()).toContain('Falta el idioma por defecto')
  })
})

describe('CA-ACAD-016: presentación con lang cuando el texto sale de la caída', () => {
  function mountText(text: string, translations: Record<string, string>) {
    return mount(TranslatedText, { props: { text, translations }, global: { plugins: [i18n] } })
  }

  it('sin caída (idioma de la página) no lleva lang', () => {
    expect(
      mountText('Lengua', { 'es-ES': 'Lengua', en: 'Language' }).find('span').attributes('lang'),
    ).toBeUndefined()
  })

  it('con caída a otro idioma, lleva el lang de procedencia', () => {
    setLocale('de')

    const span = mountText('Lengua', { 'es-ES': 'Lengua', en: 'Language' }).find('span')

    expect(span.attributes('lang')).toBe('es-ES')
  })
})
