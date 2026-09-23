import { test, expect, type Page } from '@playwright/test'

/**
 * `docs/modulos/REQ-CORE/funcional.md` §18/`§12.11`. Criterios marcados
 * **[Playwright]**: necesitan *layout* real, *media queries* o medida de
 * cajas (jsdom no las da). Sin backend real: se intercepta la API con
 * `page.route`, mismo patrón que `e2e/design-system.spec.ts`.
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
  public_id: '01J-USER',
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
  roles: [{ public_id: '01J-ROLE', code: 'administrador_centro', name: 'Administrador del centro' }],
  // `mfa.leer`: entrada "Administración de MFA" visible como acceso
  // directo, para que el panel de accesos directos no esté vacío.
  permissions: ['mfa.leer'],
  email_verified_at: '2026-01-01T00:00:00Z',
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
  deleted_at: null,
}

async function mockBackend(page: Page): Promise<void> {
  await page.route('**/tenant/branding', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(BRANDING) }),
  )
  await page.route('**/auth/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }))
  await page.route('**/me', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(ME) }),
  )
}

test.describe('CA-CORE-080 (RUX-RESP-001/002): sin desplazamiento horizontal', () => {
  test('en los cinco anchos de referencia', async ({ page }) => {
    await mockBackend(page)
    await page.goto('/')
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    for (const width of [320, 768, 1024, 1440, 1920]) {
      await page.setViewportSize({ width, height: 900 })
      const [scrollWidth, clientWidth] = await page.evaluate(() => [
        document.documentElement.scrollWidth,
        document.documentElement.clientWidth,
      ])
      expect(scrollWidth, `ancho ${width}px`).toBeLessThanOrEqual(clientWidth)
    }
  })
})

test.describe('CA-CORE-081 (RUX-RESP-003): regímenes de navegación', () => {
  test('≥1024: barra lateral visible sin interacción, sin botón de menú', async ({ page }) => {
    await mockBackend(page)
    await page.setViewportSize({ width: 1200, height: 900 })
    await page.goto('/')

    await expect(page.getByRole('navigation', { name: 'Navegación principal' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Abrir menú' })).toHaveCount(0)
  })

  test('768-1023: la navegación no es visible; un botón abre un panel lateral parcial', async ({
    page,
  }) => {
    await mockBackend(page)
    await page.setViewportSize({ width: 900, height: 900 })
    await page.goto('/')

    await expect(page.getByRole('navigation', { name: 'Navegación principal' })).toHaveCount(0)

    const trigger = page.getByRole('button', { name: 'Abrir menú' })
    await expect(trigger).toBeVisible()
    await trigger.click()

    const panel = page.locator('[data-slot="sheet-content"]')
    await expect(panel).toBeVisible()
    const box = await panel.boundingBox()
    expect(box).not.toBeNull()
    expect(box!.width).toBeLessThan(900 * 0.9)
  })

  test('<768: un botón de hamburguesa abre el menú a pantalla completa', async ({ page }) => {
    await mockBackend(page)
    await page.setViewportSize({ width: 375, height: 900 })
    await page.goto('/')

    const trigger = page.getByRole('button', { name: 'Abrir menú' })
    await expect(trigger).toBeVisible()
    await trigger.click()

    const panel = page.locator('[data-slot="sheet-content"]')
    await expect(panel).toBeVisible()
    const box = await panel.boundingBox()
    expect(box).not.toBeNull()
    expect(box!.width).toBeGreaterThan(375 * 0.9)
  })
})

test.describe('CA-CORE-084 (RUX-RESP-007): objetivos táctiles ≥44×44px', () => {
  test('el botón de menú, las entradas de navegación y el menú de usuario miden ≥44×44 a 320 y 768px', async ({
    page,
  }) => {
    await mockBackend(page)

    for (const width of [320, 768]) {
      await page.setViewportSize({ width, height: 900 })
      await page.goto('/')

      const menuButton = page.getByRole('button', { name: 'Abrir menú' })
      const menuBox = await menuButton.boundingBox()
      expect(menuBox!.width, `botón de menú a ${width}px`).toBeGreaterThanOrEqual(44)
      expect(menuBox!.height, `botón de menú a ${width}px`).toBeGreaterThanOrEqual(44)

      const userMenuButton = page.getByRole('button', { name: /Menú de usuario/ })
      const userMenuBox = await userMenuButton.boundingBox()
      expect(userMenuBox!.width, `menú de usuario a ${width}px`).toBeGreaterThanOrEqual(44)
      expect(userMenuBox!.height, `menú de usuario a ${width}px`).toBeGreaterThanOrEqual(44)

      await menuButton.click()
      const navLink = page.locator('[data-slot="sheet-content"] nav a').first()
      await expect(navLink).toBeVisible()
      const navLinkBox = await navLink.boundingBox()
      expect(navLinkBox!.height, `entrada de navegación a ${width}px`).toBeGreaterThanOrEqual(44)
      await page.keyboard.press('Escape')
    }
  })

  test('CA-CORE-084 ampliado (OPEN-CORE-14): un botón base solo sube a 44px con any-pointer:coarse', async ({
    browser,
  }) => {
    await test.step('con puntero fino (escritorio), la altura por defecto es la normal (32px)', async () => {
      const context = await browser.newContext({ hasTouch: false })
      const page = await context.newPage()
      await mockBackend(page)
      await page.goto('/entrar')

      const button = page.getByRole('button', { name: /iniciar sesión|entrar/i })
      const box = await button.boundingBox()
      expect(box!.height).toBeLessThan(44)

      await context.close()
    })

    await test.step('con puntero grueso (táctil), la altura mínima sube a 44px', async () => {
      const context = await browser.newContext({ hasTouch: true })
      const page = await context.newPage()
      await mockBackend(page)
      await page.goto('/entrar')

      const button = page.getByRole('button', { name: /iniciar sesión|entrar/i })
      const box = await button.boundingBox()
      expect(box!.height).toBeGreaterThanOrEqual(44)

      await context.close()
    })
  })
})

test.describe('CA-CORE-087 (RUX-005): transición de ruta sin recarga', () => {
  test('navegar entre dos rutas del shell no recarga el documento', async ({ page }) => {
    await mockBackend(page)
    await page.setViewportSize({ width: 1200, height: 900 })
    await page.goto('/')

    await page.evaluate(() => {
      ;(window as unknown as { __marker: boolean }).__marker = true
    })

    await page.getByRole('link', { name: 'Sesiones abiertas' }).click()
    await page.waitForURL('**/cuenta/sesiones')

    const markerSurvived = await page.evaluate(
      () => (window as unknown as { __marker?: boolean }).__marker === true,
    )
    expect(markerSurvived).toBe(true)
  })

  test('la regla de transición de ruta usa el token de movimiento (reactivo a prefers-reduced-motion)', async ({
    page,
  }) => {
    await mockBackend(page)
    await page.goto('/')

    const usesToken = await page.evaluate(() => {
      for (const sheet of Array.from(document.styleSheets)) {
        let rules: CSSRuleList
        try {
          rules = sheet.cssRules
        } catch {
          continue
        }
        for (const rule of Array.from(rules)) {
          if (
            rule instanceof CSSStyleRule &&
            rule.selectorText.includes('route-enter-active') &&
            rule.style.transition.includes('--motion-duration-normal')
          ) {
            return true
          }
        }
      }
      return false
    })

    expect(usesToken).toBe(true)
  })
})
