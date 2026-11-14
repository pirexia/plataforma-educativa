/**
 * `docs/design-system.md` §12.1, §18 (`CA-DS-044`). Adaptación de los
 * componentes vendorizados a `RN-DS-16`/`RN-DS-17`/`RN-DS-18`.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { buttonVariants } from './button'
import { badgeVariants } from './badge'
import { RadioGroup, RadioGroupItem } from './radio-group'
import { Checkbox } from './checkbox'
import { Label } from './label'

describe('Button — CA-DS-044', () => {
  it('variant="link" usa text-primary-on-background', () => {
    expect(buttonVariants({ variant: 'link' })).toContain('text-primary-on-background')
  })

  it('la variante por defecto tiene border-primary-on-background y ningún bg-primary/', () => {
    const classes = buttonVariants({ variant: 'default' })

    expect(classes).toContain('border-primary-on-background')
    expect(classes).not.toMatch(/bg-primary\//)
  })
})

describe('Badge — CA-DS-044', () => {
  it('la variante por defecto tiene border-primary-on-background y ningún bg-primary/', () => {
    const classes = badgeVariants({ variant: 'default' })

    expect(classes).toContain('border-primary-on-background')
    expect(classes).not.toMatch(/bg-primary\//)
  })

  it('variant="link" usa text-primary-on-background', () => {
    expect(badgeVariants({ variant: 'link' })).toContain('text-primary-on-background')
  })
})

describe('RadioGroupItem — CA-DS-044', () => {
  it('montado y marcado (checked), su clase contiene data-checked:border-primary-on-background', () => {
    const wrapper = mount(RadioGroup, {
      props: { modelValue: 'a' },
      slots: {
        default: '<RadioGroupItem value="a" /><RadioGroupItem value="b" />',
      },
      global: { components: { RadioGroupItem } },
    })

    const item = wrapper.find('[data-slot="radio-group-item"]')

    expect(item.exists()).toBe(true)
    expect(item.classes().join(' ')).toContain('data-checked:border-primary-on-background')
  })
})

describe('Checkbox — REQ-PRL-004 (#520), RN-DS-16', () => {
  it('es un role=checkbox, alterna con el clic, emite update:modelValue y su etiqueta asociada lo nombra', async () => {
    const wrapper = mount(
      {
        components: { Checkbox, Label },
        data: () => ({ on: false }),
        template:
          '<div><Checkbox id="c1" :model-value="on" @update:model-value="on = $event === true" /><Label for="c1">Fuera de horario</Label></div>',
      },
      { attachTo: document.body },
    )

    const box = wrapper.find('[role="checkbox"]')

    expect(box.exists()).toBe(true)
    expect(box.attributes('id')).toBe('c1')
    expect(box.attributes('aria-checked')).toBe('false')
    expect(wrapper.find('label[for="c1"]').text()).toBe('Fuera de horario')

    await wrapper.find('label[for="c1"]').trigger('click')

    expect(box.attributes('aria-checked')).toBe('true')
    expect(box.classes().join(' ')).toContain('data-[state=checked]:border-primary-on-background')

    await box.trigger('click')

    expect(box.attributes('aria-checked')).toBe('false')
    wrapper.unmount()
  })
})
