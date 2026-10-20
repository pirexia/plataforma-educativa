import { expect, test } from '@playwright/test'

/**
 * `docs/modulos/REQ-ACAD/funcional.md §13.16` (1.11d-a, parte web),
 * `CA-ACAD-101` (parte de períodos lectivos): flujo contra la **API real**, solo
 * con `E2E_REAL_API=1` (patrón de `e2e/acad-structure.spec.ts`): el script
 * `scripts/e2e-real-api.sh` prepara el centro de pruebas sintético y lo retira
 * al terminar. La parte de escalas de `CA-ACAD-101` (`1.11d-b`) está en `e2e/acad-scales.spec.ts`.
 *
 * Recorrido: en una etapa, crear tres trimestres; intentar un cuarto solapado
 * (el `422` junto a la fecha, con el nombre del período con el que solapa);
 * intentar borrar la etapa (el `409` con «Períodos lectivos»); y comprobar a
 * 320 px de ancho que la ficha no tiene desplazamiento horizontal de página.
 */

test.describe('CA-ACAD-101 (REQ-ACAD-001): períodos lectivos contra la API real', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real y un centro de pruebas: ejecútalo con `npm run test:e2e:real` (apps/web/scripts/e2e-real-api.sh), que lo prepara y define E2E_REAL_API=1, E2E_ADMIN_EMAIL y E2E_ADMIN_PASSWORD.',
  )

  test.use({ baseURL: process.env.E2E_BASE_URL ?? 'http://demo.plataforma.test:5173' })

  test('tres trimestres, un cuarto solapado (422), 409 al borrar la etapa y 320 px sin desbordar', async ({
    page,
  }) => {
    const email = process.env.E2E_ADMIN_EMAIL ?? ''
    const password = process.env.E2E_ADMIN_PASSWORD ?? ''

    await page.goto('/entrar')
    await page.getByLabel(/correo/i).fill(email)
    await page.getByLabel(/contraseña/i).fill(password)
    await page.getByRole('button', { name: /entrar|acceder/i }).click()
    await expect(page).not.toHaveURL(/\/entrar/)

    // 0. Un curso académico activo.
    await page.goto('/administracion/cursos/nuevo')
    await page.getByLabel(/^Código/).fill('2026-2027')
    await page.getByLabel(/^Fecha de inicio/).fill('2026-09-01')
    await page.getByLabel(/^Fecha de fin/).fill('2027-06-30')
    await page.getByRole('button', { name: 'Guardar' }).click()
    await page.getByRole('button', { name: 'Activar curso' }).click()
    await page.getByRole('button', { name: 'Activar el curso 2026-2027' }).click()
    await expect(page.getByRole('status').filter({ hasText: 'activado' })).toBeVisible()

    // 1. Una etapa.
    await page.goto('/academico/estructura')
    await page.getByRole('button', { name: 'Nueva etapa' }).first().click()
    const levelDialog = page.getByRole('dialog')
    await levelDialog.getByLabel(/^Código/).fill('ESO')
    await levelDialog.getByLabel(/^Nombre \(Español\)/).fill('Educación Secundaria Obligatoria')
    await levelDialog.getByLabel(/^Tipo de etapa/).click()
    await page.getByRole('option', { name: 'Educación Secundaria Obligatoria', exact: true }).click()
    await levelDialog.getByLabel(/^Régimen/).click()
    await page.getByRole('option', { name: 'Concertado', exact: true }).click()
    await levelDialog.getByRole('button', { name: 'Crear' }).click()
    await expect(page.getByRole('status').filter({ hasText: 'Etapa creada' })).toBeVisible()
    await page.getByRole('link', { name: 'ESO' }).click()

    const section = page.locator('[data-slot="acad-periods"]')
    await expect(section.getByRole('heading', { name: 'Períodos lectivos' })).toBeVisible()
    await expect(section).toContainText('Esta etapa no tiene períodos lectivos')

    // 2. Tres trimestres.
    const trimesters: [string, string, string][] = [
      ['Primer trimestre', '2026-09-08', '2026-12-22'],
      ['Segundo trimestre', '2027-01-08', '2027-03-26'],
      ['Tercer trimestre', '2027-04-06', '2027-06-20'],
    ]

    for (const [name, start, end] of trimesters) {
      await page.getByRole('button', { name: 'Nuevo período' }).click()
      const dialog = page.getByRole('dialog')
      await dialog.getByLabel(/^Tipo de período/).click()
      await page.getByRole('option', { name: 'Trimestre', exact: true }).click()
      await dialog.getByLabel(/^Nombre \(Español\)/).fill(name)
      await dialog.getByLabel(/^Inicio/).fill(start)
      await dialog.getByLabel(/^Fin/).fill(end)
      await dialog.getByRole('button', { name: 'Crear' }).click()
      await expect(page.getByRole('status').filter({ hasText: 'Período creado' })).toBeVisible()
      await expect(section).toContainText(name)
    }

    // 3. Un cuarto solapado con el tercero: el 422 junto a la fecha, nombrando el período.
    await page.getByRole('button', { name: 'Nuevo período' }).click()
    const overlapping = page.getByRole('dialog')
    await overlapping.getByLabel(/^Tipo de período/).click()
    await page.getByRole('option', { name: 'Trimestre', exact: true }).click()
    await overlapping.getByLabel(/^Nombre \(Español\)/).fill('Cuarto trimestre')
    await overlapping.getByLabel(/^Inicio/).fill('2027-06-10')
    await overlapping.getByLabel(/^Fin/).fill('2027-06-25')
    await overlapping.getByRole('button', { name: 'Crear' }).click()
    await expect(
      overlapping.getByRole('alert').filter({ hasText: 'Tercer trimestre' }),
    ).toBeVisible()
    await expect(overlapping.getByLabel(/^Inicio/)).toHaveAttribute('aria-invalid', 'true')
    await overlapping.getByRole('button', { name: 'Cancelar' }).click()

    // 4. Borrar la etapa con períodos: 409 junto a la acción, con «Períodos lectivos».
    await page.getByRole('button', { name: 'Borrar', exact: true }).click()
    await page.getByRole('button', { name: 'Borrar ESO' }).click()
    await expect(
      page.getByRole('alert').filter({ hasText: 'Elementos que lo impiden' }),
    ).toContainText('Períodos lectivos')

    // 5. A 320 px la ficha no tiene desplazamiento horizontal de página (WCAG 1.4.10).
    await page.setViewportSize({ width: 320, height: 800 })
    await expect(section.getByRole('heading', { name: 'Períodos lectivos' })).toBeVisible()
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(overflow).toBeLessThanOrEqual(0)

    await page.getByRole('button', { name: 'Nuevo período' }).click()
    const narrow = page.getByRole('dialog')
    await expect(narrow).toBeVisible()
    const dialogOverflow = await narrow.evaluate((el) => el.scrollWidth - el.clientWidth)
    expect(dialogOverflow).toBeLessThanOrEqual(0)
  })
})
