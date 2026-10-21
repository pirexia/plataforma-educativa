import { execSync } from 'node:child_process'
import { expect, test, type Page } from '@playwright/test'

/**
 * `docs/modulos/REQ-CURSO/funcional.md §13.8` (1.12b, `REQ-CURSO-002`), `CA-CURSO-147`: apertura del curso siguiente contra
 * la **API real**, solo con `E2E_REAL_API=1` (patrón de `CA-CURSO-086`, `e2e/curso-academic-years.spec.ts`):
 * `npm run test:e2e:real` prepara el centro de pruebas sintético (con `acad` contratado) y lo retira al terminar. **Ejecutarlo
 * recrea el centro `demo`** con otra contraseña: exige permiso expreso.
 *
 * Recorrido: crear una etapa en el curso activo, abrir el curso siguiente con el asistente («Abrir curso siguiente»: alta del
 * curso y asistente de cuatro pasos), ver la simulación, confirmar, **ejecutar la cola** (`queue:work database_long --queue=long
 * --stop-when-empty` en el contenedor de la API, o el comando de `E2E_QUEUE_COMMAND`: en desarrollo el servicio `worker-long`
 * no arranca solo) y ver el resultado; revertir y ver la copia revertida.
 */
const QUEUE_COMMAND =
  process.env.E2E_QUEUE_COMMAND ??
  'podman exec plataforma-api php artisan queue:work database_long --queue=long --stop-when-empty --tries=1'

async function login(page: Page): Promise<void> {
  await page.goto('/entrar')
  await page.getByLabel(/correo/i).fill(process.env.E2E_ADMIN_EMAIL ?? '')
  await page.getByLabel(/contraseña/i).fill(process.env.E2E_ADMIN_PASSWORD ?? '')
  await page.getByRole('button', { name: /entrar|acceder/i }).click()
  await expect(page).not.toHaveURL(/\/entrar/)
}

function runQueue(): void {
  execSync(QUEUE_COMMAND, { stdio: 'inherit', timeout: 120_000 })
}

test.describe('CA-CURSO-147 (REQ-CURSO-002): apertura del curso siguiente contra la API real', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real y un centro de pruebas: ejecútalo con `npm run test:e2e:real` (apps/web/scripts/e2e-real-api.sh), que lo prepara y define E2E_REAL_API=1, E2E_ADMIN_EMAIL y E2E_ADMIN_PASSWORD.',
  )

  test.use({ baseURL: process.env.E2E_BASE_URL ?? 'http://demo.plataforma.test:5173' })

  test('copiar la estructura al curso siguiente con el asistente, ejecutar la cola y revertir', async ({
    page,
  }) => {
    await login(page)

    // 0. Un curso académico activo con una etapa.
    await page.goto('/administracion/cursos/nuevo')
    await page.getByLabel(/^Código/).fill('2026-2027')
    await page.getByLabel(/^Fecha de inicio/).fill('2026-09-01')
    await page.getByLabel(/^Fecha de fin/).fill('2027-08-31')
    await page.getByRole('button', { name: 'Guardar' }).click()
    await page.getByRole('button', { name: 'Activar curso' }).click()
    await page.getByRole('button', { name: 'Activar el curso 2026-2027' }).click()
    await expect(page.getByRole('status').filter({ hasText: 'activado' })).toBeVisible()

    await page.goto('/academico/estructura')
    await page.getByRole('button', { name: 'Nueva etapa' }).first().click()

    const dialog = page.getByRole('dialog')

    await dialog.getByLabel(/^Código/).fill('ESO')
    await dialog.getByLabel(/^Nombre \(Español\)/).fill('Educación Secundaria Obligatoria')
    await dialog.getByLabel(/^Tipo de etapa/).click()
    await page
      .getByRole('option', { name: 'Educación Secundaria Obligatoria', exact: true })
      .click()
    await dialog.getByLabel(/^Régimen/).click()
    await page.getByRole('option', { name: 'Concertado', exact: true }).click()
    await dialog.getByRole('button', { name: 'Crear' }).click()
    await expect(page.getByRole('status').filter({ hasText: 'Etapa creada' })).toBeVisible()

    // 1. «Abrir curso siguiente»: alta del curso y, al crearlo, el asistente.
    await page.goto('/administracion/cursos')
    await page.getByRole('link', { name: 'Abrir curso siguiente' }).click()
    await page.getByLabel(/^Código/).fill('2027-2028')
    await page.getByLabel(/^Fecha de inicio/).fill('2027-09-01')
    await page.getByLabel(/^Fecha de fin/).fill('2028-08-31')
    await page.getByRole('button', { name: 'Guardar' }).click()
    await expect(page).toHaveURL(/\/copiar-estructura$/)

    // 2. Origen (el activo, por defecto) y elementos: «Etapas». La casilla de asignación docente sale desmarcada.
    await expect(page.getByRole('heading', { name: 'Origen' })).toBeFocused()
    await page.getByRole('button', { name: 'Siguiente' }).click()
    await expect(page.getByLabel('Copiar la asignación docente')).not.toBeChecked()
    await page.getByLabel('Etapas', { exact: true }).check()
    await page.getByRole('button', { name: 'Siguiente' }).click()

    // 3. Simulación: se copiará una etapa y el desplazamiento de fechas es de 365 días.
    await expect(page.getByText('Las fechas se desplazan 365 días.')).toBeVisible()
    await page.getByRole('button', { name: 'Confirmar' }).click()
    await page.getByRole('button', { name: 'Copiar a 2027-2028' }).click()

    // 4. El detalle: «En cola» hasta que se ejecuta la cola; después, «Completada» con su resultado.
    await expect(page.getByTestId('rollover-status')).toContainText('En cola')
    runQueue()
    await expect(page.getByTestId('rollover-status')).toContainText('Completada', {
      timeout: 60_000,
    })
    await expect(page.getByRole('cell', { name: 'acad.education_levels' })).toBeVisible()

    // 5. Revertir: se pide confirmación y la copia queda revertida.
    await page.getByRole('button', { name: 'Revertir copia' }).click()
    await page.getByRole('button', { name: 'Revertir la copia en 2027-2028' }).click()
    await expect(page.getByTestId('rollover-status')).toContainText('Revirtiendo')
    runQueue()
    await expect(page.getByTestId('rollover-status')).toContainText('Revertida', {
      timeout: 60_000,
    })
  })
})
