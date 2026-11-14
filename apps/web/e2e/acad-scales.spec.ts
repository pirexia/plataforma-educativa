import { expect, test, type Page } from '@playwright/test'

/**
 * `docs/modulos/REQ-ACAD/funcional.md §13.16` (1.11d-b, parte web),
 * `CA-ACAD-101` (parte de escalas de calificación): flujo contra la **API
 * real**, solo con `E2E_REAL_API=1` (patrón de `e2e/acad-periods.spec.ts`): el
 * script `scripts/e2e-real-api.sh` prepara el centro de pruebas sintético y lo
 * retira al terminar. La parte de períodos lectivos de `CA-ACAD-101` está en
 * `e2e/acad-periods.spec.ts`.
 *
 * Recorrido: crear una escala cualitativa de cinco valores (con un código
 * repetido que el servidor rechaza en la fila, `RN-ACAD-83`), reordenarla con
 * «Subir» y guardar; crear una mixta 0-10 con tramos contiguos (`RN-ACAD-85`);
 * y comprobar a 320 px que el formulario de escala no tiene desplazamiento
 * horizontal de página (WCAG 1.4.10).
 */

async function login(page: Page): Promise<void> {
  await page.goto('/entrar')
  await page.getByLabel(/correo/i).fill(process.env.E2E_ADMIN_EMAIL ?? '')
  await page.getByLabel(/contraseña/i).fill(process.env.E2E_ADMIN_PASSWORD ?? '')
  await page.getByRole('button', { name: /entrar|acceder/i }).click()
  await expect(page).not.toHaveURL(/\/entrar/)
}

