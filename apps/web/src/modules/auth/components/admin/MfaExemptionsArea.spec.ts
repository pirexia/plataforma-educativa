/**
 * `docs/modulos/REQ-CORE/funcional.md §14.13.3` (1.9f): `MfaExemptionsArea`
 * migrada al componente de tabla. `CA-CORE-291`, `CA-CORE-292`,
 * `CA-CORE-256` (formulario de concesión fuera de la tabla) y `REQ-AUTH-003`.
 * Cierra otra parte del issue #120.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { i18n, setLocale } from '@/i18n'

const apiFetchMock = vi.fn()

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  apiFetch: (...args: unknown[]) => apiFetchMock(...args),
}))

import { ApiError } from '@/api/client'
import MfaExemptionsArea from './MfaExemptionsArea.vue'

function person(id: string, given: string, family: string) {
  return {
    public_id: id,
    given_name: given,
    family_name_1: family,
    family_name_2: null,
    email: `${id}@example.com`,
  }
}

function exemption(id: string, given: string, family: string, state: string) {
  return {
    public_id: id,
    user: person(`u-${id}`, given, family),
    reason: 'Dispositivo compartido en aula de informática',
    expires_at: '2026-12-01T00:00:00Z',
    state,
    granted_by: { given_name: 'Eva', family_name_1: 'Ruiz' },
    granted_at: '2026-10-01T10:00:00Z',
    revoked_by: null,
    revoked_at: null,
  }
}

function pageOf(rows: unknown[], meta = {}) {
  return {
    data: rows,
    meta: { current_page: 1, per_page: 25, total: rows.length, last_page: 1, ...meta },
  }
}

const wrappers: VueWrapper[] = []

const PickerStub = {
  emits: ['select', 'clear'],
  template:
    "<button data-pick type=\"button\" @click=\"$emit('select', { public_id: 'u-new', given_name: 'Nuria', family_name_1: 'Gil', family_name_2: null, email: 'n@example.com' })\">pick</button>",
}

function mountArea() {
  const wrapper = mount(MfaExemptionsArea, {
    global: { plugins: [i18n], stubs: { MfaUserPicker: PickerStub } },
    attachTo: document.body,
  })

  wrappers.push(wrapper as VueWrapper)

  return wrapper
}

function listRequests(): URL[] {
  return apiFetchMock.mock.calls
    .filter((call) => String(call[0]).startsWith('/mfa-exemptions') && !call[1]?.method)
    .map((call) => new URL(String(call[0]), 'http://x'))
}

function methodCalls(method: string): unknown[][] {
  return apiFetchMock.mock.calls.filter((call) => call[1]?.method === method)
}

async function click(el: Element): Promise<void> {
  el.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await flushPromises()
}

function buttonByLabel(label: string): HTMLButtonElement {
  const found = [...document.body.querySelectorAll('button')].find(
    (button) => (button.getAttribute('aria-label') ?? button.textContent ?? '').trim() === label,
  )

  if (!found) {
    throw new Error(`No hay botón «${label}»`)
  }

  return found as HTMLButtonElement
}

function dialog(): Element | null {
  return document.body.querySelector('[role="alertdialog"]')
}

let listResponse: () => unknown

beforeEach(() => {
  setLocale('es')
  apiFetchMock.mockReset()
  window.localStorage.clear()
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
  listResponse = () =>
    pageOf([exemption('e1', 'Ana', 'López', 'live'), exemption('e2', 'Luis', 'Pérez', 'expired')])
  apiFetchMock.mockImplementation(async (_path: string, init?: { method?: string }) => {
    if (init?.method === 'DELETE' || init?.method === 'POST') {
      return undefined
    }

    const result = listResponse()

    if (result instanceof Error) {
      throw result
    }

    return result
  })
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('CA-CORE-291 (RN-CORE-84, RN-CORE-64, RN-CORE-96, REQ-AUTH-003): paridad de la migración', () => {
  it('la primera petición lleva state=live, page=1 y per_page=25', async () => {
    mountArea()
    await flushPromises()

    const first = listRequests()[0]!

    expect(first.pathname).toBe('/mfa-exemptions')
    expect(first.searchParams.get('state')).toBe('live')
    expect(first.searchParams.get('page')).toBe('1')
    expect(first.searchParams.get('per_page')).toBe('25')
    expect(first.searchParams.has('user')).toBe(false)
  })

  it('la celda de acciones de una fila no viva es el valor vacío común, sin botón; la viva tiene nombre con la persona', async () => {
    mountArea()
    await flushPromises()

    const rows = [...document.body.querySelectorAll('tbody tr')]
    const expired = rows.find((row) => row.textContent?.includes('Luis Pérez'))!
    const live = rows.find((row) => row.textContent?.includes('Ana López'))!

    expect(expired.querySelector('button')).toBeNull()
    expect(expired.querySelector('[data-slot="data-table-empty-value"]')).not.toBeNull()
    expect(live.querySelector('button')!.getAttribute('aria-label')).toBe(
      'Revocar la excepción de Ana López',
    )
  })

  it('revocar: sin petición hasta confirmar; Esc cierra sin petición y devuelve el foco; confirmar envía un DELETE y vuelve a pedir la misma consulta', async () => {
    mountArea()
    await flushPromises()

    const trigger = buttonByLabel('Revocar la excepción de Ana López')

    trigger.focus()
    await click(trigger)

    expect(methodCalls('DELETE')).toHaveLength(0)
    expect(dialog()).not.toBeNull()
    expect(dialog()!.textContent).toContain('Ana López')

    dialog()!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()

    expect(dialog()).toBeNull()
    expect(methodCalls('DELETE')).toHaveLength(0)
    expect(document.activeElement).toBe(trigger)

    await click(trigger)

    const gets = listRequests().length

    await click(
      [...dialog()!.querySelectorAll('button')].find(
        (button) => button.textContent?.trim() === 'Revocar la excepción de Ana López',
      )!,
    )

    expect(methodCalls('DELETE')).toHaveLength(1)
    expect(String(methodCalls('DELETE')[0]![0])).toBe('/mfa-exemptions/e1')
    expect(listRequests()).toHaveLength(gets + 1)

    const before = listRequests().at(-2)!
    const after = listRequests().at(-1)!

    expect(after.searchParams.get('state')).toBe(before.searchParams.get('state'))
    expect(after.searchParams.get('page')).toBe(before.searchParams.get('page'))
  })

  it('un 500 al revocar muestra auth.common.unexpectedError con role=alert y no vuelve a pedir el listado', async () => {
    mountArea()
    await flushPromises()

    apiFetchMock.mockImplementation(async (_path: string, init?: { method?: string }) => {
      if (init?.method === 'DELETE') {
        throw new ApiError('500', 500, {})
      }

      return listResponse()
    })

    const gets = listRequests().length

    await click(buttonByLabel('Revocar la excepción de Ana López'))
    await click(
      [...dialog()!.querySelectorAll('button')].find(
        (button) => button.textContent?.trim() === 'Revocar la excepción de Ana López',
      )!,
    )

    const alert = document.body.querySelector('p[role="alert"]')

    expect(alert?.textContent).toContain(i18n.global.t('auth.common.unexpectedError'))
    expect(listRequests()).toHaveLength(gets)
  })

  it('un 403 del listado pinta el estado sin acceso dentro del área, sin ocultarla (CA-AUTH-176)', async () => {
    listResponse = () => new ApiError('403', 403, { type: 'urn:pge:error:forbidden' })
    mountArea()
    await flushPromises()

    expect(document.body.textContent).toContain(i18n.global.t('auth.mfaAdmin.exemptions.title'))
    expect(document.body.querySelector('[data-slot="data-table"]')).not.toBeNull()
    expect(document.body.querySelector('table')).toBeNull()
  })
})

describe('CA-CORE-292 (RN-CORE-84, OPEN-CORE-56 = A): tras conceder, página 1 con state=live', () => {
  it('con el filtro en «Caducada» y la página 2, conceder con 201 vuelve a state=live y page=1 y el filtro muestra «Viva»', async () => {
    listResponse = () =>
      pageOf([exemption('e2', 'Luis', 'Pérez', 'expired')], {
        current_page: 1,
        total: 60,
        last_page: 3,
      })
    mountArea()
    await flushPromises()

    await click(buttonByLabel('Filtrar por estado: Viva'))
    await click(
      [...document.body.querySelectorAll('[role="menuitemradio"]')].find(
        (el) => el.textContent?.trim() === 'Caducada',
      )!,
    )
    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()
    await click(buttonByLabel('Página siguiente'))

    const mid = listRequests().at(-1)!

    expect(mid.searchParams.get('state')).toBe('expired')
    expect(mid.searchParams.get('page')).toBe('2')

    await click(buttonByLabel('Conceder excepción'))
    await click(document.body.querySelector('[data-pick]')!)

    const textarea = document.body.querySelector<HTMLTextAreaElement>('#mfa-exemption-reason')!

    textarea.value = 'Dispositivo compartido en aula'
    textarea.dispatchEvent(new Event('input', { bubbles: true }))

    const date = document.body.querySelector<HTMLInputElement>('#mfa-exemption-expires')!

    date.value = '2026-11-01'
    date.dispatchEvent(new Event('input', { bubbles: true }))
    await flushPromises()
    await click(buttonByLabel('Conceder'))

    expect(methodCalls('POST')).toHaveLength(1)

    const last = listRequests().at(-1)!

    expect(last.searchParams.get('state')).toBe('live')
    expect(last.searchParams.get('page')).toBe('1')
    expect(
      [...document.body.querySelectorAll('button')].some(
        (button) => button.textContent?.trim() === 'Filtrar por estado: Viva',
      ),
    ).toBe(true)
  })
})

describe('CA-CORE-256 (RN-CORE-84): el formulario de concesión queda fuera de la tabla', () => {
  it('el formulario no está dentro del componente de tabla', async () => {
    mountArea()
    await flushPromises()
    await click(buttonByLabel('Conceder excepción'))

    const form = document.body.querySelector('form')!

    expect(form).not.toBeNull()
    expect(form.closest('[data-slot="data-table"]')).toBeNull()
  })
})
