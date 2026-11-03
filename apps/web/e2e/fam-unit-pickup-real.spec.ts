import { join } from 'node:path'
import { test, expect, type Page } from '@playwright/test'
import { RealSession } from './fixtures/realSession'

/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.9`, `CA-FAM-UNIT-142` (E2E real) y la parte de integración de
 * `CA-FAM-UNIT-141`: sección «Personas autorizadas a recoger» de la ficha de familia **en navegador real contra la API
 * real** (cookie de sesión y CSRF reales; datos sintéticos de `REQ-SEED-005`: `@example.com`, pasaporte ficticio, fotografía
 * sin rostro). Secretaría da de alta un tercero (persona → autorización → fotografía), lo ve en la lista vigente con su `alt`
 * y lo retira; la accesibilidad se comprueba con aserciones explícitas (roles, nombres, `alt`, foco y estado por texto e
 * icono, `OPEN-FAM-UNIT-62` A), sin `@axe-core/playwright`.
 *
 * Solo con `E2E_REAL_API=1`: `npm run test:e2e:real` (apps/web/scripts/e2e-real-api.sh) prepara el centro `demo` con
 * `fam_unit` y `prl` y define las variables. Almacén: el S3 que haya en `minio:9000` (MinIO o el servidor falso de
 * `apps/api/tests/Support/e2e-fake-s3.php`, `D-67`); la URL firmada de la fotografía apunta a `minio:9000`, que el navegador no
 * resuelve, así que se redirige a `127.0.0.1:9000` (como `prl-door-api.spec.ts`).
 */

const S3_PUBLIC = process.env.E2E_S3_PUBLIC_URL ?? 'http://127.0.0.1:9000'
const S3_INTERNAL = process.env.E2E_S3_INTERNAL_URL ?? 'http://minio:9000'
const PERSON = { given: 'Abuelo', family: 'Ficticio', document: 'E2EFICT0002', phone: '600555777' }
const PERSON_NAME = `${PERSON.given} ${PERSON.family}`

test.describe('CA-FAM-UNIT-142 (REQ-FAM-UNIT-005): personas autorizadas en navegador real contra la API real', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real y un centro de pruebas: ejecútalo con `npm run test:e2e:real` (apps/web/scripts/e2e-real-api.sh).',
  )

  test.use({ baseURL: process.env.E2E_BASE_URL ?? 'http://demo.plataforma.test:5173' })

  let unit = ''
  let studentName = ''

  test.beforeAll(async () => {
    const admin = await RealSession.login(
      'demo',
      process.env.E2E_ADMIN_EMAIL ?? '',
      process.env.E2E_ADMIN_PASSWORD ?? '',
    )

    unit = (await admin.json('POST', 'family-units', 201, {})).public_id as string
    const student = process.env.E2E_STUDENT_1 ?? ''
    const added = await admin.json('POST', `family-units/${unit}/students`, 201, {
      student_person: student,
    })

    studentName = added.display_name as string

    const user = await admin.json('POST', 'users', 201, {
      email: 'e2e-madre-142@example.com',
      person: { given_name: 'Marta', family_name_1: 'Ficticia', contact_phone: '600111222' },
      send_invitation: false,
      role_ids: [],
    })
    const guardian = (
      await admin.json('POST', 'guardians', 201, { user: user.public_id, family_unit: unit })
    ).public_id as string

    await admin.json('POST', 'guardian-links', 201, {
      guardian,
      student_person: student,
      relationship: 'madre',
      is_legal_guardian: true,
    })
    await admin.json('PATCH', `family-units/${unit}`, 200, { principal_guardian: guardian })

    const text = (
      await admin.json('POST', 'terms-versions', 201, {
        kind: 'autorizacion_recogida',
        title_translations: { 'es-ES': 'Autorización de recogida' },
        body_translations: {
          'es-ES': 'Autorizo a la persona indicada a recoger a mi hijo o hija.',
        },
      })
    ).public_id as string

    await admin.json('POST', `terms-versions/${text}/publish`, 200)
    await admin.dispose()
  })

  async function signIn(page: Page): Promise<void> {
    await page.goto('/entrar')
    await page.getByLabel(/correo/i).fill(process.env.E2E_SECRETARIA_EMAIL ?? '')
    await page.getByLabel(/contraseña/i).fill(process.env.E2E_STAFF_PASSWORD ?? '')
    await page.getByRole('button', { name: /entrar|acceder/i }).click()
    await expect(page).not.toHaveURL(/\/entrar/)
  }

  test('secretaría da de alta un tercero, sube su foto, lo ve en la lista vigente y lo retira', async ({
    page,
  }) => {
    // La URL firmada apunta al `minio` interno de la red de contenedores: el navegador la pide por loopback.
    await page.route(`${S3_INTERNAL}/**`, async (route) => {
      const response = await route.fetch({
        url: route.request().url().replace(S3_INTERNAL, S3_PUBLIC),
      })

      await route.fulfill({ response })
    })

    await signIn(page)
    await page.goto(`/familias/${unit}`)

    const section = page.getByRole('region', { name: 'Personas autorizadas a recoger' })

    await expect(section).toBeVisible()
    await expect(section.getByRole('heading', { level: 2 })).toBeVisible()

    // Al montar, ninguna petición auditada: la tabla y la lista esperan a que se elija un alumno (UI-2).
    await section.getByRole('combobox', { name: 'Alumno' }).click()
    await page.getByRole('option', { name: studentName }).click()
    await expect(
      section.getByText('Este alumno no tiene autorizaciones de terceros.'),
    ).toBeVisible()

    // ── Teclado: el botón abre el diálogo con Enter, el foco queda dentro y Esc lo devuelve al botón ──
    const newButton = section.getByRole('button', { name: 'Nueva autorización' })

    await newButton.focus()
    await expect(newButton).toBeFocused()
    await page.keyboard.press('Enter')

    const wizard = page.getByRole('dialog', { name: 'Nueva persona autorizada' })

    await expect(wizard).toBeVisible()

    for (let i = 0; i < 12; i++) {
      await page.keyboard.press('Tab')
      expect(await wizard.evaluate((el) => el.contains(document.activeElement))).toBe(true)
    }

    await page.keyboard.press('Escape')
    await expect(wizard).toBeHidden()
    await expect(newButton).toBeFocused()

    // ── Paso 1: persona (documento sintético; el servidor no la conoce) ──
    await newButton.click()
    await wizard.getByRole('combobox', { name: 'Tipo de documento' }).click()
    await page.getByRole('option', { name: 'Pasaporte' }).click()
    await wizard.getByLabel('Número de documento').fill(PERSON.document)
    await wizard.getByRole('button', { name: 'Buscar' }).click()
    await expect(wizard.getByText('No hay ninguna persona con ese documento.')).toBeVisible()
    await wizard.getByLabel('Nombre', { exact: true }).fill(PERSON.given)
    await wizard.getByLabel('Primer apellido').fill(PERSON.family)
    await wizard.getByLabel('Teléfono').fill(PERSON.phone)
    await wizard.getByRole('button', { name: 'Siguiente' }).click()

    // ── Paso 2: autorización. El foco va al encabezado del paso; el tutor que autoriza es de solo lectura ──
    await expect(wizard.getByLabel('Autoriza')).toHaveValue('Marta Ficticia')
    await expect(wizard.getByLabel('Autoriza')).not.toBeEditable()
    await expect(wizard.getByText('2. Autorización')).toBeFocused()
    await expect(wizard.getByLabel('Texto firmado (versión)')).toContainText('Publicada')
    await wizard.getByRole('button', { name: 'Siguiente' }).click()

    // ── Paso 3: fotografía. Hasta subirla la autorización no es vigente ──
    await expect(wizard.getByText('Hasta que subas la fotografía')).toBeVisible()
    await expect(wizard.getByText('3. Fotografía')).toBeFocused()
    await wizard
      .getByLabel('Fotografía', { exact: true })
      .setInputFiles(join(process.cwd(), 'e2e', 'fixtures', 'pickup-photo-e2e.jpg'))
    await expect(wizard.getByRole('img', { name: `Fotografía de ${PERSON_NAME}` })).toBeVisible()
    await wizard.getByRole('button', { name: 'Subir fotografía' }).click()
    await expect(wizard).toBeHidden()

    // Tras subir la foto, la autorización es «Vigente» (texto + icono decorativo, no solo color).
    const row = section.getByRole('row', { name: new RegExp(PERSON_NAME) })

    await expect(row.getByText('Vigente', { exact: true })).toBeVisible()
    await expect(row.locator('svg[aria-hidden="true"]').first()).toBeVisible()
    await expect(
      section.getByRole('status').filter({ hasText: 'Autorización registrada.' }),
    ).toBeVisible()

    // ── Lista vigente: solo bajo demanda, con aviso de registro y la foto con su `alt` ──
    await expect(section.getByText('Esta consulta queda registrada con tu usuario')).toBeVisible()
    await section.getByRole('button', { name: 'Ver lista de recogida' }).click()

    const photo = section.getByRole('img', { name: `Fotografía de ${PERSON_NAME}` })

    await expect(photo).toBeVisible()
    await expect
      .poll(() => photo.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0))
      .toBe(true)
    await expect(photo).toHaveAttribute('referrerpolicy', 'no-referrer')

    const card = section.getByRole('listitem').filter({ hasText: PERSON_NAME }).first()

    await expect(card.getByText('Autorizado/a')).toBeVisible()
    await expect(card.getByText('Abuelo/a')).toBeVisible()
    await expect(card.getByText(/•••••.*0002/)).toBeVisible()
    await expect(card.getByRole('link', { name: PERSON.phone })).toHaveAttribute(
      'href',
      `tel:${PERSON.phone}`,
    )

    // ── CA-FAM-UNIT-141 (integración): ningún dato de la persona en almacenamiento, URL, history.state ni título ──
    const leaks = await page.evaluate(() => ({
      local: JSON.stringify({ ...window.localStorage }),
      session: JSON.stringify({ ...window.sessionStorage }),
      state: JSON.stringify(window.history.state),
      title: document.title,
      url: window.location.href,
    }))
    const haystack = Object.values(leaks).join('\n')

    for (const needle of [
      PERSON.given,
      PERSON.family,
      PERSON.document,
      PERSON.phone,
      'minio',
      '/photo',
      'Lucía',
    ]) {
      expect(haystack, `«${needle}» no debe persistirse`).not.toContain(needle)
    }

    // ── Retirada: con confirmación y texto de irreversibilidad; deja de figurar en la siguiente lectura ──
    const withdraw = section.getByRole('button', {
      name: `Retirar la autorización de ${PERSON_NAME} para ${studentName}`,
    })

    await withdraw.focus()
    await page.keyboard.press('Enter')

    const confirm = page.getByRole('alertdialog')

    await expect(confirm).toContainText('La retirada no se puede deshacer')
    await confirm.getByRole('button', { name: /^Retirar la autorización de/ }).click()
    await expect(
      section.getByRole('status').filter({ hasText: 'Autorización retirada.' }),
    ).toBeVisible()
    await expect(row.getByText(/No vigente/)).toBeVisible()
    await expect(
      section.getByRole('button', {
        name: `Retirar la autorización de ${PERSON_NAME} para ${studentName}`,
      }),
    ).toHaveCount(0)

    await section.getByRole('button', { name: 'Ver lista de recogida' }).click()
    await expect(section.getByText('Lista calculada el')).toBeVisible()
    await expect(section.getByRole('img', { name: `Fotografía de ${PERSON_NAME}` })).toHaveCount(0)
    await expect(section.getByRole('list').getByText(PERSON_NAME)).toHaveCount(0)
  })
})
