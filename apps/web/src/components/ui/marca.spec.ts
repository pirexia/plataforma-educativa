/**
 * `docs/design-system.md` §12.1, §18 (`CA-DS-044`). Adaptación de los
 * componentes vendorizados a `RN-DS-16`/`RN-DS-17`/`RN-DS-18`.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { buttonVariants } from './button'
import { badgeVariants } from './badge'
import { RadioGroup, RadioGroupItem } from './radio-group'

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
