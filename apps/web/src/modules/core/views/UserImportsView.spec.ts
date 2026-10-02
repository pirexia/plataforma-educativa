/**
 * `docs/modulos/REQ-CORE/funcional.md §14.6.1`, `§14.6.4.6`, `§14.18`:
 * listado y subida de importaciones (1.9c) — `CA-CORE-233` (cabecera exacta,
 * casilla de invitaciones, `202` navega al detalle, `415` muestra el mensaje
 * del servidor sin navegar) y `CA-CORE-284` (lista de tipos de documento
 * admitidos con su nombre traducido).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'
import { DOCUMENT_TYPES } from '../documentTypes'
import { USER_IMPORT_HEADER, USER_IMPORT_MAX_BYTES } from '../userImportHeader'

const listUserImports = vi.fn()
const createUserImport = vi.fn()

vi.mock('../api', () => ({
  listUserImports: (...args: unknown[]) => listUserImports(...args),
  createUserImport: (...args: unknown[]) => createUserImport(...args),
}))

const { setFlash } = await import('../composables/useFlash')
const { recallImportInvitations } = await import('../composables/importInvitations')
const { default: UserImportsView } = await import('./UserImportsView.vue')

function importRow(id: string, overrides: Record<string, unknown> = {}) {
  return {
    public_id: id,
    original_filename: `personal-${id}.csv`,
    status: 'validado',
    row_count: 1234,
    error_count: 2,
    created_count: null,
    error_summary: null,
    report_url: null,
    created_at: '2026-09-01T10:00:00Z',
    validated_at: '2026-09-01T10:01:00Z',
    executed_at: null,
    ...overrides,
  }
}

function page(rows: unknown[]) {
  return { data: rows, meta: { current_page: 1, per_page: 25, total: rows.length, last_page: 1 } }
}

function problem(status: number, body: Record<string, unknown> = {}, headers?: Headers): ApiError {
  return new ApiError(`HTTP ${status}`, status, { status, ...body }, headers)
}

const wrappers: VueWrapper[] = []

async function mountView(): Promise<{ wrapper: VueWrapper; router: Router }> {
  const stub = { template: '<div/>' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/administracion/importaciones',
        name: 'core-user-imports',
        component: UserImportsView,
      },
      {
        path: '/administracion/importaciones/:publicId',
        name: 'core-user-import-detail',
        component: stub,
      },
    ],
  })

  await router.push('/administracion/importaciones')
  await router.isReady()

  const wrapper = mount(UserImportsView, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return { wrapper, router }
}

function fileInput(): HTMLInputElement {
  return document.getElementById('user-import-file') as HTMLInputElement
}

function invitationsBox(): HTMLInputElement {
  return document.body.querySelector<HTMLInputElement>('form input[type="checkbox"]')!
}

async function choose(file: File): Promise<void> {
  Object.defineProperty(fileInput(), 'files', { configurable: true, value: [file] })
  fileInput().dispatchEvent(new Event('change', { bubbles: true }))
  await flushPromises()
}

async function submit(wrapper: VueWrapper): Promise<void> {
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

function csv(name = 'personal.csv'): File {
  return new File([`${USER_IMPORT_HEADER}\n`], name, { type: 'application/octet-stream' })
}

beforeEach(() => {
  setLocale('es')
  listUserImports.mockReset().mockResolvedValue(page([importRow('A1'), importRow('B2')]))
  createUserImport.mockReset().mockResolvedValue({ public_id: 'NEW1', status: 'subido' })
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
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('CA-CORE-233 (RN-CORE-71): formulario de subida', () => {
  it('muestra la cabecera exacta de api.md §7 y la casilla de invitaciones marcada', async () => {
    await mountView()

    expect(document.getElementById('user-import-header')?.textContent?.trim()).toBe(
      'email;given_name;family_name_1;family_name_2;document_type;document_number;birth_date;contact_email;contact_phone;locale;roles',
    )
    expect(invitationsBox().checked).toBe(true)
    expect(fileInput().getAttribute('accept')).toBe('.csv')
  })

  it('con 202 navega al detalle del lote, enviando la casilla de invitaciones', async () => {
    const { wrapper, router } = await mountView()
    const file = csv()

    await choose(file)
    await submit(wrapper)

    expect(createUserImport).toHaveBeenCalledWith(file, true)
    expect(router.currentRoute.value.name).toBe('core-user-import-detail')
    expect(router.currentRoute.value.params.publicId).toBe('NEW1')
    expect(recallImportInvitations('NEW1')).toBe(true)
  })

  it('desmarcada, la casilla envía send_invitations = false y se recuerda para la confirmación', async () => {
    const { wrapper } = await mountView()
    const file = csv()

    invitationsBox().click()
    await flushPromises()
    await choose(file)
    await submit(wrapper)

    expect(createUserImport).toHaveBeenCalledWith(file, false)
    expect(recallImportInvitations('NEW1')).toBe(false)
  })

  it('con 415 muestra el mensaje del servidor sin navegar', async () => {
    createUserImport.mockRejectedValue(problem(415, { detail: 'El fichero no es un CSV válido.' }))
    const { wrapper, router } = await mountView()

    await choose(csv())
    await submit(wrapper)

    expect(document.body.querySelector('[role="alert"]')?.textContent).toContain(
      'El fichero no es un CSV válido.',
    )
    expect(router.currentRoute.value.name).toBe('core-user-imports')
  })

  it('con 413 muestra el detail del servidor y con 429 los segundos de Retry-After', async () => {
    createUserImport.mockRejectedValueOnce(
      problem(413, { detail: 'El fichero es demasiado grande.' }),
    )
    const { wrapper } = await mountView()

    await choose(csv())
    await submit(wrapper)
    expect(document.body.querySelector('[role="alert"]')?.textContent).toContain(
      'El fichero es demasiado grande.',
    )

    createUserImport.mockRejectedValueOnce(problem(429, {}, new Headers({ 'Retry-After': '42' })))
    await submit(wrapper)
    expect(document.body.querySelector('[role="alert"]')?.textContent).toContain('42')
  })

  it('un 422 del campo file se pinta bajo el campo con aria-invalid y aria-describedby', async () => {
    createUserImport.mockRejectedValue(
      problem(422, {
        errors: { file: [{ code: 'x', message: 'El fichero tiene demasiadas filas.' }] },
      }),
    )
    const { wrapper } = await mountView()

    await choose(csv())
    await submit(wrapper)

    expect(fileInput().getAttribute('aria-invalid')).toBe('true')
    expect(fileInput().getAttribute('aria-describedby')).toContain('user-import-file-error')
    expect(document.getElementById('user-import-file-error')?.textContent).toContain(
      'demasiadas filas',
    )
  })

  it('comodidad de cliente (RN-CORE-71): sin fichero, extensión distinta de .csv o más de 10 MB no sale ninguna petición', async () => {
    const { wrapper } = await mountView()

    await submit(wrapper)
    expect(document.getElementById('user-import-file-error')?.textContent).toContain(
      'Selecciona un fichero CSV',
    )

    await choose(new File(['x'], 'personal.txt'))
    await submit(wrapper)
    expect(document.getElementById('user-import-file-error')?.textContent).toContain('.csv')

    const big = new File(['x'], 'grande.csv')

    Object.defineProperty(big, 'size', { value: USER_IMPORT_MAX_BYTES + 1 })
    await choose(big)
    await submit(wrapper)
    expect(document.getElementById('user-import-file-error')?.textContent).toContain('10 MB')

    expect(createUserImport).not.toHaveBeenCalled()
  })

  it('copiar la cabecera la escribe en el portapapeles y lo anuncia con role="status"', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)

    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
    await mountView()

    const copy = [...document.body.querySelectorAll('button')].find(
      (button) => button.textContent?.trim() === 'Copiar la cabecera',
    )!

    copy.click()
    await flushPromises()

    expect(writeText).toHaveBeenCalledWith(USER_IMPORT_HEADER)
    expect(
      [...document.body.querySelectorAll('[role="status"]')].some((el) =>
        el.textContent?.includes('Cabecera copiada'),
      ),
    ).toBe(true)
  })
})

describe('CA-CORE-284 (§14.6.4.6): tipos de documento admitidos', () => {
  it('muestra, junto a la cabecera, los códigos del catálogo con su nombre traducido', async () => {
    await mountView()

    const items = [
      ...document.body.querySelectorAll('[data-testid="user-import-document-types"] li'),
    ].map((li) => li.textContent?.replace(/\s+/g, ' ').trim())

    expect(items).toEqual([
      'dni DNI (documento nacional de identidad)',
      'nie NIE (número de identidad de extranjero)',
      'pasaporte Pasaporte',
    ])
    expect(items).toHaveLength(DOCUMENT_TYPES.length)
  })

  it('los nombres cambian con el idioma', async () => {
    setLocale('en')
    await mountView()

    expect(
      document.body.querySelector('[data-testid="user-import-document-types"]')?.textContent,
    ).toContain('Passport')
  })
})

describe('listado de lotes (§14.6.1)', () => {
  it('pinta fichero (enlace al detalle), fecha de subida, estado, filas, filas con error y creados', async () => {
    await mountView()

    const headers = [...document.body.querySelectorAll('thead th')].map((th) =>
      th.textContent?.trim(),
    )

    expect(headers).toEqual(
      expect.arrayContaining([
        'Fichero',
        'Subido',
        'Estado',
        'Filas',
        'Filas con error',
        'Usuarios creados',
      ]),
    )

    const link = document.body.querySelector<HTMLAnchorElement>('tbody a')!

    expect(link.textContent?.trim()).toBe('personal-A1.csv')
    expect(link.getAttribute('href')).toBe('/administracion/importaciones/A1')

    const firstRow = document.body.querySelector('tbody tr')!.textContent ?? ''

    expect(firstRow).toContain('Validado')
    expect(firstRow).toContain('1234')
  })

  it('pide la página al servidor sin filtros ni orden', async () => {
    await mountView()

    expect(listUserImports).toHaveBeenCalledWith({ page: 1, per_page: 25 })
  })

  it('un mensaje dejado por otra pantalla (lote descartado) se muestra una sola vez', async () => {
    setFlash({ key: 'core.userImports.flash.discarded', params: { file: 'personal-X.csv' } })
    await mountView()

    expect(document.body.querySelector('[role="status"]')?.textContent).toContain(
      'Importación de personal-X.csv descartada.',
    )
  })
})
