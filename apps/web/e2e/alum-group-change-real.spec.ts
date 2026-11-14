import { test, expect, type Locator, type Page } from '@playwright/test'
import { RealSession } from './fixtures/realSession'
import { downloadExportCsv } from './fixtures/dataExport'
import {
  BASE_URL,
  centreA,
  createStructure,
  createStudent,
  enroll,
  ensureActiveYear,
  browserState,
  closeAll,
  loginState,
  pageAs,
  tag,
  type Structure,
  type YearInfo,
} from './fixtures/alumReal'

/**
 * `docs/modulos/REQ-ALUM/funcional.md §11.8` (`1.15-d` T3): solicitudes de cambio de grupo **en navegador real contra la API
 * real**: solicitar desde la ficha y desde la lista de clase, aprobar por otro usuario, autoaprobación rechazada con el mensaje
 * del servidor, rechazo con nota obligatoria, cancelación y curso cerrado sin acciones de escritura (exportar sigue disponible).
 * Cubre `CA-ALUM-070` (sin acciones de escritura con un curso de solo lectura, `RN-ACAD-42`), `CA-ALUM-071` (foco de los diálogos)
 * y el flujo de `CA-ALUM-072`. Datos sintéticos de `REQ-SEED-005`.
 *
 * Actores: secretaría (`matricula.actualizar`, sin `matricula.aprobar`), administración (puede solicitar y aprobar: la
 * autoaprobación la rechaza el servidor) y dirección (`matricula.aprobar`, distinta de quien solicita). El último test cierra el
 * curso activo del centro de pruebas, por eso este spec va el último de la suite `alum`.
 *
 * Solo con `E2E_REAL_API=1`: `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).
 */
