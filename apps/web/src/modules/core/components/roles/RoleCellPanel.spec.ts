/**
 * `docs/modulos/REQ-PERM/funcional.md §20.8.1`-`§20.8.3`: panel de edición de una
 * celda — tres estados (`RN-PERM-32`), «Denegar» sin ámbito y con su explicación
 * (`RN-PERM-34`), los cuatro estados de ámbito con el motivo **dentro de la
 * opción** y la nota con `aria-describedby` (`RN-PERM-33`, issue #170;
 * `CA-PERM-109`), «Aplicar»/«Cancelar»/`Esc` (`CA-PERM-116`).
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { i18n, setLocale } from '@/i18n'
import type { CellChoice, ScopeOption } from '../../roleEditor'
import RoleCellPanel from './RoleCellPanel.vue'

const wrappers: VueWrapper[] = []

const OPTIONS: ScopeOption[] = [
  { scope: 'todos', status: 'current', selectable: true },
  { scope: 'propios', status: 'available', selectable: true },
  { scope: 'departamento', status: 'not_held', selectable: false },
  { scope: 'grupo', status: 'no_resolver', selectable: false },
]

function mountPanel(props: Record<string, unknown> = {}): VueWrapper {
  const wrapper = mount(RoleCellPanel, {
    props: {
      open: true,
      title: 'Auditoría · Leer',
      code: 'auditoria.leer',
      choice: { state: 'allow', scope: 'todos' } satisfies CellChoice,
      options: OPTIONS,
      allowEnabled: true,
      notes: [],
      error: null,
      ...props,
    },
    global: { plugins: [i18n] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)

  return wrapper
}

async function openScopeSelect(): Promise<void> {
  const trigger = document.body.querySelector('[role="combobox"]')!

  trigger.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }))
  await flushPromises()
}

function radio(value: string): HTMLElement {
  return document.body.querySelector(`[role="radio"][value="${value}"]`) as HTMLElement
}

beforeEach(() => {
  setLocale('es')
  // Reka UI usa estas APIs del navegador, que jsdom no implementa.
  Object.assign(Element.prototype, {
    hasPointerCapture: () => false,
    setPointerCapture: () => undefined,
    releasePointerCapture: () => undefined,
    scrollIntoView: () => undefined,
  })
  globalThis.ResizeObserver ??= class {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  }
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('RN-PERM-32 / RN-PERM-34: tres estados y «Denegar»', () => {
  it('es un diálogo con el título, el código técnico y un grupo de radio con nombre accesible que incluye la identidad', async () => {
    mountPanel()
    await flushPromises()

    const dialog = document.body.querySelector('[role="dialog"]')!

    expect(dialog.textContent).toContain('Auditoría · Leer')
    expect(dialog.querySelector('code')!.textContent).toBe('auditoria.leer')

    const group = dialog.querySelector('[role="radiogroup"]')!

    expect(group.getAttribute('aria-label')).toBe('Concesión de Auditoría · Leer')
    expect(
      [...dialog.querySelectorAll('[role="radio"]')].map((r) => r.getAttribute('value')),
    ).toEqual(['none', 'allow', 'deny'])
  })

  it('al elegir «Denegar» el selector de ámbito desaparece, sale «Cualquier ámbito» con su explicación asociada al grupo, y Aplicar emite {state: deny}', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    expect(document.body.querySelector('[role="combobox"]')).not.toBeNull()

    radio('deny').click()
    await flushPromises()

    expect(document.body.querySelector('[role="combobox"]')).toBeNull()
    expect(document.body.textContent).toContain('Cualquier ámbito')

    const explanation = document.getElementById('role-cell-auditoria-leer-deny-explanation')!

    expect(explanation.textContent).toContain(
      'Denegar anula este permiso para todos los titulares del rol, en cualquier ámbito y aunque otro de sus roles lo conceda.',
    )
    expect(
      document.body.querySelector('[role="radiogroup"]')!.getAttribute('aria-describedby'),
    ).toContain('role-cell-auditoria-leer-deny-explanation')

    const apply = [...document.body.querySelectorAll('button')].find(
      (b) => b.textContent?.trim() === 'Aplicar',
    )!

    apply.click()
    await flushPromises()

    expect(wrapper.emitted('apply')).toEqual([[{ state: 'deny' }]])
  })

  it('«Sin conceder» emite {state: none}', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    radio('none').click()
    await flushPromises()
    ;[...document.body.querySelectorAll('button')]
      .find((b) => b.textContent?.trim() === 'Aplicar')!
      .click()
    await flushPromises()

    expect(wrapper.emitted('apply')).toEqual([[{ state: 'none' }]])
  })

  it('«Cancelar» cierra sin emitir apply', async () => {
    const wrapper = mountPanel()
    await flushPromises()
    ;[...document.body.querySelectorAll('button')]
      .find((b) => b.textContent?.trim() === 'Cancelar')!
      .click()
    await flushPromises()

    expect(wrapper.emitted('apply')).toBeUndefined()
    expect(wrapper.emitted('update:open')).toEqual([[false]])
  })

  it('Esc cierra sin emitir apply', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    document.body
      .querySelector('[role="dialog"]')!
      .dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()

    expect(wrapper.emitted('apply')).toBeUndefined()
    expect(wrapper.emitted('update:open')?.[0]).toEqual([false])
  })

  it('el borrador parte del estado vigente cada vez que se abre', async () => {
    const wrapper = mountPanel({ choice: { state: 'deny' } })
    await flushPromises()

    expect(radio('deny').getAttribute('aria-checked')).toBe('true')

    radio('none').click()
    await flushPromises()
    await wrapper.setProps({ open: false })
    await flushPromises()
    await wrapper.setProps({ open: true })
    await flushPromises()

    expect(radio('deny').getAttribute('aria-checked')).toBe('true')
  })
})

describe('CA-PERM-109 (RN-PERM-33, issue #170): ámbitos con el motivo en la propia opción', () => {
  it('«todos» actual y seleccionable, «propios» disponible, «departamento» no lo tienes y «grupo» aún no existe, con el texto dentro de la opción', async () => {
    mountPanel()
    await flushPromises()
    await openScopeSelect()

    const options = [...document.body.querySelectorAll('[role="option"]')]
    const text = (scope: string) =>
      options.find((o) => o.textContent?.startsWith(scope))!.textContent!.trim()
    const disabled = (scope: string) =>
      options.find((o) => o.textContent?.startsWith(scope))!.hasAttribute('data-disabled')

    expect(options).toHaveLength(4)
    expect(text('Todos')).toBe('Todos (actual)')
    expect(disabled('Todos')).toBe(false)
    expect(text('Propios')).toBe('Propios')
    expect(disabled('Propios')).toBe(false)
    expect(text('Departamento')).toBe(
      'Departamento — no puedes concederlo: tú no tienes este permiso con este ámbito',
    )
    expect(disabled('Departamento')).toBe(true)
    expect(text('Grupo')).toBe(
      'Grupo — todavía no se puede conceder: lo aportará un módulo que aún no está disponible',
    )
    expect(disabled('Grupo')).toBe(true)
  })

  it('«Permitir» deshabilitado con la nota asociada al grupo por aria-describedby; «Denegar» y «Sin conceder» siguen habilitados', async () => {
    mountPanel({
      choice: { state: 'none' },
      options: [{ scope: 'todos', status: 'not_held', selectable: false }],
      allowEnabled: false,
      notes: ['Solo puedes denegar o dejar sin conceder este permiso: tú no lo tienes'],
    })
    await flushPromises()

    expect(radio('allow').hasAttribute('data-disabled')).toBe(true)
    expect(radio('deny').hasAttribute('data-disabled')).toBe(false)
    expect(radio('none').hasAttribute('data-disabled')).toBe(false)

    const group = document.body.querySelector('[role="radiogroup"]')!
    const noteId = group.getAttribute('aria-describedby')!

    expect(document.getElementById(noteId.split(' ')[0]!)!.textContent).toContain(
      'Solo puedes denegar o dejar sin conceder este permiso: tú no lo tienes',
    )
    expect(group.getAttribute('aria-invalid')).toBeNull()
  })

  it('los avisos de inercia salen como texto en la lista de notas', async () => {
    mountPanel({
      notes: [
        'Esta concesión no surtirá efecto: el rol no tiene acceso a datos de categoría especial',
      ],
    })
    await flushPromises()

    expect(document.body.querySelector('ul')!.textContent).toContain('no surtirá efecto')
  })

  it('con un error del servidor, el grupo lleva aria-invalid, el error role="alert" y está en aria-describedby (RN-PERM-36)', async () => {
    mountPanel({ error: 'No puedes conceder este permiso.' })
    await flushPromises()

    const group = document.body.querySelector('[role="radiogroup"]')!
    const alert = document.body.querySelector('[role="dialog"] [role="alert"]')!

    expect(group.getAttribute('aria-invalid')).toBe('true')
    expect(alert.textContent).toBe('No puedes conceder este permiso.')
    expect(group.getAttribute('aria-describedby')).toContain(alert.id)
  })
})

describe('Select de ámbito: elegir una opción', () => {
  it('elegir «Propios» y aplicar emite {state: allow, scope: propios}', async () => {
    const wrapper = mountPanel()
    await flushPromises()
    await openScopeSelect()

    const option = [...document.body.querySelectorAll('[role="option"]')].find((o) =>
      o.textContent?.startsWith('Propios'),
    )!

    option.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerType: 'mouse' }))
    option.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()
    ;[...document.body.querySelectorAll('button')]
      .find((b) => b.textContent?.trim() === 'Aplicar')!
      .click()
    await flushPromises()

    expect(wrapper.emitted('apply')).toEqual([[{ state: 'allow', scope: 'propios' }]])
  })
})