test.describe('CA-ACAD-101 (REQ-ACAD-001): escalas de calificación contra la API real', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real y un centro de pruebas: ejecútalo con `npm run test:e2e:real` (apps/web/scripts/e2e-real-api.sh), que lo prepara y define E2E_REAL_API=1, E2E_ADMIN_EMAIL y E2E_ADMIN_PASSWORD.',
  )

  test.use({ baseURL: process.env.E2E_BASE_URL ?? 'http://demo.plataforma.test:5173' })

  test('cualitativa de cinco valores reordenada, mixta 0-10 y 320 px sin desbordar', async ({
    page,
  }) => {
    await login(page)

    // 0. Un curso académico activo.
    await page.goto('/administracion/cursos/nuevo')
    await page.getByLabel(/^Código/).fill('2026-2027')
    await page.getByLabel(/^Fecha de inicio/).fill('2026-09-01')
    await page.getByLabel(/^Fecha de fin/).fill('2027-06-30')
    await page.getByRole('button', { name: 'Guardar' }).click()
    await page.getByRole('button', { name: 'Activar curso' }).click()
    await page.getByRole('button', { name: 'Activar el curso 2026-2027' }).click()
    await expect(page.getByRole('status').filter({ hasText: 'activado' })).toBeVisible()

    // 1. Listado vacío y alta de una cualitativa.
    await page.goto('/academico/escalas')
    await expect(page.getByText('Este curso académico no tiene escalas')).toBeVisible()
    await page.getByRole('link', { name: 'Nueva escala' }).first().click()
    await page
      .getByLabel(/^Código/)
      .first()
      .fill('CUAL')
    await page.getByLabel(/^Nombre \(Español\)/).fill('Insuficiente a Sobresaliente')
    await page.getByRole('radio', { name: 'Cualitativa' }).click()

    const values: [string, string, boolean][] = [
      ['IN', 'Insuficiente', false],
      ['SU', 'Suficiente', true],
      ['BI', 'Bien', true],
      ['NT', 'Notable', true],
      ['SU', 'Sobresaliente', true], // código repetido a propósito (RN-ACAD-83)
    ]

    for (const [index, [code, label, passing]] of values.entries()) {
      await page.getByRole('button', { name: 'Añadir valor' }).click()

      const row = page.getByRole('group', { name: `Valor ${index + 1}` })

      await row.getByLabel(/^Código/).fill(code)
      await row.getByLabel(/^Etiqueta \(Español\)/).fill(label)

      if (passing) {
        await row.getByLabel('Es aprobado').check()
      }
    }

    // 2. El servidor rechaza el código repetido en la fila 5, no sobre el formulario entero.
    await page.getByRole('button', { name: 'Crear', exact: true }).click()

    const fifth = page.getByRole('group', { name: 'Valor 5' })

    await expect(fifth.getByLabel(/^Código/)).toHaveAttribute('aria-invalid', 'true')
    await expect(fifth.getByRole('alert')).toBeVisible()

    // 3. Se corrige el código y se reordena con «Subir» (sin arrastrar): el 5.º pasa al 4.º puesto.
    await fifth.getByLabel(/^Código/).fill('SB')
    await fifth.getByRole('button', { name: 'Subir' }).click()
    await expect(page.getByRole('status').filter({ hasText: 'ahora en la posición 4' })).toHaveText(
      /Sobresaliente ahora en la posición 4/,
    )
    await expect(page.getByRole('group', { name: 'Valor 4' }).getByLabel(/^Código/)).toHaveValue(
      'SB',
    )

    await page.getByRole('button', { name: 'Crear', exact: true }).click()
    await expect(page.getByRole('status').filter({ hasText: 'Escala creada' })).toBeVisible()

    const items = page.locator('[data-slot="acad-scale-values"] li')

    await expect(items).toHaveCount(5)
    await expect(items.nth(3)).toContainText('Sobresaliente')
    await expect(items.nth(3)).toContainText('Aprobado')
    await expect(items.nth(0)).toContainText('Suspenso')

    // 4. Una mixta 0-10 con dos tramos contiguos.
    await page.goto('/academico/escalas/nueva')
    await page
      .getByLabel(/^Código/)
      .first()
      .fill('MIX10')
    await page.getByLabel(/^Nombre \(Español\)/).fill('De 0 a 10 con calificación')
    await page.getByRole('radio', { name: 'Mixta' }).click()
    await page.getByLabel(/^Mínimo/).fill('0')
    await page.getByLabel(/^Máximo/).fill('10')
    await page.getByLabel(/^Decimales/).click()
    await page.getByRole('option', { name: '1', exact: true }).click()
    await page.getByLabel(/^Nota mínima de aprobado/).fill('5')

    const bands: [string, string, string, string][] = [
      ['IN', 'Insuficiente', '0', '4.9'],
      ['SU', 'Suficiente', '5', '10'],
    ]

    for (const [index, [code, label, from, to]] of bands.entries()) {
      await page.getByRole('button', { name: 'Añadir tramo' }).click()

      const row = page.getByRole('group', { name: `Valor ${index + 1}` })

      await row.getByLabel(/^Código/).fill(code)
      await row.getByLabel(/^Etiqueta \(Español\)/).fill(label)
      await row.getByLabel(/^Desde/).fill(from)
      await row.getByLabel(/^Hasta/).fill(to)
    }

    await expect(
      page.getByRole('group', { name: 'Valor 2' }).locator('[data-slot="acad-scale-band-passing"]'),
    ).toContainText('Aprobado')
    await page.getByRole('button', { name: 'Crear', exact: true }).click()
    await expect(page.getByRole('status').filter({ hasText: 'Escala creada' })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Tramos' })).toBeVisible()

    // 5. A 320 px el formulario de escala no tiene desplazamiento horizontal de página (WCAG 1.4.10).
    await page.setViewportSize({ width: 320, height: 800 })
    await page.goto('/academico/escalas/nueva')
    await page.getByRole('radio', { name: 'Mixta' }).click()
    await page.getByRole('button', { name: 'Añadir tramo' }).click()
    await expect(page.getByRole('group', { name: 'Valor 1' })).toBeVisible()

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )

    expect(overflow).toBeLessThanOrEqual(0)
  })
})
