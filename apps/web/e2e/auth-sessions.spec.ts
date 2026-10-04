import { test, expect, type Page } from '@playwright/test'

/**
 * `docs/modulos/REQ-CORE/funcional.md §14.13.6`, criterio **[Playwright]** de
 * 1.9f: `CA-CORE-259` (parte de sesiones, `RUX-RESP-004`): `/cuenta/sesiones`
 * en tarjetas a 320 px y como tabla a 1024 px, sin desplazamiento
 * horizontal. Sin backend real: se intercepta la API con `page.route`, mismo
 * patrón que `e2e/core-audit.spec.ts`.
 */

const BRANDING = {
  name: 'Centro de ejemplo',
  color_primary: '#1D4ED8',
  color_secondary: '#FFFFFF',
  logo_url: null,
  favicon_url: null,
  login_background_url: null,
  default_locale: 'es-ES',
  active_locales: ['es-ES', 'en', 'de', 'fr'],
}

const ME = {
  public_id: '01J-ME',
  email: 'ana@example.com',
  status: 'activo',
  person: {
    public_id: '01J-PERSON',
    given_name: 'Ana',
    family_name_1: 'García',
    family_name_2: null,
    contact_email: null,
    contact_phone: null,
    document_type: null,
    document_number: null,
    birth_date: null,
    locale: 'es-ES',
  },
  roles: [],
  permissions: ['auditoria.leer', 'auditoria.exportar', 'usuario.leer', 'rol.leer'],
  email_verified_at: '2026-01-01T00:00:00Z',
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
  deleted_at: null,
}

function session(id: string, current: boolean) {
  return {
    public_id: id,
    current,
    started_at: current ? '2026-10-03T10:15:00Z' : '2026-10-02T08:00:00Z',
    last_activity_at: '2026-10-03T11:00:00Z',
    ip_address: '203.0.113.7',
    client: { browser: 'Chrome', platform: 'Windows', device_type: 'escritorio' },
    location: null,
    device_known: true,
  }
}

async function mockBackend(page: Page): Promise<void> {
  const json = (body: unknown) => ({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify(body),
  })

  await page.route('**/tenant/branding', (route) => route.fulfill(json(BRANDING)))
  await page.route('**/auth/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }))
  await page.route('**/me', (route) => route.fulfill(json(ME)))
  await page.route(/\/api\/v1\/auth\/sessions(\?.*)?$/, (route) =>
    route.fulfill(
      json({
        data: [session('S1', true), session('S2', false), session('S3', false)],
        meta: { current_page: 1, per_page: 25, total: 3, last_page: 1 },
      }),
    ),
  )
}

async function documentFitsWidth(page: Page): Promise<boolean> {
  return page.evaluate(
    () => document.documentElement.scrollWidth <= document.documentElement.clientWidth,
  )
}

test.describe('CA-CORE-259 (RUX-RESP-004): sesiones, en tarjetas por debajo de 768 px', () => {
  test('a 320 px es una lista de tarjetas y no table, sin desplazamiento horizontal; a 1024 px, table', async ({
    page,
  }) => {
    await mockBackend(page)
    await page.setViewportSize({ width: 320, height: 900 })
    await page.goto('/cuenta/sesiones')

    await expect(page.locator('ul[data-slot="data-table-cards"] > li')).toHaveCount(3)
    await expect(page.locator('table')).toHaveCount(0)
    expect(await documentFitsWidth(page)).toBe(true)

    await page.setViewportSize({ width: 1024, height: 900 })

    await expect(page.locator('table')).toBeVisible()
    await expect(page.locator('ul[data-slot="data-table-cards"]')).toHaveCount(0)
    expect(await documentFitsWidth(page)).toBe(true)
  })
})
