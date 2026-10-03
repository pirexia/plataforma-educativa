/**
 * `docs/modulos/REQ-CORE/funcional.md §14.7`, `§14.18`: auditoría (1.9d) —
 * `CA-CORE-242` (modo `cursor`), `CA-CORE-243` (fechas y operación),
 * `CA-CORE-244` (filtro por usuario, `OPEN-CORE-33` = C), `CA-CORE-245`
 * (módulo, `OPEN-CORE-34` = B), `CA-CORE-246` («Ver cambios»), `CA-CORE-247`
 * (exportación), `CA-CORE-262` (almacenamiento).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import { MAX_CURSOR_ROWS } from '@/data-table'

const listAuditLogs = vi.fn()
const getAuditFacets = vi.fn()
const exportAuditLogs = vi.fn()
const getDataExport = vi.fn()
const getUser = vi.fn()
const listUsers = vi.fn()

vi.mock('../api', () => ({
  listAuditLogs: (...args: unknown[]) => listAuditLogs(...args),
  getAuditFacets: (...args: unknown[]) => getAuditFacets(...args),
  exportAuditLogs: (...args: unknown[]) => exportAuditLogs(...args),
  getDataExport: (...args: unknown[]) => getDataExport(...args),
  getUser: (...args: unknown[]) => getUser(...args),
  listUsers: (...args: unknown[]) => listUsers(...args),
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
const { default: AuditView } = await import('./AuditView.vue')

const ANA = '01HZX0000000000000000000AA'

function log(id: string, overrides: Record<string, unknown> = {}) {
  return {
    public_id: id,
    occurred_at: '2026-03-02T10:30:00Z',
    actor: { public_id: ANA, display_name: 'Ana López' },
    actor_type: 'user',
    auditable_type: 'user',
    auditable_public_id: '01HZX0000000000000000000ZZ',
    event: 'updated',
    changes: null,
    ip_address: '203.0.113.7',
    user_agent: null,
    request_id: 'req-1',
    ...overrides,
  }
}

function cursorPage(rows: unknown[], next: string | null = null) {
  return { data: rows, meta: { next_cursor: next, has_more: next !== null } }
}

const FACETS = {
  modules: ['core', 'auth'],
  auditable_types: [
    { alias: 'user', module: 'core' },
    { alias: 'role', module: 'core' },
    { alias: 'misterio', module: 'core' },
  ],
  events: [
    'created',
    'updated',
    'deleted',
    'restored',
    'read',
    'exported',
    'login',
    'logout',
    'password_reset_requested',
  ],
  actor_types: ['user', 'system', 'console', 'import', 'platform', 'anonymous'],
}

const wrappers: VueWrapper[] = []
let router: Router

async function mountView(url = '/administracion/auditoria'): Promise<VueWrapper> {
  router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/administracion/auditoria', name: 'core-audit', component: AuditView }],
  })

  await router.push(url)
  await router.isReady()

  const wrapper = mount(AuditView, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return wrapper
}

async function click(el: Element | null | undefined): Promise<void> {
  if (!el) {
    throw new Error('Control inexistente')
  }

  el.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await flushPromises()
}

function buttonByText(text: string): HTMLButtonElement {
  const found = [...document.body.querySelectorAll('button')].find(
    (button) => (button.textContent ?? '').trim() === text,
  )

  if (!found) {
    throw new Error(`No hay botón «${text}»`)
  }

  return found as HTMLButtonElement
}

function menuItems(): string[] {
  return [...document.body.querySelectorAll('[role="menuitemcheckbox"]')].map(
    (el) => el.textContent?.trim() ?? '',
  )
}

function lastParams(): Record<string, unknown> {
  return listAuditLogs.mock.calls.at(-1)![0] as Record<string, unknown>
}

beforeEach(() => {
  setLocale('es')
  listAuditLogs.mockReset().mockResolvedValue(cursorPage([log('L1'), log('L2')]))
  getAuditFacets.mockReset().mockResolvedValue(FACETS)
  exportAuditLogs.mockReset().mockResolvedValue({ public_id: 'EXP1', status: 'pendiente' })
  getDataExport.mockReset()
  getUser.mockReset().mockResolvedValue({
    public_id: ANA,
    email: 'ana@example.com',
    person: { given_name: 'Ana', family_name_1: 'López' },
  })
  listUsers.mockReset().mockResolvedValue({
    data: [
      {
        public_id: ANA,
        email: 'ana@example.com',
        person: { given_name: 'Ana', family_name_1: 'López' },
      },
    ],
    meta: { current_page: 1, per_page: 10, total: 1, last_page: 1 },
  })
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
  session.__setPermissions(['auditoria.leer', 'auditoria.exportar', 'usuario.leer'])
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('CA-CORE-242 (RN-CORE-76, ADR-038 §4.4): modo cursor', () => {
  it('«Cargar más», sin paginador ni total, sin búsqueda de la tabla ni cabeceras ordenables, y la URL nunca contiene cursor', async () => {
    listAuditLogs.mockResolvedValueOnce(cursorPage([log('L1')], 'CUR1'))
    const wrapper = await mountView()

    expect(wrapper.find('caption').text()).toBe('Registro de auditoría del centro')
    expect(wrapper.find('[data-slot="data-table-pagination"]').exists()).toBe(false)
    expect(wrapper.find('thead button').exists()).toBe(false)
    expect(
      [...document.querySelectorAll('input[type="search"]')].filter(
        (input) => !input.closest('[data-slot="data-table-entity-filter"]'),
      ),
    ).toEqual([])
    expect(lastParams().cursor).toBeUndefined()

    await click(buttonByText('Cargar más'))

    expect(lastParams().cursor).toBe('CUR1')
    expect(router.currentRoute.value.query.cursor).toBeUndefined()
    expect(JSON.stringify(router.currentRoute.value.query)).not.toContain('CUR1')
  })

  it('el listado pide con los filtros de la URL y nunca envía q', async () => {
    await mountView('/administracion/auditoria?event=created,updated')

    expect(lastParams()).toMatchObject({ event: ['created', 'updated'] })
    expect(lastParams()).not.toHaveProperty('q')
  })

  it('pinta fecha, operación, usuario (o tipo de actor), entidad y deja IP, petición e identificador ocultos', async () => {
    listAuditLogs.mockResolvedValue(
      cursorPage([
        log('L1'),
        log('L2', {
          actor: null,
          actor_type: 'console',
          event: 'login',
          auditable_type: 'user_session',
        }),
        log('L3', { event: 'nuevo_evento' }),
      ]),
    )
    const wrapper = await mountView()
    const text = wrapper.find('tbody').text()

    expect(text).toContain('Modificación')
    expect(text).toContain('Ana López')
    expect(text).toContain('Consola')
    expect(text).toContain('Inicio de sesión')
    // ADR-038 §7.3: alias sin catálogo y evento nuevo muestran su código.
    expect(text).toContain('user_session')
    expect(text).toContain('nuevo_evento')
    expect(text).not.toContain('203.0.113.7')
    expect(text).not.toContain('req-1')
  })
})

describe('CA-CORE-243 (RN-CORE-76, OPEN-CORE-35 = A): fechas y operación', () => {
  it('un rango del 1 al 3 de marzo envía el inicio del día 1 y el fin del día 3 en la zona del navegador', async () => {
    await mountView(
      '/administracion/auditoria?occurred_at_from=2026-03-01&occurred_at_to=2026-03-03',
    )

    expect(lastParams().occurred_at_from).toBe(new Date(2026, 2, 1, 0, 0, 0, 0).toISOString())
    expect(lastParams().occurred_at_to).toBe(
      new Date(2026, 2, 3, 23, 59, 59, 999).toISOString().replace('.999Z', '.999999Z'),
    )
  })

  it('el filtro de operación ofrece los nueve valores con su etiqueta traducida', async () => {
    await mountView()
    await click(buttonByText('Operación'))

    expect(menuItems()).toEqual([
      'Alta',
      'Modificación',
      'Baja',
      'Restauración',
      'Lectura',
      'Exportación',
      'Inicio de sesión',
      'Cierre de sesión',
      'Solicitud de restablecimiento de contraseña',
    ])
  })

  it('los nueve valores de event, los seis de actor_type y las entidades tienen etiqueta en los cuatro idiomas', () => {
    const events = FACETS.events
    const actorTypes = FACETS.actor_types

    for (const locale of ['es', 'en', 'de', 'fr'] as const) {
      const messages = i18n.global.getLocaleMessage(locale) as {
        core: { audit: { event: Record<string, string>; actorType: Record<string, string> } }
      }

      for (const event of events) {
        expect(messages.core.audit.event[event], `${locale} event.${event}`).toBeTruthy()
      }

      for (const type of actorTypes) {
        expect(messages.core.audit.actorType[type], `${locale} actorType.${type}`).toBeTruthy()
      }
    }
  })
})

describe('CA-CORE-244 (REQ-CORE-005, OPEN-CORE-33 = C): filtro por usuario', () => {
  it('desde la URL (como «Ver su actividad») envía actor_id y muestra el nombre del usuario con acción de quitarlo', async () => {
    await mountView(`/administracion/auditoria?actor_id=${ANA}`)

    expect(lastParams().actor_id).toBe(ANA)
    expect(getUser).toHaveBeenCalledWith(ANA, { include_deleted: false })
    expect(document.body.textContent).toContain('Filtrado por: Ana López (ana@example.com)')

    await click(document.querySelector('[aria-label="Quitar el filtro «Usuario»"]'))

    expect(lastParams().actor_id).toBeUndefined()
    expect(router.currentRoute.value.query.actor_id).toBeUndefined()
  })

  it('elegido en el filtro (búsqueda GET /users?q=) envía actor_id', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })

    try {
      await mountView()

      const input = document.querySelector<HTMLInputElement>(
        '[data-slot="data-table-entity-filter"] input[type="search"]',
      )!

      input.value = 'ana'
      input.dispatchEvent(new Event('input', { bubbles: true }))
      await vi.advanceTimersByTimeAsync(400)
      await flushPromises()

      expect(listUsers).toHaveBeenCalledTimes(1)
      expect(listUsers.mock.calls[0]![0]).toMatchObject({ q: 'ana' })

      await click(
        [...document.querySelectorAll('[data-slot="data-table-entity-results"] button')].find((b) =>
          b.textContent?.includes('Ana López'),
        ),
      )

      expect(lastParams().actor_id).toBe(ANA)
    } finally {
      vi.useRealTimers()
    }
  })

  it('sin usuario.leer no se ofrece el filtro ni se pide GET /users', async () => {
    session.__setPermissions(['auditoria.leer'])
    await mountView()

    expect(document.querySelector('[data-slot="data-table-entity-filter"]')).toBeNull()
    expect(listUsers).not.toHaveBeenCalled()
    expect(getUser).not.toHaveBeenCalled()
  })
})

describe('CA-CORE-245 (REQ-CORE-005, OPEN-CORE-34 = B, S7): módulo y entidad desde las facetas', () => {
  it('pide las facetas una vez; el filtro de módulo con dos valores envía module como lista', async () => {
    await mountView('/administracion/auditoria?module=core,auth')

    expect(getAuditFacets).toHaveBeenCalledTimes(1)
    expect(lastParams().module).toEqual(['core', 'auth'])

    await click(buttonByText('Módulo (2)'))

    expect(menuItems()).toEqual(['Centro y usuarios', 'Acceso y seguridad'])
  })

  it('el filtro de entidad usa los alias de las facetas, con rama por defecto para uno sin traducir', async () => {
    await mountView()
    await click(buttonByText('Entidad'))

    expect(menuItems()).toEqual(['Usuario', 'Rol', 'misterio'])
  })

  it('el tipo de actor se envía como lista', async () => {
    await mountView('/administracion/auditoria?actor_type=user,system')

    expect(lastParams().actor_type).toEqual(['user', 'system'])
  })

  it('sin facetas (fallo del servidor) la tabla sigue funcionando sin los filtros de módulo y entidad', async () => {
    getAuditFacets.mockRejectedValue(new Error('red'))
    const wrapper = await mountView()

    expect(wrapper.find('tbody').text()).toContain('Modificación')
    expect(
      [...document.querySelectorAll('button')].map((b) => b.textContent?.trim()),
    ).not.toContain('Módulo')
    expect(
      [...document.querySelectorAll('button')].map((b) => b.textContent?.trim()),
    ).not.toContain('Entidad')
  })

  it('el estado de la URL de módulo se respeta aunque las facetas tarden: la tabla no se monta antes', async () => {
    let release: (value: unknown) => void = () => undefined

    getAuditFacets.mockReturnValue(new Promise((resolve) => (release = resolve)))

    const wrapper = await mountView('/administracion/auditoria?module=core')

    expect(listAuditLogs).not.toHaveBeenCalled()
    release(FACETS)
    await flushPromises()

    expect(lastParams().module).toEqual(['core'])
    expect(wrapper.find('tbody').exists()).toBe(true)
  })
})

describe('CA-CORE-246 (RN-CORE-77, CA-CORE-052, ADR-035): «Ver cambios»', () => {
  const redacted = log('L9', {
    event: 'updated',
    changes: {
      status: { from: 'pendiente', to: 'activo' },
      document_number: { redacted: 'identifier', from_empty: false, to_empty: false },
      active_locales: { from: ['es-ES'], to: ['es-ES', 'en'] },
    },
  })

  it('abre un diálogo con pendiente → activo y «valor no registrado» con el motivo, sin ningún otro valor; no hace peticiones', async () => {
    listAuditLogs.mockResolvedValue(cursorPage([redacted]))
    await mountView()

    const calls = listAuditLogs.mock.calls.length
    const open = document.querySelector<HTMLButtonElement>('tbody button')!

    expect(open.getAttribute('aria-label')).toContain('Ver cambios')

    open.focus()
    await click(open)

    const dialog = document.querySelector('[role="dialog"]')!

    expect(dialog).not.toBeNull()
    expect(dialog.contains(document.activeElement)).toBe(true)
    expect(dialog.textContent).toContain('pendiente → activo')
    expect(dialog.textContent).toContain('Valor no registrado')
    expect(dialog.textContent).toContain('Motivo: dato identificativo')
    expect(dialog.textContent).toContain('["es-ES"] → ["es-ES","en"]')
    expect(dialog.textContent).toContain('document_number')
    expect(listAuditLogs.mock.calls.length).toBe(calls)
    expect(getUser).not.toHaveBeenCalled()
  })

  it('Esc cierra el panel y el foco vuelve al botón', async () => {
    listAuditLogs.mockResolvedValue(cursorPage([redacted]))
    await mountView()

    const open = document.querySelector<HTMLButtonElement>('tbody button')!

    open.focus()
    await click(open)

    document
      .querySelector('[role="dialog"]')!
      .dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()

    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(document.activeElement).toBe(open)
  })

  it('un valor redactado nunca se reconstruye: con from_empty/to_empty informa si estaba vacío, sin el valor', async () => {
    listAuditLogs.mockResolvedValue(
      cursorPage([
        log('L8', {
          changes: {
            birth_date: { redacted: 'special', from_empty: true, to_empty: false },
            api_key: { redacted: 'secret' },
          },
        }),
      ]),
    )
    await mountView()
    await click(document.querySelector('tbody button'))

    const text = document.querySelector('[role="dialog"]')!.textContent!

    expect(text).toContain('Antes: vacío')
    expect(text).toContain('Después: con valor')
    expect(text).toContain('categoría especial de datos')
    expect(text).toContain('dato secreto')
  })

  it('changes null (eventos read, login…) muestra «sin cambios registrados»', async () => {
    listAuditLogs.mockResolvedValue(cursorPage([log('L7', { event: 'read', changes: null })]))
    await mountView()
    await click(document.querySelector('tbody button'))

    expect(document.querySelector('[role="dialog"]')!.textContent).toContain(
      'Sin cambios registrados.',
    )
  })
})

describe('CA-CORE-247 (RN-CORE-78, RN-CORE-52): exportación', () => {
  it('con auditoria.exportar, los filtros estructurados se envían como arrays y nada más', async () => {
    await mountView('/administracion/auditoria?event=created,updated&actor_type=user')

    await click(buttonByText('Exportar'))

    expect(exportAuditLogs).toHaveBeenCalledTimes(1)
    expect(exportAuditLogs.mock.calls[0]![0]).toEqual({
      format: 'csv',
      event: ['created', 'updated'],
      actor_type: ['user'],
    })
  })

  it('las fechas del filtro se exportan como instantes, igual que en el listado', async () => {
    await mountView(
      '/administracion/auditoria?occurred_at_from=2026-03-01&occurred_at_to=2026-03-03',
    )
    await click(buttonByText('Exportar'))

    const payload = exportAuditLogs.mock.calls[0]![0] as Record<string, string>

    expect(payload.occurred_at_from).toBe(lastParams().occurred_at_from)
    expect(payload.occurred_at_to).toBe(lastParams().occurred_at_to)
  })

  it('sin auditoria.exportar no hay control de exportación', async () => {
    session.__setPermissions(['auditoria.leer'])
    await mountView()

    expect(
      [...document.querySelectorAll('button')].map((b) => b.textContent?.trim()),
    ).not.toContain('Exportar')
  })

  it('al alcanzar 1.000 filas el aviso de tope ofrece exportar', async () => {
    const rows = (from: number) => Array.from({ length: 50 }, (_, i) => log(`R${from + i}`))

    listAuditLogs.mockImplementation(async () =>
      cursorPage(rows(listAuditLogs.mock.calls.length * 50), 'NEXT'),
    )

    await mountView()

    for (let load = 1; load < MAX_CURSOR_ROWS / 50; load += 1) {
      await click(buttonByText('Cargar más'))
    }

    const cap = document.querySelector('[data-slot="data-table-cursor-footer"]')!

    expect(cap.textContent).toMatch(/1\.?000/)
    expect([...cap.querySelectorAll('button')].map((b) => b.textContent?.trim())).toEqual([
      'Exportar',
    ])
  }, 60_000)
})

describe('CA-CORE-262 (RN-CORE-50): almacenamiento', () => {
  it('las únicas escrituras en localStorage son claves plataforma.table.<tableId> sin datos de filas', async () => {
    const setItem = vi.spyOn(Storage.prototype, 'setItem')

    try {
      await mountView(`/administracion/auditoria?actor_id=${ANA}`)
      await click(document.querySelector('tbody button'))

      for (const [key, value] of setItem.mock.calls) {
        expect(key).toMatch(/^plataforma\.table\.core\.audit$/)
        expect(String(value)).not.toContain('Ana')
        expect(String(value)).not.toContain('203.0.113.7')
      }
    } finally {
      setItem.mockRestore()
    }
  })
})
