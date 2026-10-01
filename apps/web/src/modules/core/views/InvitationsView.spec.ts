/**
 * `docs/modulos/REQ-CORE/funcional.md §14.5`, `§14.18`: invitaciones (1.9b)
 * — `CA-CORE-231` (filtro de estado múltiple) y `CA-CORE-232` (acciones
 * según estado, confirmación, `409` y `429`), `RN-CORE-62` (enlace a la
 * ficha solo con `usuario.leer`).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'

const listInvitations = vi.fn()
const issueInvitation = vi.fn()
const revokeInvitation = vi.fn()

vi.mock('../api', () => ({
  listInvitations: (...args: unknown[]) => listInvitations(...args),
  issueInvitation: (...args: unknown[]) => issueInvitation(...args),
  revokeInvitation: (...args: unknown[]) => revokeInvitation(...args),
}))

vi.mock('@/session/useSession', async () => {
  const { shallowRef } = await import('vue')
  const user = shallowRef<{ public_id: string; permissions: string[] } | null>(null)

  return {
    useSession: () => ({ user }),
    __setPermissions: (permissions: string[]) => {
      user.value = { public_id: 'ME', permissions }
    },
  }
})

const session = (await import('@/session/useSession')) as unknown as {
  __setPermissions: (permissions: string[]) => void
}
const { default: InvitationsView } = await import('./InvitationsView.vue')

function invitation(id: string, status: string, overrides: Record<string, unknown> = {}) {
  return {
    public_id: id,
    user: { public_id: `U-${id}`, email: `${id.toLowerCase()}@example.com` },
    status,
    expires_at: '2026-12-31T10:00:00Z',
    created_at: '2026-09-01T10:00:00Z',
    accepted_at: status === 'aceptada' ? '2026-09-02T10:00:00Z' : null,
    revoked_at: status === 'revocada' ? '2026-09-02T10:00:00Z' : null,
    ...overrides,
  }
}

function page(rows: unknown[]) {
  return { data: rows, meta: { current_page: 1, per_page: 25, total: rows.length, last_page: 1 } }
}

const wrappers: VueWrapper[] = []

async function mountView(url = '/administracion/invitaciones'): Promise<VueWrapper> {
  const stub = { template: '<div/>' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/administracion/invitaciones',
        name: 'core-invitations',
        component: InvitationsView,
      },
      { path: '/administracion/usuarios/:publicId', name: 'core-user-detail', component: stub },
    ],
  })

  await router.push(url)
  await router.isReady()

  const wrapper = mount(InvitationsView, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return wrapper
}

async function click(el: Element | undefined): Promise<void> {
  if (!el) {
    throw new Error('Control inexistente')
  }

  el.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await flushPromises()
}

function actionButtons(): string[] {
  return [...document.body.querySelectorAll('tbody button')].map(
    (button) => button.getAttribute('aria-label') ?? '',
  )
}

function dialogButton(label: string): Element | undefined {
  return [...document.body.querySelectorAll('[role="alertdialog"] button')].find(
    (button) => button.textContent?.trim() === label,
  )
}

beforeEach(() => {
  setLocale('es')
  listInvitations
    .mockReset()
    .mockResolvedValue(
      page([
        invitation('A1', 'vigente'),
        invitation('B1', 'caducada'),
        invitation('C1', 'revocada'),
        invitation('D1', 'aceptada'),
      ]),
    )
  issueInvitation
    .mockReset()
    .mockResolvedValue({ public_id: 'N1', expires_at: '2027-01-15T10:00:00Z' })
  revokeInvitation.mockReset().mockResolvedValue(undefined)
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
  session.__setPermissions([
    'invitacion.leer',
    'invitacion.crear',
    'invitacion.eliminar',
    'usuario.leer',
  ])
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('CA-CORE-231 (RN-CORE-70, S7): filtro de estado múltiple', () => {
  it('vigente y caducada marcados envían status=vigente,caducada (como lista)', async () => {
    await mountView('/administracion/invitaciones?status=vigente,caducada')

    expect(listInvitations.mock.calls.at(-1)![0]).toMatchObject({ status: ['vigente', 'caducada'] })
  })

  it('el filtro no tiene búsqueda ni columnas ordenables', async () => {
    const wrapper = await mountView()

    expect(wrapper.find('input[type="search"]').exists()).toBe(false)
    expect(wrapper.find('thead button').exists()).toBe(false)
  })
})

describe('CA-CORE-232 (RN-CORE-70): acciones según estado', () => {
  it('vigente ofrece Revocar; caducada y revocada, Reenviar; aceptada, ninguna', async () => {
    await mountView()

    expect(actionButtons()).toEqual([
      'Revocar la invitación de a1@example.com',
      'Reenviar la invitación a b1@example.com',
      'Reenviar la invitación a c1@example.com',
    ])
  })

  it('sin invitacion.crear ni invitacion.eliminar no hay acciones; sin usuario.leer no hay enlace a la ficha', async () => {
    session.__setPermissions(['invitacion.leer'])
    const wrapper = await mountView()

    expect(actionButtons()).toEqual([])
    expect(wrapper.find('tbody th a').exists()).toBe(false)
  })

  it('con usuario.leer el correo enlaza con la ficha del usuario', async () => {
    const wrapper = await mountView()

    expect(wrapper.find('tbody th a').attributes('href')).toBe('/administracion/usuarios/U-A1')
  })

  it('revocar y reenviar piden confirmación antes de la petición', async () => {
    await mountView()

    await click(document.querySelector('[aria-label="Revocar la invitación de a1@example.com"]')!)

    expect(revokeInvitation).not.toHaveBeenCalled()
    expect(document.body.querySelector('[role="alertdialog"]')).not.toBeNull()

    await click(dialogButton('Revocar la invitación de a1@example.com'))

    expect(revokeInvitation).toHaveBeenCalledWith('A1')

    await click(document.querySelector('[aria-label="Reenviar la invitación a b1@example.com"]')!)
    expect(issueInvitation).not.toHaveBeenCalled()

    await click(dialogButton('Reenviar la invitación a b1@example.com'))

    expect(issueInvitation).toHaveBeenCalledWith('U-B1')
    expect(document.body.querySelector('[role="status"]')?.textContent).toContain('b1@example.com')
  })

  it('cancelar la confirmación no envía nada', async () => {
    await mountView()

    await click(document.querySelector('[aria-label="Revocar la invitación de a1@example.com"]')!)
    await click(dialogButton('Cancelar'))

    expect(revokeInvitation).not.toHaveBeenCalled()
  })

  it('un 429 al reenviar muestra los segundos de Retry-After', async () => {
    issueInvitation.mockRejectedValue(
      new ApiError('HTTP 429', 429, { status: 429 }, new Headers({ 'Retry-After': '120' })),
    )
    await mountView()

    await click(document.querySelector('[aria-label="Reenviar la invitación a b1@example.com"]')!)
    await click(dialogButton('Reenviar la invitación a b1@example.com'))

    expect(document.body.querySelector('p[role="alert"]')?.textContent).toContain('120')
  })

  it('un 409 muestra el detail del servidor y refresca la lista', async () => {
    issueInvitation.mockRejectedValue(
      new ApiError('HTTP 409', 409, {
        status: 409,
        detail: 'Solo se puede invitar a un usuario en estado pendiente.',
      }),
    )
    await mountView()

    const before = listInvitations.mock.calls.length

    await click(document.querySelector('[aria-label="Reenviar la invitación a b1@example.com"]')!)
    await click(dialogButton('Reenviar la invitación a b1@example.com'))

    expect(document.body.querySelector('p[role="alert"]')?.textContent).toContain(
      'Solo se puede invitar a un usuario en estado pendiente.',
    )
    expect(listInvitations.mock.calls.length).toBe(before + 1)
  })
})
