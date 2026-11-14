import { test, expect, type Page } from '@playwright/test'

/**
 * `docs/modulos/REQ-CORE/funcional.md §14.18`, criterios **[Playwright]** de
 * 1.9d: `CA-CORE-259` (la auditoría y el listado de roles, en tarjetas a
 * 320 px y como tabla a 1024 px, sin desplazamiento horizontal) y
 * `CA-CORE-246` con foco real (panel «Ver cambios») y etiquetas accesibles
 * de los filtros de auditoría (`CA-CORE-244`). Sin backend real: se intercepta la API
 * con `page.route`, mismo patrón que `e2e/core-users.spec.ts`.
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

function entry(id: string, event: string) {
  return {
    public_id: id,
    occurred_at: '2026-03-02T10:30:00Z',
    actor: { public_id: '01HZX0000000000000000000AA', display_name: 'Lucía Pérez' },
    actor_type: 'user',
    auditable_type: 'user',
    auditable_public_id: '01HZX0000000000000000000ZZ',
    event,
    changes: {
      status: { from: 'pendiente', to: 'activo' },
      document_number: { redacted: 'identifier', from_empty: false, to_empty: false },
    },
    ip_address: null,
    user_agent: null,
    request_id: null,
  }
}

const FACETS = {
  modules: ['core'],
  auditable_types: [{ alias: 'user', module: 'core' }],
  events: ['created', 'updated'],
  actor_types: ['user', 'system'],
}

const ROLES = {
  data: [
    {
      public_id: 'R1',
      code: 'docente',
      name: 'Docente',
      is_system: true,
      mfa_required: false,
      special_data_access: false,
      users_count: 4,
    },
    {
      public_id: 'R2',
      code: 'secretaria',
      name: 'Secretaría',
      is_system: true,
      mfa_required: true,
      special_data_access: false,
      users_count: 2,
    },
  ],
  meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
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
  await page.route(/\/api\/v1\/audit-logs\/facets$/, (route) => route.fulfill(json(FACETS)))
  await page.route(/\/api\/v1\/audit-logs(\?.*)?$/, (route) =>
    route.fulfill(
      json({
        data: [entry('L1', 'updated'), entry('L2', 'created'), entry('L3', 'updated')],
        meta: { next_cursor: null, has_more: false },
      }),
    ),
  )
  await page.route(/\/api\/v1\/roles(\?.*)?$/, (route) => route.fulfill(json(ROLES)))
}

async function documentFitsWidth(page: Page): Promise<boolean> {
  return page.evaluate(
    () => document.documentElement.scrollWidth <= document.documentElement.clientWidth,
  )
}

test.describe('CA-CORE-259 (RUX-RESP-004): auditoría y roles, en tarjetas por debajo de 768 px', () => {
  test('la auditoría a 320 px es una lista de tarjetas y no table, sin desplazamiento horizontal; a 1024 px, table', async ({
    page,
  }) => {
    await mockBackend(page)
    await page.setViewportSize({ width: 320, height: 900 })
    await page.goto('/administracion/auditoria')

    await expect(page.locator('ul[data-slot="data-table-cards"] > li')).toHaveCount(3)
    await expect(page.locator('table')).toHaveCount(0)
    expect(await documentFitsWidth(page)).toBe(true)

    await page.setViewportSize({ width: 1024, height: 900 })

    await expect(page.locator('table')).toBeVisible()
    await expect(page.locator('ul[data-slot="data-table-cards"]')).toHaveCount(0)
    expect(await documentFitsWidth(page)).toBe(true)
  })

  test('los roles a 320 px son tarjetas y a 1024 px, table', async ({ page }) => {
    await mockBackend(page)
    await page.setViewportSize({ width: 320, height: 900 })
    await page.goto('/administracion/roles')

    await expect(page.locator('ul[data-slot="data-table-cards"] > li')).toHaveCount(2)
    await expect(page.locator('table')).toHaveCount(0)
    expect(await documentFitsWidth(page)).toBe(true)

    await page.setViewportSize({ width: 1024, height: 900 })

    await expect(page.locator('table')).toBeVisible()
    expect(await documentFitsWidth(page)).toBe(true)
  })
})

test.describe('CA-CORE-246/-244 (RUX-004, WCAG 2.2 AA): «Ver cambios» y filtros', () => {
  test('el panel atrapa el foco, Esc lo cierra y el foco vuelve al botón', async ({ page }) => {
    await mockBackend(page)
    await page.setViewportSize({ width: 1280, height: 900 })
    await page.goto('/administracion/auditoria')

    const open = page.getByRole('button', { name: /^Ver cambios/ }).first()

    await open.focus()
    await page.keyboard.press('Enter')

    const dialog = page.getByRole('dialog')

    await expect(dialog).toBeVisible()
    await expect(dialog).toContainText('pendiente → activo')
    await expect(dialog).toContainText('Valor no registrado')

    for (let step = 0; step < 4; step += 1) {
      await page.keyboard.press('Tab')
      expect(
        await page.evaluate(() => document.activeElement?.closest('[role="dialog"]') !== null),
      ).toBe(true)
    }

    await page.keyboard.press('Escape')
    await expect(dialog).toHaveCount(0)
    await expect(open).toBeFocused()
  })

  test('el filtro por usuario y el rango de fechas tienen etiqueta accesible', async ({ page }) => {
    await mockBackend(page)
    await page.setViewportSize({ width: 1280, height: 900 })
    await page.goto('/administracion/auditoria')

    await expect(
      page.locator('[data-slot="data-table-entity-filter"] input[type="search"]'),
    ).toBeVisible()
    await expect(page.getByLabel('Buscar: Usuario')).toBeVisible()
    await expect(page.getByLabel('Desde')).toBeVisible()
    await expect(page.getByLabel('Hasta')).toBeVisible()
  })
})