test.describe('CA-ALUM-072 CA-ALUM-070 CA-ALUM-071 (REQ-ALUM-003): cambios de grupo en navegador real contra la API real', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real y un centro de pruebas: ejecútalo con `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).',
  )

  test.use({ baseURL: BASE_URL })
  test.describe.configure({ mode: 'serial' })

  const label = tag()
  let year: YearInfo
  let structure: Structure
  let admin: RealSession
  const students: Record<
    'ficha' | 'lista' | 'rechazo' | 'cerrado',
    { id: string; family: string; enrollment: string }
  > = {} as never

  const states = {} as Record<
    'secretaria' | 'admin' | 'direccion',
    Awaited<ReturnType<typeof loginState>>
  >

  test.beforeAll(async () => {
    // Los inicios de sesión esperan lo que pida la API si responde 429 (`RealSession.login`).
    test.setTimeout(180_000)
    states.secretaria = await loginState(centreA.secretariaEmail, centreA.staffPassword)
    states.direccion = await loginState(centreA.direccionEmail, centreA.staffPassword)
    admin = await RealSession.login(centreA.slug, centreA.adminEmail, centreA.adminPassword)
    states.admin = await browserState(admin)
    year = await ensureActiveYear(admin)
    structure = await createStructure(admin, year.id, label)

    for (const key of ['ficha', 'lista', 'rechazo', 'cerrado'] as const) {
      const family = `Cambio${key}${label}`
      const student = await createStudent(admin, 'Alumno', family)

      students[key] = {
        id: student.id,
        family,
        enrollment: await enroll(admin, student.id, year, structure, structure.groupFrom),
      }
    }
  })

  test.afterAll(async () => {
    await closeAll()
    await admin?.dispose()
  })

  const row = (page: Page, family: string): Locator =>
    page.getByRole('row').filter({ hasText: family })

  async function requestIn(dialog: Locator, page: Page, reason: string): Promise<void> {
    await dialog.getByRole('combobox', { name: 'Grupo de destino' }).click()
    await page.getByRole('option', { name: structure.groupToName }).click()
    await dialog.getByLabel('Motivo').fill(reason)
    await dialog.getByRole('button', { name: 'Enviar solicitud' }).click()
  }

  test('CA-ALUM-072: secretaría solicita el cambio de grupo desde la ficha del alumno', async ({
    browser,
  }) => {
    const page = await pageAs(browser, states.secretaria)
    await page.goto(`/alumnado/${students.ficha.id}?academic_year=${year.id}`)

    const trigger = page.getByRole('button', { name: /^Solicitar cambio de grupo/ })

    await trigger.click()

    const dialog = page.getByRole('dialog', { name: 'Solicitar cambio de grupo' })

    // CA-ALUM-071: el foco entra en el diálogo y, al cerrar sin enviar, vuelve al botón que lo abrió.
    await expect(dialog.locator(':focus')).toHaveCount(1)
    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(trigger).toBeFocused()

    await trigger.click()
    await requestIn(dialog, page, 'Cambio pedido por la familia (dato ficticio)')

    await expect(page.getByText('La solicitud de cambio de grupo se ha enviado.')).toBeVisible()
  })

  test('CA-ALUM-072: administración solicita el cambio de grupo desde la lista de clase', async ({
    browser,
  }) => {
    const page = await pageAs(browser, states.admin)
    await page.goto(`/alumnado/listas?academic_year=${year.id}`)

    await row(page, students.lista.family)
      .getByRole('button', { name: /Solicitar cambio de grupo/ })
      .click()

    const dialog = page.getByRole('dialog', { name: 'Solicitar cambio de grupo' })

    await requestIn(dialog, page, 'Cambio pedido desde la lista (dato ficticio)')
    await expect(page.getByText('La solicitud de cambio de grupo se ha enviado.')).toBeVisible()
  })

  test('CA-ALUM-072: dirección aprueba la solicitud de otra persona y el alumno cambia de grupo', async ({
    browser,
  }) => {
    const page = await pageAs(browser, states.direccion)
    await page.goto(`/alumnado/cambios-de-grupo?academic_year=${year.id}`)

    const target = row(page, students.ficha.family)

    await expect(target.getByText('Pendiente', { exact: true })).toBeVisible()
    await target.getByRole('button', { name: /^Aprobar/ }).click()

    const dialog = page.getByRole('dialog', { name: 'Aprobar la solicitud' })

    await dialog.getByRole('button', { name: 'Aprobar', exact: true }).click()
    await expect(page.getByText('La solicitud se ha aprobado.')).toBeVisible()
    await expect(
      row(page, students.ficha.family).getByText('Aprobada', { exact: true }),
    ).toBeVisible()

    const moved = await admin.json('GET', `enrollments/${students.ficha.enrollment}`, 200)

    expect(moved.group.public_id).toBe(structure.groupTo)
  })

  test('CA-ALUM-072: la autoaprobación se rechaza con el mensaje del servidor y la solicitud se cancela', async ({
    browser,
  }) => {
    const page = await pageAs(browser, states.admin)
    await page.goto(`/alumnado/cambios-de-grupo?academic_year=${year.id}`)

    const approve = row(page, students.lista.family).getByRole('button', { name: /^Aprobar/ })

    await approve.click()

    const dialog = page.getByRole('dialog', { name: 'Aprobar la solicitud' })

    await dialog.getByRole('button', { name: 'Aprobar', exact: true }).click()
    await expect(
      dialog
        .getByRole('alert')
        .filter({ hasText: 'No puedes aprobar una solicitud que has hecho tú.' }),
    ).toBeVisible()

    // CA-ALUM-071: Escape cierra el diálogo y el foco vuelve al botón de la fila.
    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(approve).toBeFocused()

    await row(page, students.lista.family)
      .getByRole('button', { name: /^Cancelar/ })
      .click()
    await page
      .getByRole('dialog', { name: 'Cancelar la solicitud' })
      .getByRole('button', { name: 'Cancelar solicitud' })
      .click()
    await expect(page.getByText('La solicitud se ha cancelado.')).toBeVisible()
    await expect(
      row(page, students.lista.family).getByText('Cancelada', { exact: true }),
    ).toBeVisible()

    // El alumno sigue en su grupo de origen.
    const kept = await admin.json('GET', `enrollments/${students.lista.enrollment}`, 200)

    expect(kept.group.public_id).toBe(structure.groupFrom)
  })

  test('CA-ALUM-072: el rechazo exige una nota y deja la solicitud rechazada', async ({
    browser,
  }) => {
    await admin.json('POST', 'group-change-requests', 201, {
      enrollment: students.rechazo.enrollment,
      to_group: structure.groupTo,
      reason: 'Solicitud que se rechaza (dato ficticio)',
    })

    const page = await pageAs(browser, states.direccion)
    await page.goto(`/alumnado/cambios-de-grupo?academic_year=${year.id}`)

    await row(page, students.rechazo.family)
      .getByRole('button', { name: /^Rechazar/ })
      .click()

    const dialog = page.getByRole('dialog', { name: 'Rechazar la solicitud' })
    const submit = dialog.getByRole('button', { name: 'Rechazar', exact: true })

    await expect(submit).toBeDisabled()
    await dialog.getByLabel('Nota').fill('Sin plazas en el grupo de destino (dato ficticio)')
    await expect(submit).toBeEnabled()
    await submit.click()

    await expect(page.getByText('La solicitud se ha rechazado.')).toBeVisible()
    await expect(
      row(page, students.rechazo.family).getByText('Rechazada', { exact: true }),
    ).toBeVisible()
  })

  test('CA-ALUM-070 CA-ALUM-072: con el curso cerrado no hay acciones de escritura y exportar sigue disponible', async ({
    browser,
  }) => {
    // Una solicitud pendiente que el cierre del curso deja sin resolver.
    await admin.json('POST', 'group-change-requests', 201, {
      enrollment: students.cerrado.enrollment,
      to_group: structure.groupTo,
      reason: 'Solicitud pendiente al cerrar el curso (dato ficticio)',
    })
    await admin.json('POST', `academic-years/${year.id}/status`, 200, { status: 'cerrado' })

    const page = await pageAs(browser, states.admin)

    await page.goto(`/alumnado/cambios-de-grupo?academic_year=${year.id}`)
    await expect(page.getByText(/solo lectura/).first()).toBeVisible()
    await expect(
      row(page, students.cerrado.family).getByText('Pendiente', { exact: true }),
    ).toBeVisible()
    await expect(page.getByRole('button', { name: /^(Aprobar|Rechazar|Cancelar)/ })).toHaveCount(0)

    await page.goto(`/alumnado/listas?academic_year=${year.id}`)
    await expect(row(page, students.cerrado.family)).toBeVisible()
    await expect(page.getByRole('button', { name: /Solicitar cambio de grupo/ })).toHaveCount(0)

    const exportButton = page.getByRole('button', { name: 'Exportar' })

    await expect(exportButton).toBeEnabled()

    const [response] = await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('/enrollments/exports') && r.request().method() === 'POST',
      ),
      exportButton.click(),
    ])

    expect(response.status(), await response.text()).toBe(202)

    // El worker escribe el CSV en Garage y se descarga por la URL firmada (ADR-076 §2.3): el alumno del curso cerrado está en él.
    const csv = await downloadExportCsv(
      admin,
      ((await response.json()) as { public_id: string }).public_id,
    )
    const lines = csv.trim().split(/\r?\n/)

    expect(lines[0]).toContain('enrollment_public_id,student_public_id,family_name')
    expect(lines.some((line) => line.includes(students.cerrado.family))).toBe(true)
  })
})
