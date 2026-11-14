import { test, expect, type Page } from '@playwright/test'
import type { FixtureOptions } from './fixtures/tableFixture'

/**
 * `docs/modulos/REQ-CORE/funcional.md §13.18`: criterios marcados
 * **[Playwright]** de la tabla de datos de 1.9 (`CA-CORE-177`, `-178`,
 * `-185`, `-203`). Necesitan *layout* real, *media queries*, medida de
 * cajas y foco de verdad (jsdom no los da). Sin backend real: se
 * intercepta la API con `page.route` y la «tabla de prueba» se monta desde
 * `e2e/fixtures/tableFixture.ts`, que el servidor de desarrollo de Vite
 * sirve a la página (mismo patrón que `e2e/shell.spec.ts`).
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

async function openFixture(page: Page, options: FixtureOptions = {}): Promise<void> {
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
  await page.goto('/entrar')
  await page.evaluate(
    `import('/e2e/fixtures/tableFixture.ts').then((m) => m.mountFixture(${JSON.stringify(options)}))`,
  )
  await expect(page.locator('#fixture-root [data-slot="data-table"]')).toBeVisible()
  await expect(
    page
      .locator('#fixture-root')
      .getByText('Persona p1-0')
      .or(page.locator('#fixture-root').getByText('Persona c1-0'))
      .first(),
  ).toBeVisible()
}

async function documentFitsWidth(page: Page): Promise<boolean> {
  return page.evaluate(
    () => document.documentElement.scrollWidth <= document.documentElement.clientWidth,
  )
}

test.describe('CA-CORE-177 (RUX-RESP-004, RUX-RESP-001): tarjetas por debajo de 768 px', () => {
  test('a 320 px no hay tabla y sí una tarjeta por fila; a 768 px, tabla y no lista; sin desplazamiento horizontal', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 320, height: 900 })
    await openFixture(page)

    await expect(page.locator('#fixture-root table')).toHaveCount(0)
    await expect(page.locator('#fixture-root ul[data-slot="data-table-cards"] > li')).toHaveCount(5)
    expect(await documentFitsWidth(page)).toBe(true)

    await page.setViewportSize({ width: 768, height: 900 })
    await expect(page.locator('#fixture-root table')).toBeVisible()
    await expect(page.locator('#fixture-root ul[data-slot="data-table-cards"]')).toHaveCount(0)
    expect(await documentFitsWidth(page)).toBe(true)

    await page.setViewportSize({ width: 320, height: 900 })
    await expect(page.locator('#fixture-root table')).toHaveCount(0)
    await expect(page.locator('#fixture-root ul[data-slot="data-table-cards"]')).toBeVisible()
  })
})

test.describe('CA-CORE-178 (RUX-RESP-007): objetivos táctiles de 44 × 44 px', () => {
  test.use({ hasTouch: true, isMobile: true })

  async function expectTouchTarget(page: Page, locator: ReturnType<Page['locator']>, name: string) {
    const box = await locator.first().boundingBox()

    expect(box, name).not.toBeNull()
    expect(box!.width, `${name}: ancho`).toBeGreaterThanOrEqual(43.5)
    expect(box!.height, `${name}: alto`).toBeGreaterThanOrEqual(43.5)
  }

  test('ordenación de tarjetas, paginador, menú de columnas y acción de tarjeta', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 320, height: 900 })
    await openFixture(page)
    const root = page.locator('#fixture-root')

    await expectTouchTarget(
      page,
      root.getByRole('button', { name: 'Ordenar', exact: true }),
      'ordenación',
    )
    await expectTouchTarget(
      page,
      root.getByRole('button', { name: 'Columnas', exact: true }),
      'columnas',
    )
    for (const name of ['Primera página', 'Página anterior', 'Página siguiente', 'Última página']) {
      await expectTouchTarget(page, root.getByRole('button', { name }), name)
    }
    await expectTouchTarget(
      page,
      root.getByRole('button', { name: '25 por página' }),
      'filas por página',
    )
    await expectTouchTarget(page, root.locator('.fixture-action'), 'acción de tarjeta')
  })

  test('«Cargar más» en el modo cursor', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 900 })
    await openFixture(page, { mode: 'cursor' })

    await expectTouchTarget(
      page,
      page.locator('#fixture-root').getByRole('button', { name: 'Cargar más' }),
      'cargar más',
    )
  })
})

test.describe('CA-CORE-185 (RUX-004): recorrido solo con teclado', () => {
  test('orden de documento, menús que atrapan y devuelven el foco, y el foco no acaba en body', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 1280, height: 900 })
    await openFixture(page, { canExport: true })
    const root = page.locator('#fixture-root')

    const describeFocus = () =>
      page.evaluate(() => {
        const el = document.activeElement as HTMLElement | null

        if (!el || el === document.body) {
          return 'BODY'
        }

        return el.getAttribute('aria-label') ?? el.textContent?.trim() ?? el.tagName
      })

    await root.locator('input[type="search"]').focus()

    const visited: string[] = ['search']

    for (let step = 0; step < 40; step += 1) {
      await page.keyboard.press('Tab')
      const name = await describeFocus()
      visited.push(name)

      if (name === 'Última página') {
        break
      }
    }

    const indexOf = (predicate: (name: string) => boolean) => visited.findIndex(predicate)
    const order = [
      indexOf((name) => name === 'search'),
      indexOf((name) => name.startsWith('Estado')),
      indexOf((name) => name === 'Columnas'),
      indexOf((name) => name === 'Exportar'),
      indexOf((name) => name.includes('ordenar de forma ascendente')),
      indexOf((name) => name.startsWith('Acción de')),
      indexOf((name) => name === '25 por página'),
      indexOf((name) => name === 'Última página'),
    ]

    expect(
      order.every((index) => index >= 0),
      `recorrido: ${visited.join(' | ')}`,
    ).toBe(true)
    expect(
      [...order].sort((a, b) => a - b),
      `orden: ${visited.join(' | ')}`,
    ).toEqual(order)
    expect(visited).not.toContain('BODY')

    // Menú de columnas: Enter abre, el foco queda atrapado dentro, Esc cierra y devuelve el foco.
    const columns = root.getByRole('button', { name: 'Columnas', exact: true })
    await columns.focus()
    await page.keyboard.press('Enter')
    await expect(page.getByRole('menu')).toBeVisible()
    await page.keyboard.press('Tab')
    expect(
      await page.evaluate(() => document.activeElement?.closest('[role="menu"]') !== null),
    ).toBe(true)
    await page.keyboard.press('Escape')
    await expect(page.getByRole('menu')).toHaveCount(0)
    await expect(columns).toBeFocused()

    // Activar «siguiente» en la penúltima página: el foco no acaba en body.
    const next = root.getByRole('button', { name: 'Página siguiente' })
    await next.focus()
    await page.keyboard.press('Enter') // página 2
    await expect(root.getByText('Página 2 de 3')).toBeVisible()
    await page.keyboard.press('Enter') // página 3 (última): «siguiente» queda deshabilitado
    await expect(root.getByText('Página 3 de 3')).toBeVisible()
    await expect(next).toBeDisabled()

    const focusedInPaginator = await page.evaluate(
      () =>
        document.activeElement !== document.body &&
        document.activeElement?.closest('[data-slot="data-table-pagination"]') !== null,
    )
    expect(focusedInPaginator).toBe(true)
  })
})

test.describe('CA-CORE-203 (RN-CORE-55, RUX-RESP-004, WCAG 1.4.10/2.1.1): desplazamiento interno', () => {
  test('a 320 px una tabla declarada con desplazamiento interno sigue siendo tabla, enfocable y desplazable con teclado', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 320, height: 900 })
    await openFixture(page, { mobile: 'scroll', extraColumns: 8 })
    const root = page.locator('#fixture-root')

    await expect(root.locator('table')).toBeVisible()
    await expect(root.locator('ul[data-slot="data-table-cards"]')).toHaveCount(0)
    expect(await documentFitsWidth(page)).toBe(true)

    const region = root.locator('[role="region"]')
    await expect(region).toHaveAttribute('tabindex', '0')
    await expect(region).toHaveAttribute('aria-label', /Personas de prueba/)

    // Enfocable con Tab (se recorre el resto de controles hasta llegar a la región).
    await root.locator('input[type="search"]').focus()
    let focusedRegion = false

    for (let step = 0; step < 30 && !focusedRegion; step += 1) {
      await page.keyboard.press('Tab')
      focusedRegion = await region.evaluate((el) => el === document.activeElement)
    }

    expect(focusedRegion).toBe(true)

    const before = await region.evaluate((el) => el.scrollLeft)
    await page.keyboard.press('ArrowRight')
    await expect.poll(async () => region.evaluate((el) => el.scrollLeft)).toBeGreaterThan(before)
  })
})
