import { test, expect, type Page } from '@playwright/test'
import { deriveOnBackground } from '../src/design-system/color/deriveOnBackground'
import { hexToRgb } from '../src/design-system/color/color'

/**
 * `docs/design-system.md` §18 (`CA-DS-009`, `CA-DS-036`). Necesitan
 * cálculo real de estilos (jsdom no aplica hojas ni *media queries*), así
 * que van en Playwright y no en Vitest (§18: "salvo los marcados
 * [Playwright]"). Sin backend real: se intercepta la API con
 * `page.route` (mismo patrón que un centro de desarrollo con
 * `color_primary = #1D4ED8`, el ejemplo de `ADR-052 §Contexto`).
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

async function mockBackend(page: Page): Promise<void> {
  await page.route('**/tenant/branding', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(BRANDING) }),
  )
  await page.route('**/auth/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }))
  await page.route('**/auth/identity-providers', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: [] }),
    }),
  )
}

function hexToRgbString(hex: string): string {
  const { r, g, b } = hexToRgb(hex)

  return `rgb(${Math.round(r * 255)}, ${Math.round(g * 255)}, ${Math.round(b * 255)})`
}

test.describe('CA-DS-009: prefers-reduced-motion anula la duración de transición', () => {
  test('con reducedMotion emulado, ~0ms; sin emular, 150ms', async ({ page }) => {
    await mockBackend(page)
    await page.goto('/entrar')

    const submitButton = page.getByRole('button', { name: /iniciar sesión|entrar/i })
    await expect(submitButton).toBeVisible()

    const withoutEmulation = await submitButton.evaluate(
      (el) => getComputedStyle(el).transitionDuration,
    )
    expect(withoutEmulation).toBe('0.15s')

    await page.emulateMedia({ reducedMotion: 'reduce' })
    const withEmulation = await submitButton.evaluate(
      (el) => getComputedStyle(el).transitionDuration,
    )
    // "0.01ms" en CSS: el navegador lo serializa en segundos, en notación
    // exponencial (`1e-05s`).
    expect(withEmulation).toBe('1e-05s')
  })
})

test.describe('CA-DS-036: --primary-on-background en /entrar, claro y oscuro', () => {
  test('el enlace de recuperación usa la variante derivada; el botón, el color exacto', async ({
    page,
  }) => {
    await mockBackend(page)

    await page.emulateMedia({ colorScheme: 'light' })
    await page.goto('/entrar')

    const link = page.getByRole('link', { name: /contraseña/i })
    const button = page.getByRole('button', { name: /iniciar sesión|entrar/i })

    await expect(link).toBeVisible()

    const linkColorLight = await link.evaluate((el) => getComputedStyle(el).color)
    expect(linkColorLight).toBe(hexToRgbString(deriveOnBackground('#1D4ED8', 'light')))

    const buttonBgLight = await button.evaluate((el) => getComputedStyle(el).backgroundColor)
    expect(buttonBgLight).toBe(hexToRgbString('#1D4ED8'))

    await page.emulateMedia({ colorScheme: 'dark' })
    await page.reload()
    await expect(link).toBeVisible()

    const linkColorDark = await link.evaluate((el) => getComputedStyle(el).color)
    expect(linkColorDark).toBe(hexToRgbString(deriveOnBackground('#1D4ED8', 'dark')))

    const buttonBgDark = await button.evaluate((el) => getComputedStyle(el).backgroundColor)
    expect(buttonBgDark).toBe(hexToRgbString('#1D4ED8'))
  })
})
