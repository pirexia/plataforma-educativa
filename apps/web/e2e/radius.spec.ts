import { test, expect, type Page } from '@playwright/test'

/**
 * `docs/modulos/REQ-CORE/capa-visual.md §12.1`, `CA-UI-004` (`RN-DS-25`):
 * el radio calculado del botón principal, de un campo de texto, de un menú
 * desplegable y de un diálogo es `2px`. Necesita estilos calculados, así que
 * va en Playwright, con la API simulada (mismo patrón que
 * `e2e/core-users.spec.ts`).
 */

const BRANDING = {
  name: 'Centro de ejemplo',
  color_primary: '#1D4ED8',
  color_secondary: '#FFFFFF',
  logo_url: null,
  favicon_url: null,
  login_background_url: null,
  default_locale: 'es-ES',
  active_locales: ['es-ES'],
}

const PERSON = {
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
}

const ME = {
  public_id: '01J-ME',
  email: 'ana@example.com',
  status: 'activo',
  person: PERSON,
  roles: [],
  permissions: ['usuario.leer', 'usuario.actualizar', 'usuario.eliminar', 'rol.leer'],
  email_verified_at: '2026-01-01T00:00:00Z',
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
  deleted_at: null,
}

const USER = {
  public_id: 'U1',
  email: 'lucia@example.com',
  status: 'activo',
  person: {
    ...PERSON,
    public_id: 'P-U1',
    given_name: 'Lucía',
    family_name_1: 'Pérez',
    family_name_2: 'Gómez',
  },
  roles: [{ public_id: 'R1', code: 'docente', name: 'Docente' }],
  email_verified_at: null,
  created_at: '2026-09-01T10:00:00Z',
  updated_at: '2026-09-01T10:00:00Z',
  deleted_at: null,
}

async function mockBackend(page: Page): Promise<void> {
  const json = (body: unknown) => ({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify(body),
  })

  await page.route('**/tenant/branding', (route) => route.fulfill(json(BRANDING)))
  await page.route('**/auth/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }))
  await page.route('**/auth/identity-providers', (route) => route.fulfill(json({ data: [] })))
  await page.route('**/me', (route) => route.fulfill(json(ME)))
  await page.route(/\/api\/v1\/roles(\?.*)?$/, (route) =>
    route.fulfill(
      json({
        data: [{ public_id: 'R1', code: 'docente', name: 'Docente', is_system: true }],
        meta: { current_page: 1, per_page: 100, total: 1, last_page: 1 },
      }),
    ),
  )
  await page.route(/\/api\/v1\/users\/U1(\?.*)?$/, (route) => route.fulfill(json(USER)))
  await page.route(/\/api\/v1\/users\/U1\/roles$/, (route) =>
    route.fulfill(json({ data: USER.roles })),
  )
}

const radiusOf = (locator: ReturnType<Page['locator']>) =>
  locator.evaluate((el) => {
    const cs = getComputedStyle(el)

    return [
      cs.borderTopLeftRadius,
      cs.borderTopRightRadius,
      cs.borderBottomRightRadius,
      cs.borderBottomLeftRadius,
    ]
  })

test.describe('CA-UI-004 (RN-DS-25): radio de 2 px calculado', () => {
  test('/entrar: botón principal y campo de texto', async ({ page }) => {
    await mockBackend(page)
    await page.goto('/entrar')

    await expect(page.locator('#login-email')).toBeVisible()
    expect(await radiusOf(page.locator('#login-email'))).toEqual(['2px', '2px', '2px', '2px'])
    expect(await radiusOf(page.getByRole('button', { name: 'Entrar', exact: true }))).toEqual([
      '2px',
      '2px',
      '2px',
      '2px',
    ])
  })

  test('pantalla autenticada: menú desplegable y diálogo', async ({ page }) => {
    await mockBackend(page)
    await page.setViewportSize({ width: 1280, height: 900 })
    await page.goto('/administracion/usuarios/U1')
    await expect(page.getByRole('heading', { level: 1, name: 'Lucía Pérez Gómez' })).toBeVisible()

    await page.getByRole('button', { name: 'Menú de usuario' }).click()
    const menu = page.locator('[data-slot="dropdown-menu-content"]')
    await expect(menu).toBeVisible()
    expect(await radiusOf(menu)).toEqual(['2px', '2px', '2px', '2px'])
    await page.keyboard.press('Escape')
    await expect(menu).toHaveCount(0)

    // Mismo gesto que `e2e/core-users.spec.ts`: foco y Enter.
    await page.getByRole('button', { name: 'Dar de baja' }).focus()
    await page.keyboard.press('Enter')
    const dialog = page.getByRole('alertdialog')
    await expect(dialog).toBeVisible()
    expect(await radiusOf(dialog)).toEqual(['2px', '2px', '2px', '2px'])
  })
})
