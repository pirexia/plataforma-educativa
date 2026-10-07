import { expect, test } from '@playwright/test'

/**
 * `docs/modulos/REQ-CURSO/funcional.md §13.5` (1.10), `CA-CURSO-086`: flujo
 * completo **contra la API real**, solo con `E2E_REAL_API=1` (patrón de
 * `CA-PERM-133`, `e2e/core-roles.spec.ts`): `npm run test:e2e:real` prepara el
 * centro de pruebas sintético (`apps/api/tests/Support/e2e-real-tenant.php`) y
 * lo retira al terminar.
 *
 * Recorrido: crear un curso, activarlo, crear el siguiente, intentar activarlo
 * (ver el `409` con el enlace al curso activo), cerrar el primero y activar el
 * segundo.
 */

test.describe('CA-CURSO-086 (REQ-CURSO-001): ciclo de vida del curso contra la API real', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real y un centro de pruebas: ejecútalo con `npm run test:e2e:real` (apps/web/scripts/e2e-real-api.sh), que lo prepara y define E2E_REAL_API=1, E2E_ADMIN_EMAIL y E2E_ADMIN_PASSWORD.',
  )

  // El centro «demo» es el de VITE_API_URL en desarrollo: SPA y API comparten
  // el sitio demo.plataforma.test (cookie de sesión del mismo sitio).
  test.use({ baseURL: process.env.E2E_BASE_URL ?? 'http://demo.plataforma.test:5173' })

  test('crear, activar, crear el siguiente, 409 al activarlo, cerrar el primero y activar el segundo', async ({
    page,
  }) => {
    const email = process.env.E2E_ADMIN_EMAIL ?? ''
    const password = process.env.E2E_ADMIN_PASSWORD ?? ''

    await page.goto('/entrar')
    await page.getByLabel(/correo/i).fill(email)
    await page.getByLabel(/contraseña/i).fill(password)
    await page.getByRole('button', { name: /entrar|acceder/i }).click()
    await expect(page).not.toHaveURL(/\/entrar/)

    // 1. Alta del primer curso: nace en planificación.
    await page.goto('/administracion/cursos/nuevo')
    await page.getByLabel(/^Código/).fill('2026-2027')
    await page.getByLabel(/^Fecha de inicio/).fill('2026-09-01')
    await page.getByLabel(/^Fecha de fin/).fill('2027-06-30')
    await page.getByRole('button', { name: 'Guardar' }).click()
    await expect(page).toHaveURL(/\/administracion\/cursos\/[^/]+$/)
    await expect(page.getByRole('status')).toContainText('creado en planificación')
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('2026-2027')
    const firstUrl = page.url()

    // 2. Activarlo (con confirmación).
    await page.getByRole('button', { name: 'Activar curso' }).click()
    await page.getByRole('button', { name: 'Activar el curso 2026-2027' }).click()
    await expect(page.getByRole('status')).toContainText('activado')
    await expect(page.getByRole('button', { name: 'Cerrar curso' })).toBeVisible()

    // 3. Alta del siguiente curso (contiguo, sin solape).
    await page.goto('/administracion/cursos/nuevo')
    await page.getByLabel(/^Código/).fill('2027-2028')
    await page.getByLabel(/^Fecha de inicio/).fill('2027-07-01')
    await page.getByLabel(/^Fecha de fin/).fill('2028-06-30')
    await page.getByRole('button', { name: 'Guardar' }).click()
    await expect(page).toHaveURL(/\/administracion\/cursos\/[^/]+$/)
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('2027-2028')
    const secondUrl = page.url()

    // 4. Intentar activarlo con el primero aún activo: 409 con enlace al activo.
    await page.getByRole('button', { name: 'Activar curso' }).click()
    await page.getByRole('button', { name: 'Activar el curso 2027-2028' }).click()
    await expect(page.getByRole('alert')).toContainText('Ya hay un curso activo')
    await expect(
      page.getByRole('link', { name: /Abrir el curso activo \(2026-2027\)/ }),
    ).toHaveAttribute('href', new URL(firstUrl).pathname)
    // Nada cambia en pantalla hasta recargar.
    await expect(page.getByRole('button', { name: 'Activar curso' })).toBeVisible()

    // 5. Cerrar el primero: advierte de que no se puede deshacer.
    await page.goto(firstUrl)
    await page.getByRole('button', { name: 'Cerrar curso' }).click()
    await expect(page.getByRole('alertdialog')).toContainText('no se puede deshacer')
    await page.getByRole('button', { name: 'Cerrar el curso 2026-2027' }).click()
    await expect(page.getByRole('status')).toContainText('cerrado')
    await expect(page.getByRole('note')).toContainText('solo lectura')
    await expect(page.getByRole('button', { name: 'Cerrar curso' })).toHaveCount(0)

    // 6. Activar el segundo.
    await page.goto(secondUrl)
    await page.getByRole('button', { name: 'Activar curso' }).click()
    await page.getByRole('button', { name: 'Activar el curso 2027-2028' }).click()
    await expect(page.getByRole('status')).toContainText('activado')
    await expect(page.getByRole('button', { name: 'Cerrar curso' })).toBeVisible()

    // El listado refleja los dos estados.
    await page.goto('/administracion/cursos')
    await expect(page.getByRole('row', { name: /2026-2027/ })).toContainText('Cerrado')
    await expect(page.getByRole('row', { name: /2027-2028/ })).toContainText('Activo')
  })
})
