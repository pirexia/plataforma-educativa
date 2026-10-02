/**
 * `docs/modulos/REQ-CORE/funcional.md §14.22`, `§12.6`, `§12.3.4`: pruebas
 * de integración de 1.9b con la pila real (App, *router* con su *guard*,
 * *shell*, sesión, cliente HTTP y componente de tablas); solo se simula
 * `fetch` y el almacenamiento del navegador.
 *
 * - `CA-CORE-262` (`RN-CORE-50`): listado de usuarios (con búsqueda), ficha
 *   e invitaciones; las únicas escrituras son claves
 *   `plataforma.table.<tableId>`, sin datos de fila ni texto de búsqueda.
 * - `CA-CORE-263` (`CA-CORE-070`, `CA-CORE-073`): `404` y `403` de la ficha
 *   de usuario y del listado de invitaciones dentro del *shell*; el `403`
 *   recarga `GET /me`.
 *
 * Desde 1.9c (`funcional.md §14.22` punto 7) ambos criterios cubren también
 * el listado y el detalle de una importación (`/administracion/importaciones`);
 * el rol no existe todavía (1.9d): no se prueba.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// Pila real (App + router + shell): bajo la suite completa tarda más que el límite por defecto.
vi.setConfig({ testTimeout: 30000 })

const ME_PERMISSIONS = [
  'usuario.leer',
  'usuario.importar',
  'invitacion.leer',
  'invitacion.crear',
  'invitacion.eliminar',
]

// Valores de fila distinguibles: ninguno puede aparecer en lo escrito.
const SEARCH_TEXT = 'zqx-busqueda-731'
const ROW_EMAIL = 'fila-secreta-731@example.com'
const ROW_NAME = 'Quintanilla'
const ROW_DOC = 'ZZ-DOC-731'
const ROW_PHONE = '699731731'
const INVITATION_EMAIL = 'invitada-731@example.com'
const IMPORT_FILE = 'fichero-secreto-731.csv'
const IMPORT_MESSAGE = 'Motivo-secreto-731'

// Claves legítimas ajenas a la tabla que la aplicación puede escribir
// (`src/i18n/index.ts`, `src/tenant/useTenantBranding.ts` y el modo de color
// del *design system*), nombradas una a una.
const ALLOWED_NON_TABLE_KEYS = ['plataforma.locale', 'plataforma.brand', 'plataforma.color-mode']

interface Write {
  area: 'localStorage' | 'sessionStorage'
  op: 'set' | 'remove' | 'clear'
  key: string
  value: string
}

function installFakeStorage(area: 'localStorage' | 'sessionStorage', writes: Write[]): void {
  const data = new Map<string, string>()

  const api = {
    getItem: (key: string) => data.get(key) ?? null,
    setItem: (key: string, value: string) => {
      writes.push({ area, op: 'set', key: String(key), value: String(value) })
      data.set(String(key), String(value))
    },
    removeItem: (key: string) => {
      writes.push({ area, op: 'remove', key: String(key), value: '' })
      data.delete(String(key))
    },
    clear: () => {
      writes.push({ area, op: 'clear', key: '', value: '' })
      data.clear()
    },
    key: (index: number) => [...data.keys()][index] ?? null,
  }

  const fake = new Proxy(api, {
    get: (target, prop) => {
      if (prop === 'length') {
        return data.size
      }

      if (prop in target) {
        return target[prop as keyof typeof target]
      }

      return typeof prop === 'string' ? (data.get(prop) ?? undefined) : undefined
    },
    // `storage.clave = valor` también es una escritura.
    set: (_target, prop, value) => {
      api.setItem(String(prop), String(value))

      return true
    },
  })

  vi.stubGlobal(area, fake)
}

function meBody(permissions = ME_PERMISSIONS) {
  return {
    public_id: 'ME',
    email: 'yo@example.com',
    status: 'activo',
    person: { given_name: 'Admin', family_name_1: 'Prueba', locale: 'es-ES' },
    roles: [],
    permissions,
    email_verified_at: null,
    created_at: '2026-09-01T10:00:00Z',
    updated_at: '2026-09-01T10:00:00Z',
    deleted_at: null,
  }
}

function userBody(id: string) {
  return {
    public_id: id,
    email: ROW_EMAIL,
    status: 'activo',
    person: {
      public_id: `P-${id}`,
      given_name: 'Ana',
      family_name_1: ROW_NAME,
      family_name_2: null,
      contact_email: null,
      contact_phone: ROW_PHONE,
      document_type: 'DNI',
      document_number: ROW_DOC,
      birth_date: '1990-05-05',
      locale: 'es-ES',
    },
    roles: [],
    email_verified_at: null,
    created_at: '2026-09-01T10:00:00Z',
    updated_at: '2026-09-01T10:00:00Z',
    deleted_at: null,
  }
}

function pageOf(rows: unknown[]) {
  return { data: rows, meta: { current_page: 1, per_page: 25, total: rows.length, last_page: 1 } }
}

function invitationBody() {
  return {
    public_id: 'I1',
    user: { public_id: 'U9', email: INVITATION_EMAIL },
    status: 'vigente',
    expires_at: '2026-12-31T10:00:00Z',
    created_at: '2026-09-01T10:00:00Z',
    accepted_at: null,
    revoked_at: null,
  }
}

function importBody(id: string, status = 'validado') {
  return {
    public_id: id,
    original_filename: IMPORT_FILE,
    status,
    row_count: 5,
    error_count: 1,
    created_count: null,
    error_summary: [
      { line: 3, column: 'email', code: 'formato_invalido', message: IMPORT_MESSAGE },
    ],
    report_url: null,
    created_at: '2026-09-01T10:00:00Z',
    validated_at: '2026-09-01T10:01:00Z',
    executed_at: null,
  }
}

type Responder = (path: string) => { status: number; body: unknown } | undefined

let responders: Responder[] = []
let fetchLog: string[] = []
let writes: Write[] = []
let indexedDbCalls: string[] = []

function respond(status: number, body: unknown): { status: number; body: unknown } {
  return { status, body }
}

function installFetch(): void {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
      const url = new URL(String(input))
      const path = url.pathname.replace(/^\/api\/v1/, '') + url.search

      fetchLog.push(path)

      const result =
        responders.map((responder) => responder(path)).find((found) => found !== undefined) ??
        respond(404, { status: 404 })

      return Promise.resolve(
        new Response(JSON.stringify(result.body), {
          status: result.status,
          headers: { 'Content-Type': 'application/json' },
        }),
      )
    }),
  )
}

const meResponder: Responder = (path) => (path === '/me' ? respond(200, meBody()) : undefined)

interface Booted {
  router: import('vue-router').Router
  wrapper: import('@vue/test-utils').VueWrapper
  flush: () => Promise<unknown>
}

const mounted: Booted['wrapper'][] = []

async function boot(): Promise<Booted> {
  const { flushPromises, mount } = await import('@vue/test-utils')
  const { default: router } = await import('@/router')
  const { default: App } = await import('@/App.vue')
  const { i18n, setLocale } = await import('@/i18n')

  setLocale('es')

  // El almacenamiento simulado se instala DESPUÉS del arranque de i18n
  // para que `setLocale('es')` de la propia preparación no cuente como
  // escritura de la aplicación.
  writes.length = 0

  const wrapper = mount(App, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  })

  mounted.push(wrapper)

  return { router, wrapper, flush: flushPromises }
}

async function go(booted: Booted, path: string): Promise<void> {
  await booted.router.push(path)
  await booted.flush()
  // El *guard* y las vistas cargan de forma asíncrona (importación
  // dinámica de la vista + `fetch`): se deja asentar.
  await new Promise((resolve) => setTimeout(resolve, 20))
  await booted.flush()
}

function mainText(): string {
  return document.querySelector('main#main-content')?.textContent ?? ''
}

function meCalls(): number {
  return fetchLog.filter((path) => path === '/me').length
}

beforeEach(() => {
  vi.resetModules()
  responders = [meResponder]
  fetchLog = []
  writes = []
  indexedDbCalls = []

  installFakeStorage('localStorage', writes)
  installFakeStorage('sessionStorage', writes)
  installFetch()

  const idb = {
    open: vi.fn((name: string) => {
      indexedDbCalls.push(`open:${name}`)
      throw new Error('indexedDB.open no debe llamarse')
    }),
    databases: vi.fn(() => {
      indexedDbCalls.push('databases')
      return Promise.resolve([])
    }),
    deleteDatabase: vi.fn((name: string) => {
      indexedDbCalls.push(`deleteDatabase:${name}`)
      throw new Error('indexedDB.deleteDatabase no debe llamarse')
    }),
  }

  vi.stubGlobal('indexedDB', idb)

  Object.defineProperty(window, 'matchMedia', {
    configurable: true,
    writable: true,
    value: (query: string) => ({
      matches: query.includes('min-width'),
      media: query,
      addEventListener: () => undefined,
      removeEventListener: () => undefined,
    }),
  })
})

afterEach(() => {
  while (mounted.length > 0) {
    mounted.pop()!.unmount()
  }

  document.body.innerHTML = ''
  vi.unstubAllGlobals()
})

describe('CA-CORE-262 (RN-CORE-50): nada de filas ni de búsqueda en el almacenamiento', () => {
  it('recorrer listado (con búsqueda), ficha e invitaciones solo escribe claves plataforma.table.<tableId> sin datos de fila', async () => {
    responders.push((path) => {
      if (path.startsWith('/users?')) {
        return respond(200, pageOf([userBody('U1')]))
      }

      if (path.startsWith('/users/U1')) {
        return respond(200, userBody('U1'))
      }

      if (path.startsWith('/invitations')) {
        return respond(200, pageOf([invitationBody()]))
      }

      if (path.startsWith('/user-imports/IMP1')) {
        return respond(200, importBody('IMP1'))
      }

      if (path.startsWith('/user-imports')) {
        return respond(200, pageOf([importBody('IMP1')]))
      }

      return undefined
    })

    const booted = await boot()

    // 1. Listado de usuarios, con búsqueda de texto.
    await go(booted, '/administracion/usuarios')
    expect(mainText()).toContain(ROW_NAME)

    const search = document.querySelector<HTMLInputElement>('main input[type="search"]')!

    search.value = SEARCH_TEXT
    search.dispatchEvent(new Event('input', { bubbles: true }))
    await new Promise((resolve) => setTimeout(resolve, 450))
    await booted.flush()

    // La búsqueda de verdad viajó al servidor: el texto existe y se usó.
    expect(search.value).toBe(SEARCH_TEXT)
    expect(fetchLog.some((path) => path.includes(`q=${SEARCH_TEXT}`))).toBe(true)

    await hideFirstHideableColumn()

    // 2. Ficha de usuario.
    await go(booted, '/administracion/usuarios/U1')
    expect(mainText()).toContain(ROW_DOC)

    // 3. Invitaciones.
    await go(booted, '/administracion/invitaciones')
    expect(mainText()).toContain(INVITATION_EMAIL)

    await hideFirstHideableColumn()

    // 4. Listado y detalle de importaciones (1.9c).
    await go(booted, '/administracion/importaciones')
    expect(mainText()).toContain(IMPORT_FILE)

    await hideFirstHideableColumn()

    await go(booted, '/administracion/importaciones/IMP1')
    expect(mainText()).toContain(IMPORT_MESSAGE)

    await hideFirstHideableColumn()

    // Escrituras: al menos una por cada tabla recorrida (no vacuo) …
    const tableWrites = writes.filter((write) => write.key.startsWith('plataforma.table.'))
    const tableKeys = new Set(tableWrites.map((write) => write.key))

    expect(tableKeys).toEqual(
      new Set([
        'plataforma.table.core.users',
        'plataforma.table.core.invitations',
        'plataforma.table.core.user_imports',
        'plataforma.table.core.user_import_errors',
      ]),
    )

    // … toda escritura es `set`/`remove` sobre una clave de tabla o una de las
    // legítimas ajenas, nombradas explícitamente; nunca `clear` ni sessionStorage.
    for (const write of writes) {
      expect(write.area).toBe('localStorage')
      expect(write.op).not.toBe('clear')
      expect(
        write.key.startsWith('plataforma.table.') || ALLOWED_NON_TABLE_KEYS.includes(write.key),
      ).toBe(true)
    }

    // Ningún dato de fila ni el texto de búsqueda en ninguna clave ni valor.
    const forbidden = [
      SEARCH_TEXT,
      ROW_EMAIL,
      ROW_NAME,
      ROW_DOC,
      ROW_PHONE,
      INVITATION_EMAIL,
      IMPORT_FILE,
      IMPORT_MESSAGE,
      'example.com',
    ]

    for (const write of writes) {
      for (const needle of forbidden) {
        expect(write.key).not.toContain(needle)
        expect(write.value).not.toContain(needle)
      }
    }

    // Y los valores de tabla son exactamente la forma de RN-CORE-43.
    for (const write of tableWrites.filter((candidate) => candidate.op === 'set')) {
      expect(write.value).toMatch(/^\{"v":1,"hidden":\[("[a-z_]+"(,"[a-z_]+")*)?\]\}$/)
    }

    // indexedDB: ni `open` ni `databases` ni `deleteDatabase`.
    expect(indexedDbCalls).toEqual([])
    expect(window.indexedDB.open).not.toHaveBeenCalled()
    expect(window.indexedDB.databases).not.toHaveBeenCalled()
  })
})

async function hideFirstHideableColumn(): Promise<void> {
  const { flushPromises } = await import('@vue/test-utils')

  const trigger = [...document.body.querySelectorAll('button')].find(
    (candidate) => candidate.textContent?.trim() === 'Columnas',
  )

  if (!trigger) {
    throw new Error('No hay menú de columnas')
  }

  trigger.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await flushPromises()

  const item = document.body.querySelector('[role="menuitemcheckbox"]')

  if (!item) {
    throw new Error('No hay columnas ocultables')
  }

  item.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await flushPromises()
  document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
  await flushPromises()
}

describe('CA-CORE-263 (CA-CORE-070, CA-CORE-073): 404 y 403 dentro del shell', () => {
  const FICHA = '/administracion/usuarios/OTRO'

  function assertInsideShell(): void {
    expect(document.querySelector('header')).not.toBeNull()
    expect(document.querySelector('aside nav, aside')).not.toBeNull()
    expect(document.querySelector('main#main-content [role="alert"]')).not.toBeNull()
  }

  it('ficha de usuario: GET 404 pinta «no encontrado» dentro del shell y no recarga /me', async () => {
    responders.push((path) =>
      path.startsWith('/users/OTRO') ? respond(404, { status: 404 }) : undefined,
    )

    const booted = await boot()
    await go(booted, FICHA)

    expect(fetchLog).toContain('/users/OTRO')
    assertInsideShell()
    expect(mainText()).toContain('Página no encontrada')
    // Solo la carga del arranque: un 404 no indica permisos desfasados.
    expect(meCalls()).toBe(1)
  })

  /**
   * #BUG-LOOP (ver informe): un `403` persistente provoca un bucle sin fin
   * recurso -> `GET /me` -> el shell pasa a «cargando» y desmonta la vista ->
   * la vista se vuelve a montar y repite el recurso. El tope de 6 respuestas
   * `403` es solo para que el test termine; a partir de él responde `404`.
   */
  function forbiddenCapped(prefix: string): { hits: () => number } {
    let hits = 0

    responders.push((path) => {
      if (!path.startsWith(prefix)) {
        return undefined
      }

      hits += 1

      return hits > 6 ? respond(404, { status: 404 }) : respond(403, { status: 403 })
    })

    return { hits: () => hits }
  }

  it('ficha de usuario: GET 403 recarga GET /me (§12.6), después de la petición que falló', async () => {
    forbiddenCapped('/users/OTRO')

    const booted = await boot()
    await go(booted, FICHA)

    expect(meCalls()).toBeGreaterThanOrEqual(2)
    expect(fetchLog.indexOf('/users/OTRO')).toBeGreaterThan(-1)
    expect(fetchLog.indexOf('/me', fetchLog.indexOf('/users/OTRO'))).toBeGreaterThan(
      fetchLog.indexOf('/users/OTRO'),
    )
  })

  it('invitaciones: GET 403 recarga GET /me (§12.6), después de la petición que falló', async () => {
    forbiddenCapped('/invitations')

    const booted = await boot()
    await go(booted, '/administracion/invitaciones')

    const first = fetchLog.findIndex((path) => path.startsWith('/invitations'))

    expect(first).toBeGreaterThan(-1)
    expect(fetchLog.indexOf('/me', first)).toBeGreaterThan(first)
  })

  it('detalle de importación: GET 404 pinta «no encontrado» dentro del shell y no recarga /me (CA-CORE-263)', async () => {
    responders.push((path) =>
      path.startsWith('/user-imports/OTRO') ? respond(404, { status: 404 }) : undefined,
    )

    const booted = await boot()
    await go(booted, '/administracion/importaciones/OTRO')

    expect(fetchLog).toContain('/user-imports/OTRO')
    assertInsideShell()
    expect(mainText()).toContain('Página no encontrada')
    expect(meCalls()).toBe(1)
  })

  it('detalle de importación: un 403 se pinta «sin acceso» dentro del shell, con una sola recarga de /me y sin repetir la petición (CA-CORE-263)', async () => {
    const forbidden = forbiddenCapped('/user-imports/OTRO')

    const booted = await boot()
    await go(booted, '/administracion/importaciones/OTRO')

    expect(forbidden.hits()).toBe(1)
    expect(meCalls()).toBe(2)
    assertInsideShell()
    expect(mainText()).toContain('Sin acceso')
  })

  // #300 corregido en develop (`fetchMe` ya no pasa por `loading` con la sesión `ready`).
  it('ficha de usuario: un 403 se pinta «sin acceso» dentro del shell, con una sola recarga de /me y sin repetir la petición', async () => {
    const forbidden = forbiddenCapped('/users/OTRO')

    const booted = await boot()
    await go(booted, FICHA)

    expect(forbidden.hits()).toBe(1)
    expect(meCalls()).toBe(2)
    assertInsideShell()
    expect(mainText()).toContain('Sin acceso')
  })

  it('invitaciones: un 403 se pinta «sin acceso» dentro del shell, con una sola recarga de /me y sin repetir la petición', async () => {
    const forbidden = forbiddenCapped('/invitations')

    const booted = await boot()
    await go(booted, '/administracion/invitaciones')

    expect(forbidden.hits()).toBe(1)
    expect(meCalls()).toBe(2)
    assertInsideShell()
    expect(mainText()).toContain('Sin acceso')
  })
})
