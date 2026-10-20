import { expect, test } from '@playwright/test'

/**
 * `docs/modulos/REQ-ACAD/funcional.md §18.8` (1.12-a, parte web), `CA-ACAD-135`: flujo
 * contra la **API real**, solo con `E2E_REAL_API=1` (patrón de `e2e/acad-structure.spec.ts`
 * y `e2e/acad-periods.spec.ts`): `scripts/e2e-real-api.sh` prepara el centro de pruebas
 * sintético (con dos profesores, Daniel y Marta Docente) y lo retira al terminar.
 *
 * Recorrido: crear un aula; en una etapa, un marco ordinario con tres franjas; un curso,
 * un grupo y dos asignaturas; asignar profesorado (Daniel a Matemáticas y a Lengua, Marta a
 * Lengua); componer tres sesiones; mover una a la hora de otra del mismo profesor y ver el
 * `422 timetable_conflict` junto a la acción, nombrando la sesión con la que choca; y
 * cambiar a la vista del profesor.
 */

test.describe('CA-ACAD-135 (REQ-ACAD-002): horarios contra la API real', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real y un centro de pruebas: ejecútalo con `npm run test:e2e:real` (apps/web/scripts/e2e-real-api.sh), que lo prepara y define E2E_REAL_API=1, E2E_ADMIN_EMAIL y E2E_ADMIN_PASSWORD.',
  )

  test.use({ baseURL: process.env.E2E_BASE_URL ?? 'http://demo.plataforma.test:5173' })

  test('aula, marco con franjas, asignaciones, sesiones, conflicto de profesor y vista del profesor', async ({
    page,
  }) => {
    test.setTimeout(120_000)
    page.setDefaultTimeout(15_000)
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

    // 1. Un aula.
    await page.goto('/academico/espacios')
    await page.getByRole('button', { name: 'Nuevo espacio' }).click()
    const facilityDialog = page.getByRole('dialog')
    await facilityDialog.getByLabel(/^Tipo de espacio/).click()
    await page.getByRole('option', { name: 'Aula', exact: true }).click()
    await facilityDialog.getByLabel(/^Código/).fill('A101')
    await facilityDialog.getByLabel(/^Nombre/).fill('Aula 101')
    await facilityDialog.getByRole('button', { name: 'Guardar' }).click()
    await expect(page.getByRole('status').filter({ hasText: 'Espacio creado' })).toBeVisible()

    // 2. Una etapa y su marco ordinario con tres franjas.
    await page.goto('/academico/estructura')
    await page.getByRole('button', { name: 'Nueva etapa' }).first().click()
    const levelDialog = page.getByRole('dialog')
    await levelDialog.getByLabel(/^Código/).fill('ESO')
    await levelDialog.getByLabel(/^Nombre \(Español\)/).fill('Educación Secundaria Obligatoria')
    await levelDialog.getByLabel(/^Tipo de etapa/).click()
    await page
      .getByRole('option', { name: 'Educación Secundaria Obligatoria', exact: true })
      .click()
    await levelDialog.getByLabel(/^Régimen/).click()
    await page.getByRole('option', { name: 'Concertado', exact: true }).click()
    await levelDialog.getByRole('button', { name: 'Crear' }).click()
    await expect(page.getByRole('status').filter({ hasText: 'Etapa creada' })).toBeVisible()
    await page.getByRole('link', { name: 'ESO' }).click()

    await page.getByRole('button', { name: 'Nuevo marco horario' }).click()
    const frameDialog = page.getByRole('dialog')
    await frameDialog.getByLabel(/^Tipo de marco horario/).click()
    await page.getByRole('option', { name: 'Ordinario', exact: true }).click()
    await frameDialog.getByLabel(/^Nombre \(Español\)/).fill('Jornada ordinaria')
    await frameDialog.getByRole('button', { name: 'Crear' }).click()
    await expect(page).toHaveURL(/\/academico\/marcos\/[^/]+/)

    const slots: [string, string, string][] = [
      ['Lunes', '09:00', '09:55'],
      ['Lunes', '10:00', '10:55'],
      ['Martes', '09:00', '09:55'],
    ]

    for (const [index, [day, start, end]] of slots.entries()) {
      await page.getByRole('button', { name: 'Añadir franja' }).click()
      const row = page.getByRole('group', { name: `Franja ${index + 1}`, exact: true })
      await row.getByLabel('Día').click()
      await page.getByRole('option', { name: day, exact: true }).click()
      await row.getByLabel('Inicio').fill(start)
      await row.getByLabel('Fin').fill(end)
    }

    await page.getByRole('button', { name: 'Guardar' }).click()
    await expect(
      page.getByRole('status').filter({ hasText: 'Marco horario actualizado' }),
    ).toBeVisible()

    // 3. Un curso de etapa, un grupo y dos asignaturas asignadas al grupo.
    await page.getByRole('link', { name: /Volver a la etapa/ }).click()
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

    await page.getByRole('link', { name: 'A', exact: true }).click()
    await page.getByRole('button', { name: 'Editar asignaturas' }).click()
    await page.getByLabel(/MAT/).check()
    await page.getByLabel(/LEN/).check()
    await page.getByRole('button', { name: 'Guardar' }).click()
    await expect(
      page.getByRole('status').filter({ hasText: 'Asignaturas del grupo actualizadas' }),
    ).toBeVisible()

    // La sección de profesorado lee las asignaturas del grupo al montarse: se recarga para que vea las recién asignadas.
    await page.reload()

    // 4. Profesorado: Daniel imparte Matemáticas y Lengua; Marta, Lengua.
    const assignments: [string, string][] = [
      ['MAT', 'Daniel Docente'],
      ['LEN', 'Marta Docente'],
      ['LEN', 'Daniel Docente'],
    ]

    for (const [subject, teacher] of assignments) {
      await page.getByRole('button', { name: 'Asignar profesorado' }).click()
      const dialog = page.getByRole('dialog')
      await dialog.getByLabel(/^Asignatura/).click()
      await page.getByRole('option', { name: new RegExp(subject) }).click()
      await dialog.getByLabel(teacher).check()
      await dialog.getByRole('button', { name: 'Asignar' }).click()
      await expect(dialog).toBeHidden()
      await expect(
        page.getByRole('status').filter({ hasText: 'Profesorado asignado' }),
      ).toBeVisible()
    }

    // 5. Componer sesiones en la rejilla del grupo.
    await page.goto('/academico/horarios')
    await page.getByLabel(/^Grupo$/).click()
    // La lista desplegable queda fuera del viewport en esta página: se elige con el teclado.
    await page.getByRole('option', { name: /2\.º ESO A/ }).press('Enter')

    const monday9 = page.locator('[data-cell="0-0"]')
    const monday10 = page.locator('[data-cell="1-0"]')
    const tuesday9 = page.locator('[data-cell="0-1"]')

    const addSession = async (
      cell: typeof monday9,
      assignment: RegExp,
      facility?: string,
    ): Promise<void> => {
      await cell.getByRole('button', { name: /Añadir sesión/ }).click()
      const dialog = page.getByRole('dialog')
      await dialog.getByLabel('Asignación docente').click()
      await page.getByRole('option', { name: assignment }).click()
      if (facility) {
        await dialog.getByLabel('Aula').click()
        await page.getByRole('option', { name: new RegExp(facility) }).click()
      }
      await dialog.getByRole('button', { name: 'Guardar' }).click()
      await expect(dialog).toBeHidden()
    }

    await addSession(monday9, /MAT.*Daniel Docente/, 'A101')
    await expect(monday9).toContainText('Matemáticas')
    await expect(monday9).toContainText('A101')

    await addSession(tuesday9, /LEN.*Marta Docente/)
    await expect(tuesday9).toContainText('Marta Docente')

    await addSession(monday10, /LEN.*Daniel Docente/)
    await expect(monday10).toContainText('Daniel Docente')

    // 6. Conflicto de profesor: la sesión de Daniel del lunes a las 10:00 se mueve a las 09:00,
    // cuando Daniel ya da Matemáticas. El 422 sale junto a la acción, con el profesor y la sesión.
    await monday10.getByRole('button', { name: /Editar/ }).click()
    const editDialog = page.getByRole('dialog')
    await editDialog.getByLabel('Franja').click()
    await page.getByRole('option', { name: /Lunes 09:00/ }).click()
    await editDialog.getByRole('button', { name: 'Guardar' }).click()

    const conflict = editDialog.locator('[data-slot="acad-session-conflicts"]')
    await expect(conflict).toBeVisible()
    await expect(conflict).toContainText('La sesión choca con otra')
    await expect(conflict).toContainText('Profesor')
    await expect(conflict).toContainText('Matemáticas')
    await editDialog.getByRole('button', { name: 'Cancelar' }).click()
    await expect(editDialog).toBeHidden()

    // La sesión no se movió.
    await expect(monday10).toContainText('Lengua')

    // 7. Vista del profesor: Marta solo da Lengua el martes.
    await page.getByLabel('Ver por').click()
    await page.getByRole('option', { name: 'Profesor', exact: true }).click()
    await page.getByLabel('Marta Docente').check()

    const grid = page.locator('[data-slot="acad-timetable-grid"]')
    await expect(grid).toContainText('Lengua')
    await expect(grid).toContainText('Marta Docente')
    await expect(grid).not.toContainText('Matemáticas')
    await expect(grid).not.toContainText('Daniel Docente')
  })
})
