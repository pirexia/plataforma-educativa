import { test, expect, type Page } from '@playwright/test'

/**
 * `docs/modulos/REQ-CORE/funcional.md §14.18`, criterios **[Playwright]** de
 * 1.9e: `CA-CORE-260` (recorrido solo con teclado, etiquetas y objetivos
 * táctiles en la configuración del centro; `OPEN-CORE-14`) y `CA-CORE-265` con
 * el *router* y el *shell* reales (el perfil propio se abre con
 * `/me.permissions` vacío y no sale otra petición `GET /me` que la del *guard*).
 * Sin backend real: se intercepta la API con `page.route`, mismo patrón que
 * `e2e/core-users.spec.ts`.
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

function me(permissions: string[]) {
  return {
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
    permissions,
    email_verified_at: '2026-01-01T00:00:00Z',
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    deleted_at: null,
  }
}

const SETTINGS = {
  public_id: 'T1',
  regional: {
    default_locale: 'es-ES',
    active_locales: ['es-ES', 'en'],
    timezone: 'Europe/Madrid',
    currency: 'EUR',
    autonomous_community: 'MD',
  },
  fiscal: {
    legal_name: 'Colegio Ficticio S.L.',
    tax_id: 'B00000000',
    address: 'Calle Inventada 1',
    postal_code: '28000',
    city: 'Madrid',
    province: 'Madrid',
    country_code: 'ES',
  },
  branding: {
    color_primary: '#1D4ED8',
    color_secondary: '#FFFFFF',
    logo_url: null,
    favicon_url: null,
    login_background_url: null,
  },
  security: {
    session_timeout_minutes: 30,
    mfa_allowed_methods: ['totp'],
    mfa_grace_period_days: 7,
  },
  updated_at: '2026-08-19T09:00:00Z',
}

async function mockBackend(
  page: Page,
  permissions: string[],
): Promise<{ meRequests: () => number }> {
  let meRequests = 0
  const json = (body: unknown) => ({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify(body),
  })

  await page.route('**/tenant/branding', (route) => route.fulfill(json(BRANDING)))
  await page.route('**/auth/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }))
  await page.route('**/me', (route) => {
    meRequests += 1

    return route.fulfill(json(me(permissions)))
  })
  await page.route(/\/api\/v1\/tenant\/settings$/, (route) => route.fulfill(json(SETTINGS)))

  return { meRequests: () => meRequests }
}

