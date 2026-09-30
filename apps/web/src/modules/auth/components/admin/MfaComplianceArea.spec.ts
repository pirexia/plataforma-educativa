/**
 * `docs/modulos/REQ-CORE/funcional.md §13.15`/`§13.18` (`OPEN-CORE-28`):
 * `MfaComplianceArea` migrada al componente de tabla de datos, con
 * paridad estricta. `CA-CORE-186`, `CA-CORE-187`, `CA-CORE-206`,
 * `CA-CORE-201` (sin `'—'` literal) y `REQ-AUTH-003`. Cierra en parte el
 * issue #120 (la tabla migrada gana tests por construcción).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'

const apiFetchMock = vi.fn()

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  apiFetch: (...args: unknown[]) => apiFetchMock(...args),
}))

import { ApiError } from '@/api/client'
import MfaComplianceArea from './MfaComplianceArea.vue'

const ROLE = { public_id: 'role-1', code: 'docente', name: 'Docente', mfa_required: true }

const SUMMARY = {
  role: { public_id: 'role-1', code: 'docente' },
  mfa_required: true,
  preview: false,
  users_total: 10,
  users_enrolled: 4,
  users_obligated: 6,
  users_in_grace: 2,
  users_enforced: 1,
  users_exempt: 0,
}

function user(id: string, given: string, family: string) {
  return {
    public_id: id,
    given_name: given,
    family_name_1: family,
    family_name_2: null,
    email: `${id}@example.com`,
  }
}

function entry(id: string, given: string, family: string, overrides = {}) {
  return {
    user: user(id, given, family),
    state: 'pending',
    grace_deadline_at: null,
    enrolled_methods: [],
    required_by_roles: [],
    ...overrides,
  }
}

function usersPage(entries: unknown[], meta = {}) {
  return {
    data: entries,
    meta: { current_page: 1, per_page: 25, total: entries.length, last_page: 1, ...meta },
  }
}

const wrappers: VueWrapper[] = []

function respond(handler: (path: string) => unknown): void {
  apiFetchMock.mockImplementation(async (path: string) => {
    const result = handler(path)

    if (result instanceof Error) {
      throw result
    }

    return result
  })
}

function usersRequests(): URL[] {
  return apiFetchMock.mock.calls
    .map((call) => String(call[0]))
    .filter((path) => path.startsWith('/mfa-compliance/users'))
    .map((path) => new URL(path, 'http://x'))
}

function mountArea(props: Record<string, unknown> = {}, plugins: unknown[] = []) {
  const wrapper = mount(MfaComplianceArea, {
    props: { role: ROLE, ...props },
    global: { plugins: [i18n, ...plugins] as never[] },
    attachTo: document.body,
  })

  wrappers.push(wrapper as VueWrapper)

  return wrapper
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

function checkboxItem(text: string): HTMLElement {
  const found = [...document.body.querySelectorAll('[role="menuitemcheckbox"]')].find((el) =>
    el.textContent?.includes(text),
  )

  if (!found) {
    throw new Error(`No hay opción «${text}»`)
  }

  return found as HTMLElement
}

beforeEach(() => {
  setLocale('es')
  apiFetchMock.mockReset()
  window.localStorage.clear()
  respond((path) =>
    path.startsWith('/mfa-compliance/users')
      ? usersPage([entry('u1', 'Ana', 'López'), entry('u2', 'Luis', 'Pérez')])
      : SUMMARY,
  )
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('CA-CORE-186 (WCAG 2.4.6): nombres accesibles de las acciones por fila', () => {
  it('los dos botones de restablecimiento tienen nombres distintos, cada uno con el nombre de su usuario', async () => {
    mountArea()
    await flushPromises()

    const names = [...document.body.querySelectorAll('tbody button')].map((button) =>
      button.getAttribute('aria-label'),
    )

    expect(names).toEqual(['Restablecer MFA de Ana López', 'Restablecer MFA de Luis Pérez'])
    expect(new Set(names).size).toBe(2)
  })
})

describe('CA-CORE-187 (REQ-AUTH-003): paridad funcional de la migración', () => {
  it('marcar pending y past_deadline y pasar a la página 2 pide GET /mfa-compliance/users con state por comas y page=2', async () => {
    respond((path) =>
      path.startsWith('/mfa-compliance/users')
        ? usersPage([entry('u1', 'Ana', 'López')], { current_page: 1, total: 60, last_page: 3 })
        : SUMMARY,
    )
    mountArea()
    await flushPromises()

    // Primera petición: sin filtro, página 1 (mismo contrato que antes de la migración).
    const first = usersRequests()[0]!
    expect(first.pathname).toBe('/mfa-compliance/users')
    expect(first.searchParams.get('state')).toBeNull()
    expect(first.searchParams.get('page')).toBe('1')

    await click(buttonByLabel('Filtrar por estado'))
    await click(checkboxItem('Pendiente'))
    await click(checkboxItem('Vencido'))
    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()
    await click(buttonByLabel('Página siguiente'))

    const last = usersRequests().at(-1)!
    expect(last.pathname).toBe('/mfa-compliance/users')
    expect(last.searchParams.get('state')).toBe('pending,past_deadline')
    expect(last.searchParams.get('page')).toBe('2')
  })

  it('la acción de fila emite reset-user con el usuario de esa fila', async () => {
    const wrapper = mountArea()
    await flushPromises()

    await click(buttonByLabel('Restablecer MFA de Luis Pérez'))

    expect(wrapper.emitted('reset-user')).toHaveLength(1)
    expect(wrapper.emitted('reset-user')![0]![0]).toMatchObject({
      public_id: 'u2',
      given_name: 'Luis',
    })
  })

  it('un 403 emite forbidden', async () => {
    respond((path) =>
      path.startsWith('/mfa-compliance/users')
        ? new ApiError('403', 403, { type: 'urn:pge:error:forbidden' })
        : SUMMARY,
    )
    const wrapper = mountArea()
    await flushPromises()

    expect(wrapper.emitted('forbidden')).toBeTruthy()
  })

  it('refresh() sigue expuesto y repite el resumen y el listado', async () => {
    const wrapper = mountArea()
    await flushPromises()
    const before = apiFetchMock.mock.calls.length

    await (wrapper.vm as unknown as { refresh: () => Promise<unknown> }).refresh()
    await flushPromises()

    const paths = apiFetchMock.mock.calls.slice(before).map((call) => String(call[0]))
    expect(paths.some((path) => path.startsWith('/mfa-compliance?'))).toBe(true)
    expect(paths.some((path) => path.startsWith('/mfa-compliance/users'))).toBe(true)
  })

  it('el resumen por rol se carga al elegirlo y el listado no depende del rol (issue #116 no se corrige)', async () => {
    mountArea({ role: null })
    await flushPromises()

    // Sin rol elegido: sin resumen, pero el listado se pide igualmente (comportamiento actual).
    expect(
      apiFetchMock.mock.calls.some((call) => String(call[0]).startsWith('/mfa-compliance?')),
    ).toBe(false)
    expect(usersRequests()).toHaveLength(1)
    expect(document.body.textContent).toContain('Elige un rol para ver su cumplimiento.')
  })

  it('no deja «—» en ninguna celda: el valor vacío es el común, con texto para lector de pantalla (CA-CORE-201)', async () => {
    mountArea()
    await flushPromises()

    const empty = document.body.querySelector('[data-slot="data-table-empty-value"]')
    expect(empty).not.toBeNull()
    expect(empty!.querySelector('[aria-hidden="true"]')!.textContent).toBe('—')
    expect(empty!.querySelector('.sr-only')!.textContent).toBe('Sin valor')
  })
})

describe('CA-CORE-206 (RN-CORE-54, OPEN-CORE-28): sin estado en la URL', () => {
  it('marcar filtros y paginar no cambia la URL de /administracion/mfa', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/administracion/mfa', component: { template: '<div/>' } }],
    })
    await router.push('/administracion/mfa')
    await router.isReady()

    respond((path) =>
      path.startsWith('/mfa-compliance/users')
        ? usersPage([entry('u1', 'Ana', 'López')], { total: 60, last_page: 3 })
        : SUMMARY,
    )
    mountArea({}, [router])
    await flushPromises()

    await click(buttonByLabel('Filtrar por estado'))
    await click(checkboxItem('Pendiente'))
    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()
    await click(buttonByLabel('Página siguiente'))

    expect(router.currentRoute.value.fullPath).toBe('/administracion/mfa')
  })
})
