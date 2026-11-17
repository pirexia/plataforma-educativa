import { test, expect, type Page } from '@playwright/test'
import { RealSession } from './fixtures/realSession'
import {
  BASE_URL,
  browserState,
  centreA,
  closeAll,
  createStudent,
  pageAs,
  tag,
} from './fixtures/alumReal'

/**
 * `RNF-MANT-007` (mantenimiento de dependencias: `@tanstack/vue-table` 9, PR #727; no hay un criterio de aceptación propio de
 * este E2E) y `CA-CORE-178`/`RN-CORE-54` de `REQ-CORE/funcional.md §13` (estado de la tabla en la URL: `q` y `cursor` nunca).
 * La tabla es headless: orden, filtro y paginación son del SERVIDOR, así que cada comprobación compara las filas de la
 * pantalla con lo que devuelve la API real para los mismos parámetros, no con valores fijos.
 *
 * Vista: alumnado (`StudentsView`, `table-id` `alum.students`, modo `page`, orden por «Nombre», búsqueda, menú «Columnas»). Se
 * crean 30 alumnos ficticios (`REQ-SEED-005`) para tener al menos 2 páginas con 25 por página.
 *
 * Solo con `E2E_REAL_API=1`: `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).
 */
test.describe('RNF-MANT-007 CA-CORE-178 RN-CORE-54: tabla de alumnado (TanStack Table 9) contra la API real', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real y un centro de pruebas: ejecútalo con `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).',
  )

  test.use({ baseURL: BASE_URL, viewport: { width: 1280, height: 900 } })

  // Letras en vez de dígitos: los nombres ficticios no llevan números.
  const label = [...tag()].map((digit) => String.fromCharCode(97 + Number(digit))).join('')
  const family = `Tabla${label}`
  let api: RealSession
  let page: Page

  test.beforeAll(async ({ browser }) => {
    test.setTimeout(240_000)
    api = await RealSession.login(centreA.slug, centreA.secretariaEmail, centreA.staffPassword)

    for (let i = 0; i < 30; i++) {
      const suffix =
        String.fromCharCode(97 + Math.floor(i / 26)) + String.fromCharCode(97 + (i % 26))

      await createStudent(api, 'Alumno', `${family}${suffix}`)
    }

    page = await pageAs(browser, await browserState(api))
  })

  test.afterAll(async () => {
    await closeAll()
    await api?.dispose()
  })

  /** `display_name` de la API real con el mismo orden y página que pide la pantalla. */
  async function apiNames(sort: string | null, pageNumber: number): Promise<string[]> {
    const query = `per_page=25&page=${pageNumber}${sort ? `&sort=${sort}` : ''}`
    const list = await api.json('GET', `students?${query}`, 200)

    return (list.data as Array<{ display_name: string }>).map((student) => student.display_name)
  }

  const rowNames = async (): Promise<string[]> =>
    (await page.locator('tbody tr th').allInnerTexts()).map((text) => text.trim())

  /** Espera a que las filas de la pantalla sean exactamente las que devuelve la API. */
  async function expectRowsToBe(expected: string[]): Promise<void> {
    expect(expected.length).toBeGreaterThan(0)
    await expect.poll(rowNames, { timeout: 15_000 }).toEqual(expected)
  }

  const sortButton = (direction: 'ascendente' | 'descendente') =>
    page.getByRole('button', { name: new RegExp(`^Nombre, ordenar de forma ${direction}$`) })
  const nameHeader = () => page.getByRole('columnheader', { name: /^Nombre/ })
  const searchBox = () => page.getByRole('searchbox', { name: 'Buscar' })

  test('(a) la cabecera ordenable ordena en el servidor: asc y desc, con aria-sort y la URL', async () => {
    await page.goto('/alumnado')
    await expect(page.locator('tbody tr').first()).toBeVisible()
    await expect(nameHeader()).not.toHaveAttribute('aria-sort', /.+/)

    await sortButton('ascendente').click()
    await expect(nameHeader()).toHaveAttribute('aria-sort', 'ascending')
    await expect(page).toHaveURL(/[?&]sort=name(&|$)/)
    const asc = await apiNames('name', 1)

    await expectRowsToBe(asc)

    await sortButton('descendente').click()
    await expect(nameHeader()).toHaveAttribute('aria-sort', 'descending')
    await expect(page).toHaveURL(/[?&]sort=-name(&|$)/)
    const desc = await apiNames('-name', 1)

    // Sin esto la comprobación sería vacua: los dos órdenes deben diferir.
    expect(desc).not.toEqual(asc)
    await expectRowsToBe(desc)
  })

  test('(c) «Página siguiente» cambia las filas y la URL trae page=2', async () => {
    await page.goto('/alumnado?sort=name')
    await expectRowsToBe(await apiNames('name', 1))
    const first = await rowNames()

    await page.getByRole('button', { name: 'Página siguiente' }).click()
    await expect(page).toHaveURL(/[?&]page=2(&|$)/)
    await expect(page.getByText(/^Página 2 de /)).toBeVisible()

    const second = await apiNames('name', 2)

    await expectRowsToBe(second)
    expect(second).not.toEqual(first)
  })

  test('(d) recargar con page y sort los conserva', async () => {
    await page.goto('/alumnado?sort=-name&page=2')
    await expect(nameHeader()).toHaveAttribute('aria-sort', 'descending')
    await expect(page.getByText(/^Página 2 de /)).toBeVisible()
    await expectRowsToBe(await apiNames('-name', 2))

    await page.reload()
    await expect(page).toHaveURL(/[?&]sort=-name(&|$)/)
    await expect(page).toHaveURL(/[?&]page=2(&|$)/)
    await expect(nameHeader()).toHaveAttribute('aria-sort', 'descending')
    await expect(page.getByText(/^Página 2 de /)).toBeVisible()
    await expectRowsToBe(await apiNames('-name', 2))
  })

  test('(b) un filtro reduce las filas, limpiarlo devuelve las originales y q no va a la URL', async () => {
    await page.goto('/alumnado')
    const original = await apiNames(null, 1)

    await expectRowsToBe(original)

    await searchBox().fill(`${family}aa`)
    await expect.poll(async () => (await rowNames()).length).toBe(1)
    expect((await rowNames())[0]).toContain(`${family}aa`)
    // RN-CORE-54: el texto de búsqueda nunca va en la URL.
    expect(page.url()).not.toMatch(/[?&]q=/)

    await searchBox().fill('')
    await expectRowsToBe(original)
  })

  test('(f) un filtro sin resultados muestra el estado vacío', async () => {
    await page.goto('/alumnado')
    await expect(page.locator('tbody tr').first()).toBeVisible()

    await searchBox().fill(`Zzqx${label}`)
    await expect(page.getByText('Ningún resultado con estos filtros.')).toBeVisible()
    await expect(page.locator('tbody tr')).toHaveCount(0)
    expect(page.url()).not.toMatch(/[?&]q=/)
  })

  test('(e) ocultar una columna desde «Columnas» la quita y no escribe q ni cursor en la URL', async () => {
    await page.goto('/alumnado')
    const birth = page.getByRole('columnheader', { name: 'Fecha de nacimiento' })

    await expect(birth).toBeVisible()

    await page.getByRole('button', { name: 'Columnas' }).click()
    await page.getByRole('menuitemcheckbox', { name: 'Fecha de nacimiento' }).click()
    await page.keyboard.press('Escape')

    // La tabla se repinta al ocultar: se espera a que las demás cabeceras estén de vuelta antes de afirmar la ausencia, para que
    // un instante sin cabeceras no dé por buena una columna que sigue ahí.
    await expect(nameHeader()).toBeVisible()
    await expect(page.getByRole('columnheader', { name: 'Matrícula' })).toBeVisible()
    await expect(page.locator('tbody tr').first()).toBeVisible()
    await expect(birth).toHaveCount(0)
    expect(page.url()).not.toMatch(/[?&](q|cursor)=/)

    // Restablece las columnas (preferencia en localStorage) para no dejar rastro.
    await page.getByRole('button', { name: 'Columnas' }).click()
    await page.getByRole('menuitem', { name: 'Restablecer columnas' }).click()
    await expect(birth).toBeVisible()
  })
})
