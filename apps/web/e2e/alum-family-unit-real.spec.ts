import { test, expect } from '@playwright/test'
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
 * `docs/proceso/e2e-real-ci.md §9.4`, `CA-E2ECI-31b` (E2E-CI-d2, fila F-01 de `e2e-real-ci-matriz.md §4.4`; `REQ-FAM-UNIT-001`,
 * `REQ-FAM-UNIT-002`): secretaría da de alta en `/familias`, **en navegador real contra la API real**, una unidad familiar, un tutor y
 * un vínculo tutor ↔ alumno con una persona alumno sintética; la ficha de la unidad muestra al alumno, al tutor y el vínculo activo,
 * una lectura HTTP real lo confirma y un error de validación del servidor (vínculo duplicado, `fam_unit.link.already_exists`) se
 * muestra en el formulario. El tutor es una persona usuaria **nueva** (no `e2e-tutor`, que solo puede ser tutor una vez y lo usan
 * otros specs). Datos sintéticos de `REQ-SEED-005`.
 *
 * Solo con `E2E_REAL_API=1`: `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).
 */
test.describe('CA-E2ECI-31b (REQ-FAM-UNIT-001, REQ-FAM-UNIT-002): secretaría da de alta unidad, tutor y vínculo en /familias', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real y un centro de pruebas: ejecútalo con `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).',
  )

  test.use({ baseURL: BASE_URL })
  test.afterAll(closeAll)

  test('CA-E2ECI-31b F-01: alta de unidad, tutor, alumno y vínculo por pantalla, lectura HTTP y error de vínculo duplicado en el formulario', async ({
    browser,
  }) => {
    test.setTimeout(240_000)
    const label = tag()
    const guardianFamily = `Tutora${label}`
    const studentFamily = `Familiar${label}`
    const admin = await RealSession.login(centreA.slug, centreA.adminEmail, centreA.adminPassword)
    const secretaria = await RealSession.login(
      centreA.slug,
      centreA.secretariaEmail,
      centreA.staffPassword,
    )

    try {
      // Preparación por API: la persona usuaria que será tutora (sin roles ni invitación) y una persona alumno sin unidad.
      const user = await admin.json('POST', 'users', 201, {
        email: `e2e-tutora-alta-${label}@example.com`,
        person: { given_name: 'Tutora', family_name_1: guardianFamily },
        send_invitation: false,
        role_ids: [],
      })
      const student = await createStudent(admin, 'Alumno', studentFamily)
      const studentPerson = (await admin.json('GET', `students/${student.id}`, 200)).person
        .public_id as string

      const page = await pageAs(browser, await browserState(secretaria))

      // Unidad familiar nueva.
      await page.goto('/familias')
      await expect(page.getByRole('heading', { level: 1, name: 'Familias' })).toBeVisible()
      await page.getByRole('button', { name: 'Nueva unidad' }).first().click()
      await page.getByRole('dialog').getByRole('button', { name: 'Crear', exact: true }).click()
      await expect(page).toHaveURL(/\/familias\/[0-9A-Z]{26}$/)

      const unit = page.url().split('/').pop() as string

      await expect(page.getByText('La unidad no tiene tutores.')).toBeVisible()

      // Tutor: se elige a la persona usuaria (buscador con `usuario.leer`, o su identificador si falta ese permiso).
      await page.getByRole('button', { name: 'Añadir tutor' }).click()

      const guardianDialog = page.getByRole('dialog', { name: 'Añadir tutor' })
      const typedId = guardianDialog.getByLabel('Identificador del usuario')

      if (await typedId.isVisible().catch(() => false)) {
        await typedId.fill(user.public_id as string)
      } else {
        await guardianDialog.getByRole('searchbox').fill(`e2e-tutora-alta-${label}`)
        await guardianDialog.getByRole('radio', { name: new RegExp(guardianFamily) }).check()
      }

      await guardianDialog.getByRole('button', { name: 'Añadir tutor' }).click()
      await expect(page.getByText('Tutor añadido a la unidad.')).toBeVisible()
      await expect(page.getByRole('cell', { name: new RegExp(guardianFamily) })).toBeVisible()

      // Alumno: por el identificador público de su persona.
      await page.getByRole('button', { name: 'Añadir alumno' }).click()
      await page.getByLabel('Identificador de la persona del alumno').fill(studentPerson)
      await page
        .getByRole('dialog', { name: 'Añadir alumno' })
        .getByRole('button', { name: 'Añadir alumno' })
        .click()
      await expect(page.getByText('Alumno añadido a la unidad.')).toBeVisible()
      await expect(page.getByRole('cell', { name: new RegExp(studentFamily) })).toBeVisible()

      // Vínculo.
      const fillLink = async () => {
        await page.getByRole('button', { name: 'Nuevo vínculo' }).click()

        const dialog = page.getByRole('dialog', { name: 'Nuevo vínculo' })

        await dialog.getByLabel('Tutor/a').click()
        await page.getByRole('option', { name: new RegExp(guardianFamily) }).click()
        await dialog.getByLabel('Alumno', { exact: true }).click()
        await page.getByRole('option', { name: new RegExp(studentFamily) }).click()
        await dialog.getByLabel('Relación').click()
        await page.getByRole('option', { name: 'Madre' }).click()

        return dialog
      }

      const dialog = await fillLink()

      await dialog.getByRole('button', { name: 'Crear vínculo' }).click()
      await expect(page.getByText('Vínculo creado.')).toBeVisible()

      const row = page
        .getByRole('region', { name: 'Vínculos' })
        .getByRole('row')
        .filter({ hasText: studentFamily })

      await expect(row.getByText('Activo')).toBeVisible()
      await expect(row.getByText('Madre')).toBeVisible()

      // Lectura HTTP real: la unidad tiene al tutor y al alumno, y el vínculo está activo.
      const guardians = (await secretaria.json('GET', `guardians?family_unit=${unit}`, 200))
        .data as Array<{ public_id: string }>

      expect(guardians).toHaveLength(1)

      const members = (await secretaria.json('GET', `family-units/${unit}/students`, 200))
        .data as Array<{ student_person: string }>

      expect(members.map((m) => m.student_person)).toEqual([studentPerson])

      const links = (await secretaria.json('GET', `guardian-links?family_unit=${unit}`, 200))
        .data as Array<{ status: string; relationship: string }>

      expect(links).toHaveLength(1)
      expect(links[0].status).toBe('activo')
      expect(links[0].relationship).toBe('madre')

      // Error de validación del servidor en el formulario: el mismo vínculo otra vez (`fam_unit.link.already_exists`, 422).
      const again = await fillLink()

      await again.getByRole('button', { name: 'Crear vínculo' }).click()
      await expect(again.locator('#fam-unit-link-guardian-errors')).toContainText(
        'Ya existe un vínculo entre ese tutor y ese alumno.',
      )
      await expect(again).toBeVisible()

      // Nada cambió en el servidor.
      expect(
        (await secretaria.json('GET', `guardian-links?family_unit=${unit}`, 200)).data,
      ).toHaveLength(1)
    } finally {
      await Promise.all([admin.dispose(), secretaria.dispose()])
    }
  })
})
