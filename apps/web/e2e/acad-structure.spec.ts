import { expect, test } from '@playwright/test'

/**
 * `docs/modulos/REQ-ACAD/funcional.md §13.9` (1.11-b), `CA-ACAD-053`: flujo
 * completo **contra la API real**, solo con `E2E_REAL_API=1` (patrón de
 * `CA-CURSO-086`, `e2e/curso-academic-years.spec.ts`): `npm run test:e2e:real`
 * prepara el centro de pruebas sintético y lo retira al terminar.
 *
 * Recorrido: crear una etapa con nombre en dos idiomas (si el centro los tiene
 * activos), un curso de etapa, un grupo, un subgrupo y dos asignaturas; asignar
 * una al grupo; intentar borrar el curso de etapa (ver el `409`); cambiar de
 * curso académico con el selector.
 */

test.describe('CA-ACAD-053 (REQ-ACAD-001): estructura académica contra la API real', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real y un centro de pruebas: ejecútalo con `npm run test:e2e:real` (apps/web/scripts/e2e-real-api.sh), que lo prepara y define E2E_REAL_API=1, E2E_ADMIN_EMAIL y E2E_ADMIN_PASSWORD.',
  )

  test.use({ baseURL: process.env.E2E_BASE_URL ?? 'http://demo.plataforma.test:5173' })

  test('crear la jerarquía, asignar una asignatura, 409 al borrar el curso y cambiar de curso académico', async ({
    page,
  }) => {
    const email = process.env.E2E_ADMIN_EMAIL ?? ''
    const password = process.env.E2E_ADMIN_PASSWORD ?? ''

    await page.goto('/entrar')
    await page.getByLabel(/correo/i).fill(email)
    await page.getByLabel(/contraseña/i).fill(password)
    await page.getByRole('button', { name: /entrar|acceder/i }).click()
    await expect(page).not.toHaveURL(/\/entrar/)

    // 0. Dos cursos académicos: el primero activo y el segundo en planificación.
    await page.goto('/administracion/cursos/nuevo')
    await page.getByLabel(/^Código/).fill('2026-2027')
    await page.getByLabel(/^Fecha de inicio/).fill('2026-09-01')
    await page.getByLabel(/^Fecha de fin/).fill('2027-06-30')
    await page.getByRole('button', { name: 'Guardar' }).click()
    await page.getByRole('button', { name: 'Activar curso' }).click()
    await page.getByRole('button', { name: 'Activar el curso 2026-2027' }).click()
    await expect(page.getByRole('status').filter({ hasText: 'activado' })).toBeVisible()

    await page.goto('/administracion/cursos/nuevo')
    await page.getByLabel(/^Código/).fill('2027-2028')
    await page.getByLabel(/^Fecha de inicio/).fill('2027-07-01')
    await page.getByLabel(/^Fecha de fin/).fill('2028-06-30')
    await page.getByRole('button', { name: 'Guardar' }).click()
    await expect(page).toHaveURL(/\/administracion\/cursos\/[^/]+$/)

    // 1. Etapa con nombre en dos idiomas (el segundo, si el centro lo tiene activo).
    await page.goto('/academico/estructura')
    await page.getByRole('button', { name: 'Nueva etapa' }).first().click()
    const dialog = page.getByRole('dialog')
    await dialog.getByLabel(/^Código/).fill('ESO')
    await dialog.getByLabel(/^Nombre \(Español\)/).fill('Educación Secundaria Obligatoria')
    const english = dialog.getByLabel(/^Nombre \(Inglés\)/)
    if ((await english.count()) > 0) {
      await english.fill('Compulsory Secondary Education')
    }
    await dialog.getByLabel(/^Tipo de etapa/).click()
    await page.getByRole('option', { name: 'Educación Secundaria Obligatoria', exact: true }).click()
    await dialog.getByLabel(/^Régimen/).click()
    await page.getByRole('option', { name: 'Concertado', exact: true }).click()
    await dialog.getByRole('button', { name: 'Crear' }).click()
    await expect(page.getByRole('status').filter({ hasText: 'Etapa creada' })).toBeVisible()
    await page.getByRole('link', { name: 'ESO' }).click()

    // 2. Curso de etapa.
    await page.getByRole('button', { name: 'Nuevo curso' }).click()
    await page
      .getByRole('dialog')
      .getByLabel(/^Código/)
      .fill('2ESO')
    await page
      .getByRole('dialog')
      .getByLabel(/^Nombre \(Español\)/)
      .fill('2.º ESO')
    await page.getByRole('dialog').getByRole('button', { name: 'Crear' }).click()
    await expect(page.getByRole('status').filter({ hasText: 'Curso creado' })).toBeVisible()
    await page.getByRole('link', { name: '2ESO' }).click()

    // 3. Grupo y dos asignaturas.
    await page.getByRole('button', { name: 'Nuevo grupo' }).click()
    await page
      .getByRole('dialog')
      .getByLabel(/^Código/)
      .fill('A')
    await page
      .getByRole('dialog')
      .getByLabel(/^Nombre/)
      .fill('2.º ESO A')
    await page.getByRole('dialog').getByRole('button', { name: 'Crear' }).click()
    await expect(page.getByRole('status').filter({ hasText: 'Grupo creado' })).toBeVisible()

    for (const [code, name] of [
      ['MAT', 'Matemáticas'],
      ['LEN', 'Lengua'],
    ]) {
      await page.getByRole('button', { name: 'Nueva asignatura' }).click()
      await page
        .getByRole('dialog')
        .getByLabel(/^Código/)
        .fill(code!)
      await page
        .getByRole('dialog')
        .getByLabel(/^Nombre \(Español\)/)
        .fill(name!)
      await page.getByRole('dialog').getByRole('button', { name: 'Crear' }).click()
      await expect(page.getByRole('status').filter({ hasText: 'Asignatura creada' })).toBeVisible()
    }

    // 4. Subgrupo y asignación de una asignatura al grupo.
    await page.getByRole('link', { name: 'A', exact: true }).click()
    await page.getByRole('button', { name: 'Nuevo subgrupo' }).click()
    await page
      .getByRole('dialog')
      .getByLabel(/^Código/)
      .fill('D1')
    await page
      .getByRole('dialog')
      .getByLabel(/^Nombre/)
      .fill('Desdoble 1')
    await page.getByRole('dialog').getByRole('button', { name: 'Crear' }).click()
    await expect(page.getByRole('status').filter({ hasText: 'Subgrupo creado' })).toBeVisible()

    await page.getByRole('button', { name: 'Editar asignaturas' }).click()
    await page.getByLabel(/MAT/).check()
    await page.getByRole('button', { name: 'Guardar' }).click()
    await expect(
      page.getByRole('status').filter({ hasText: 'Asignaturas del grupo actualizadas' }),
    ).toBeVisible()
    await expect(page.getByRole('list', { name: 'Asignaturas que cursa' })).toContainText('MAT')

    // 5. Borrar el curso de etapa con hijos: 409 junto a la acción.
    await page.getByRole('link', { name: /Volver al curso/ }).click()
    // Espera a que la ficha termine de cargar (grupos y asignaturas): el clic antes de eso se pierde.
    await page.waitForLoadState('networkidle')
    await expect(page.getByRole('heading', { name: /2ESO/ })).toBeVisible()
    await page.getByRole('button', { name: 'Borrar', exact: true }).click()
    await page.getByRole('button', { name: 'Borrar 2ESO' }).click()
    await expect(page.getByRole('alert')).toContainText('Grupos')

    // 6. Cambiar de curso académico con el selector: la URL gana ?academic_year=.
    await page.goto('/academico/estructura')
    await page.getByLabel('Curso académico').click()
    await page.getByRole('option', { name: /2027-2028/ }).click()
    await expect(page).toHaveURL(/academic_year=/)
    // El curso en planificación no tiene etapas; al volver al activo reaparece la estructura.
    await expect(page.getByText('Este curso académico no tiene etapas')).toBeVisible()
    await page.getByLabel('Curso académico').click()
    await page.getByRole('option', { name: /2026-2027/ }).click()
    await expect(page.getByRole('link', { name: 'ESO' })).toBeVisible()
  })
})
