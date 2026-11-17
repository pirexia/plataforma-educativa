import { test, expect, type Page } from '@playwright/test'

/**
 * `RNF-MANT-007` (mantenimiento de dependencias; no hay un criterio de aceptación de scroll): cobertura del
 * comportamiento de scroll al navegar tras pasar a vue-router 5 (#729). Comportamiento exigido, idéntico al anterior
 * `scrollBehavior() { return { top: 0 } }` salvo atrás/adelante (ver caso (b)): toda navegación terminada (ruta nueva,
 * cambio solo de query de una tabla) deja la ventana arriba del todo, y `UI-3` (`REQ-FAM-UNIT/funcional.md §23.2`) se mantiene: el router no
 * guarda posiciones en `sessionStorage` (`vue-router:scroll` ausente o `{}`).
 *
 * Contra la API real: `npm run test:e2e:real` (suite `core`, `scripts/e2e-real-api.sh`).
 */
test.describe('RNF-MANT-007: scroll al navegar (vue-router 5) contra la API real', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real: ejecútalo con `npm run test:e2e:real` (apps/web/scripts/e2e-real-api.sh).',
  )

  test.use({
    baseURL: process.env.E2E_BASE_URL ?? 'http://demo.plataforma.test:5173',
    // Viewport bajo: garantiza contenido más alto que la ventana en cualquier lista.
    viewport: { width: 1280, height: 240 },
  })

  const scrollY = (page: Page) => page.evaluate(() => window.scrollY)

  async function scrollDown(page: Page): Promise<number> {
    await page.evaluate(() => window.scrollTo({ top: document.documentElement.scrollHeight }))
    await expect.poll(() => scrollY(page)).toBeGreaterThan(50)
    return scrollY(page)
  }

  // `el.click()` desde el DOM: evita que Playwright desplace la ventana para hacer visible el destino.
  const domClick = (page: Page, selector: string) =>
    page
      .locator(selector)
      .first()
      .evaluate((el) => (el as HTMLElement).click())

  test('ruta nueva, atrás/adelante y cambio solo de query terminan arriba; sessionStorage sin posiciones', async ({
    page,
  }) => {
    await page.goto('/entrar')
    await page.getByLabel(/correo/i).fill(process.env.E2E_ADMIN_EMAIL ?? '')
    await page.getByLabel(/contraseña/i).fill(process.env.E2E_ADMIN_PASSWORD ?? '')
    await page.getByRole('button', { name: /entrar|acceder/i }).click()
    await expect(page).not.toHaveURL(/\/entrar/)

    await page.goto('/administracion/usuarios')
    await expect(page.locator('table')).toBeVisible()

    // (a) Ruta nueva por el menú (navegación del router, sin recarga).
    await scrollDown(page)
    await domClick(page, 'a[href="/administracion/roles"]')
    await expect(page).toHaveURL(/\/administracion\/roles$/)
    await expect(page.locator('table')).toBeVisible()
    await expect.poll(() => scrollY(page)).toBe(0)

    // (b) Atrás/adelante: DIVERGENCIA CONOCIDA con el `scrollBehavior` anterior (siempre arriba). Sin `scrollBehavior` el
    // router no fija `history.scrollRestoration = 'manual'` y el navegador restaura su posición al volver: no se
    // afirma el scroll, solo que la navegación llega a la ruta y no deja posiciones en `sessionStorage` (más abajo).
    // Fijar `manual` lo igualaría, pero vue-router 5 escribiría `vue-router:scroll` (UI-3) y rompe `acad-timetable`.
    await page.goBack()
    await expect(page).toHaveURL(/\/administracion\/usuarios$/)
    await expect(page.locator('table')).toBeVisible()
    await page.goForward()
    await expect(page).toHaveURL(/\/administracion\/roles$/)

    // (c) Cambio solo de query (`urlState` de la tabla: orden), misma ruta.
    await page.goto('/administracion/usuarios')
    await expect(page.locator('table')).toBeVisible()
    await scrollDown(page)
    await domClick(page, 'table thead th button')
    await expect(page).toHaveURL(/[?&]sort=/)
    await expect.poll(() => scrollY(page)).toBe(0)

    // (d) El router no guarda posiciones: `vue-router:scroll` ausente o `{}` y sin otras claves.
    const storage = await page.evaluate(() =>
      Object.fromEntries(
        Object.keys(window.sessionStorage).map((k) => [k, window.sessionStorage.getItem(k)]),
      ),
    )
    expect(Object.keys(storage).filter((k) => k !== 'vue-router:scroll')).toEqual([])
    expect(storage['vue-router:scroll'] ?? '{}').toBe('{}')
  })
})