test.describe('CA-CORE-260 (RUX-004, WCAG 2.2 AA): configuración del centro', () => {
  test('se recorre con el teclado en orden de documento y todo campo tiene etiqueta', async ({
    page,
  }) => {
    await mockBackend(page, ['configuracion.leer', 'configuracion.actualizar'])
    await page.setViewportSize({ width: 1280, height: 900 })
    await page.goto('/administracion/centro')
    await expect(
      page.getByRole('heading', { level: 1, name: 'Configuración del centro' }),
    ).toBeVisible()

    // Todo campo tiene etiqueta asociada.
    for (const label of [
      'Idioma por defecto',
      'Zona horaria',
      'Moneda',
      'Comunidad autónoma',
      'Razón social',
      'NIF/CIF',
      'Dirección',
      'Código postal',
      'Municipio',
      'Provincia',
      'País',
      'Color primario',
      'Color secundario',
      'Cierre de sesión por inactividad',
      'Plazo de gracia para activar el segundo factor',
    ]) {
      await expect(page.getByLabel(label, { exact: false }).first(), label).toBeVisible()
    }

    // Obligatorios marcados de forma no solo visual: texto para lectores de pantalla y `required`.
    for (const id of [
      'settings-regional-default_locale',
      'settings-regional-currency',
      'settings-security-session_timeout_minutes',
    ]) {
      await expect(page.locator(`#${id}`)).toHaveAttribute('required', '')
      await expect(page.locator(`label[for="${id}"] .sr-only`)).toHaveText('(obligatorio)')
    }

    await page.locator('#settings-regional-active_locales').focus()

    const visited: string[] = ['settings-regional-active_locales']

    for (let step = 0; step < 40; step += 1) {
      await page.keyboard.press('Tab')

      const id = await page.evaluate(() => {
        const el = document.activeElement as HTMLElement | null

        return !el || el === document.body ? 'BODY' : el.id || el.textContent?.trim() || el.tagName
      })

      visited.push(id)

      if (id === 'settings-security-mfa_grace_period_days') {
        break
      }
    }

    const order = [
      'settings-regional-active_locales',
      'settings-regional-default_locale',
      'settings-regional-timezone-search',
      'settings-regional-timezone',
      'settings-regional-currency',
      'settings-regional-autonomous_community',
      'settings-fiscal-legal_name',
      'settings-fiscal-tax_id',
      'settings-fiscal-address',
      'settings-fiscal-postal_code',
      'settings-fiscal-city',
      'settings-fiscal-province',
      'settings-fiscal-country_code',
      'settings-palette-color_primary',
      'settings-palette-color_secondary',
      'settings-security-session_timeout_minutes',
      'settings-security-mfa_allowed_methods',
      'settings-security-mfa_grace_period_days',
    ]
    const positions = order.map((id) => visited.indexOf(id))

    expect(
      positions.every((position) => position >= 0),
      `recorrido: ${visited.join(' | ')}`,
    ).toBe(true)
    expect(
      [...positions].sort((a, b) => a - b),
      `orden: ${visited.join(' | ')}`,
    ).toEqual(positions)
    expect(visited).not.toContain('BODY')
  })

  test('en solo lectura no hay ningún campo ni botón de guardar', async ({ page }) => {
    await mockBackend(page, ['configuracion.leer'])
    await page.goto('/administracion/centro')
    await expect(
      page.getByRole('heading', { level: 1, name: 'Configuración del centro' }),
    ).toBeVisible()
    await expect(page.getByText('Colegio Ficticio S.L.')).toBeVisible()
    await expect(page.locator('main input, main select, main button[type="submit"]')).toHaveCount(0)
  })

  test.describe('objetivos táctiles de 44 px con puntero grueso (OPEN-CORE-14)', () => {
    test.use({ hasTouch: true, isMobile: true })

    async function expectTarget(page: Page, selector: string, name: string): Promise<void> {
      const box = await page.locator(selector).first().boundingBox()

      expect(box, name).not.toBeNull()
      expect(box!.height, `${name}: alto`).toBeGreaterThanOrEqual(43.5)
      expect(box!.width, `${name}: ancho`).toBeGreaterThanOrEqual(43.5)
    }

    test('campos, selectores, casillas y botones de guardar', async ({ page }) => {
      await mockBackend(page, ['configuracion.leer', 'configuracion.actualizar'])
      await page.setViewportSize({ width: 360, height: 900 })
      await page.goto('/administracion/centro')
      await expect(page.locator('#settings-regional-currency')).toBeVisible()

      for (const id of [
        'settings-regional-default_locale',
        'settings-regional-timezone',
        'settings-regional-currency',
        'settings-fiscal-city',
        'settings-palette-color_primary',
        'settings-security-session_timeout_minutes',
      ]) {
        await expectTarget(page, `#${id}`, id)
      }

      // La casilla se activa pulsando su etiqueta: el objetivo es la etiqueta entera.
      await expectTarget(page, 'label:has(#settings-regional-active_locales)', 'casilla de idioma')
      await expectTarget(
        page,
        'label:has(#settings-security-mfa_allowed_methods)',
        'casilla de método',
      )
      await expectTarget(page, 'button[type="submit"]', 'guardar')
    })
  })
})

test.describe('CA-CORE-265 (RN-CORE-88): perfil propio con el shell real', () => {
  test('con /me.permissions vacío, «Perfil» está en «Mi cuenta», la pantalla se abre y solo sale el GET /me del guard', async ({
    page,
  }) => {
    const backend = await mockBackend(page, [])

    await page.setViewportSize({ width: 1280, height: 900 })
    await page.goto('/cuenta/perfil')
    await expect(page.getByRole('heading', { level: 1, name: 'Mi perfil' })).toBeVisible()
    await expect(page.locator('#main-content').getByText('Ana García')).toBeVisible()
    await expect(page.locator('#main-content').getByText('ana@example.com')).toBeVisible()
    await expect(page.locator('#profile-contact_email')).toBeVisible()
    await expect(page.locator('#profile-contact_phone')).toBeVisible()
    await expect(page.locator('main select')).toHaveCount(0)
    await expect(page.getByRole('navigation').getByRole('link', { name: 'Perfil' })).toBeVisible()
    expect(backend.meRequests()).toBe(1)
  })
})
