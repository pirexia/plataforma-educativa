import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import QrCode from './QrCode.vue'

// ADR-041 §2.4/§2.3.1 (issue #103): los módulos del QR heredan el color
// del tema del centro vía `currentColor`, nunca un color fijo. Sin esto,
// el QR queda ilegible (o con contraste insuficiente) en modo oscuro.
describe('QrCode', () => {
  it('pinta los módulos con fill="currentColor" y ninguno en blanco/negro fijo', () => {
    const wrapper = mount(QrCode, {
      props: {
        value: 'otpauth://totp/example?secret=JBSWY3DPEHPK3PXP',
        label: 'Código QR de ejemplo',
      },
    })

    const rects = wrapper.findAll('rect')
    expect(rects.length).toBeGreaterThan(0)

    for (const rect of rects) {
      expect(rect.attributes('fill')).toBe('currentColor')
    }

    expect(wrapper.html()).not.toContain('fill="white"')
    expect(wrapper.html()).not.toContain('fill="black"')
  })

  it('no expone el valor codificado como texto accesible', () => {
    const secretUri = 'otpauth://totp/example?secret=JBSWY3DPEHPK3PXP'
    const wrapper = mount(QrCode, {
      props: { value: secretUri, label: 'Código QR de ejemplo' },
    })

    expect(wrapper.get('svg').attributes('aria-label')).toBe('Código QR de ejemplo')
    expect(wrapper.html()).not.toContain(secretUri)
  })
})
