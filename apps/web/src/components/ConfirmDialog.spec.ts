/**
 * `docs/modulos/REQ-CORE/funcional.md §14.14`, `RN-CORE-64`,
 * `OPEN-CORE-42` (= A), `CA-CORE-220`: el componente de aplicación de
 * confirmación sobre `alert-dialog` — modal, foco dentro al abrirlo, `Esc`
 * y «Cancelar» resuelven `false`, confirmar resuelve `true`, el foco vuelve
 * al control de origen.
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { i18n, setLocale } from '@/i18n'
import ConfirmDialog from './ConfirmDialog.vue'
import { useConfirm } from './useConfirm'

const wrappers: VueWrapper[] = []
let outcome: boolean | null = null

const Host = defineComponent({
  setup() {
    const confirmation = useConfirm()

    return () =>
      h('div', [
        h(
          'button',
          {
            id: 'origen',
            onClick: async () => {
              outcome = await confirmation.ask({
                title: 'Dar de baja a Ana López',
                description: 'La cuenta deja de estar disponible.',
                confirmLabel: 'Dar de baja a Ana López',
                destructive: true,
              })
            },
          },
          'Abrir',
        ),
        h(ConfirmDialog, {
          open: confirmation.open.value,
          request: confirmation.request.value,
          onConfirm: confirmation.confirm,
          onCancel: confirmation.cancel,
        }),
      ])
  },
})

function mountHost(): VueWrapper {
  const wrapper = mount(Host, {
    global: { plugins: [i18n] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)

  return wrapper
}

async function open(): Promise<HTMLButtonElement> {
  const origin = document.getElementById('origen') as HTMLButtonElement

  origin.focus()
  origin.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await flushPromises()

  return origin
}

function dialog(): Element | null {
  return document.body.querySelector('[role="alertdialog"]')
}

beforeEach(() => {
  setLocale('es')
  outcome = null
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('CA-CORE-220 (RN-CORE-64): ConfirmDialog', () => {
  it('abre un diálogo modal con título, consecuencia y botón con la identidad, y el foco dentro', async () => {
    mountHost()
    await open()

    expect(dialog()).not.toBeNull()
    expect(dialog()!.getAttribute('aria-modal') ?? 'true').toBe('true')
    expect(dialog()!.textContent).toContain('Dar de baja a Ana López')
    expect(dialog()!.textContent).toContain('La cuenta deja de estar disponible.')
    expect(dialog()!.contains(document.activeElement)).toBe(true)
    expect(outcome).toBeNull()
  })

  it('Esc cierra sin confirmar y devuelve el foco al control que lo abrió', async () => {
    mountHost()
    const origin = await open()

    dialog()!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()

    expect(dialog()).toBeNull()
    expect(outcome).toBe(false)
    expect(document.activeElement).toBe(origin)
  })

  it('«Cancelar» resuelve false y devuelve el foco', async () => {
    mountHost()
    const origin = await open()

    const cancel = [...dialog()!.querySelectorAll('button')].find(
      (button) => button.textContent?.trim() === 'Cancelar',
    )!

    cancel.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    expect(outcome).toBe(false)
    expect(document.activeElement).toBe(origin)
  })

  it('el botón de confirmar resuelve true exactamente una vez', async () => {
    mountHost()
    await open()

    const confirm = [...dialog()!.querySelectorAll('button')].find(
      (button) => button.textContent?.trim() === 'Dar de baja a Ana López',
    )!

    confirm.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    expect(outcome).toBe(true)
    expect(dialog()).toBeNull()
  })

  it('el texto de Cancelar viene de la traducción en los cuatro idiomas', async () => {
    const labels: Record<string, string> = {
      es: 'Cancelar',
      en: 'Cancel',
      de: 'Abbrechen',
      fr: 'Annuler',
    }

    for (const [locale, label] of Object.entries(labels)) {
      setLocale(locale as 'es' | 'en' | 'de' | 'fr')
      mountHost()
      await open()

      expect([...dialog()!.querySelectorAll('button')].map((b) => b.textContent?.trim())).toContain(
        label,
      )

      wrappers.pop()!.unmount()
      document.body.innerHTML = ''
    }
  })
})
