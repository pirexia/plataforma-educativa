/* eslint-disable @typescript-eslint/no-explicit-any -- cuerpos JSON de la API real, sin tipar a propósito. */
import { expect, test, type Page } from '@playwright/test'
import {
  ADMIN,
  CENTRE_NAME,
  CONFLICT_ATTEMPT,
  FAMILY,
  OTHER_STUDENTS,
  TEACHERS,
  createShowcase,
  type ShowcaseRefs,
} from './fixtures/landingShowcase'
import { RealSession } from './fixtures/realSession'

/**
 * Capturas de la landing (ADR-074 §4.4, issue #579). **No es un test**: solo corre con `E2E_LANDING_SHOTS=1`, lanzado por
 * `npm run landing:shots` (`scripts/landing-shots.sh`), que crea el centro de escaparate y lo retira siempre. No entra en
 * `test:e2e:real` ni en CI.
 *
 * Este tramo (T2a) solo hace la **comprobación de humo** del arnés: inicia sesión en inglés, crea el escaparate con el *fixture*
 * (`e2e/fixtures/landingShowcase.ts`) por la API real, comprueba por API que existen los datos clave y que ninguna de las
 * ocho pantallas devuelve error ni 404. **No captura imágenes** (eso es el tramo T2b).
 */
const SLUG = process.env.E2E_SHOWCASE_SLUG ?? 'demo'

// El escaparate usa el slug `demo`, el de la CORS de desarrollo (`scripts/landing-shots.sh`).
const SPA_ORIGIN = process.env.E2E_BASE_URL ?? `http://${SLUG}.plataforma.test:5173`

test.use({
  baseURL: SPA_ORIGIN,
  launchOptions: { args: ['--host-resolver-rules=MAP *.plataforma.test 127.0.0.1'] },
  // Interfaz en inglés (OPEN-074-02), 1440 × 900 CSS px a doble densidad.
  locale: 'en-US',
  viewport: { width: 1440, height: 900 },
  deviceScaleFactor: 2,
})

async function signIn(page: Page, email: string, password: string): Promise<void> {
  await page.addInitScript(() => window.localStorage.setItem('plataforma.locale', 'en'))
  await page.goto('/entrar')
  await page.getByLabel(/email/i).fill(email)
  await page.getByLabel(/password/i).fill(password)
  await page.getByRole('button', { name: /sign in/i }).click()
  await expect(page).not.toHaveURL(/\/entrar/)
}

test.describe('landing:shots (T2a): escaparate y comprobación de humo', () => {
  test.skip(
    process.env.E2E_LANDING_SHOTS !== '1',
    'Herramienta de capturas, no un test: ejecútala con `npm run landing:shots` (apps/web/scripts/landing-shots.sh).',
  )

  test.describe.configure({ mode: 'serial' })

  let admin: RealSession
  let refs: ShowcaseRefs

  test.beforeAll(async () => {
    admin = await RealSession.login(SLUG, ADMIN.email, process.env.E2E_ADMIN_PASSWORD ?? '')
    refs = await createShowcase(admin)
  })

  test.afterAll(async () => {
    await admin?.dispose()
  })

  test('los datos clave del escaparate existen y son los del fixture', async () => {
    test.setTimeout(60_000)

    const tenant = await admin.json('GET', 'tenant/branding', 200)

    expect(JSON.stringify(tenant)).toContain(CENTRE_NAME)

    const list = async (path: string) => ((await admin.json('GET', path, 200)).data ?? []) as any[]

    expect(await list('education-levels')).toHaveLength(2)
    expect(await list('courses')).toHaveLength(4)
    expect(await list('groups')).toHaveLength(5)
    expect(await list('facilities')).toHaveLength(4)

    const sessionsA = await list(`timetable-sessions?group=${refs.groups['1ESO-A']}&per_page=100`)
    const sessionsB = await list(`timetable-sessions?group=${refs.groups['1ESO-B']}&per_page=100`)

    expect(sessionsA).toHaveLength(20)
    expect(sessionsB).toHaveLength(19)

    const role = await admin.json('GET', `roles/${refs.role}`, 200)

    expect(role.name).toBe('Head of studies')

    const guardians = await list(`guardian-links?family_unit=${refs.unit}&per_page=100`)

    expect(guardians).toHaveLength(FAMILY.guardians.length * FAMILY.students.length)
    expect(guardians.filter((l) => l.is_legal_guardian)).toHaveLength(2 * FAMILY.students.length)

    const students = await list('students?per_page=100')

    expect(students).toHaveLength(FAMILY.students.length + OTHER_STUDENTS.length)

    const door = await admin.json(
      'GET',
      `door/students?q=${encodeURIComponent(FAMILY.students[0]!.family1)}`,
      200,
    )

    expect(JSON.stringify(door)).toContain(FAMILY.students[0]!.given)

    expect(await list('academic-years')).toHaveLength(2)
  })

  test('el conflicto de profesor de la rejilla es detectable (422 timetable_conflict, no se guarda)', async () => {
    const moving = refs.sessions['1ESO-B']![CONFLICT_ATTEMPT.fromSlotIndex]!
    const response = await admin.send('PATCH', `timetable-sessions/${moving}`, {
      time_slot: refs.slots[CONFLICT_ATTEMPT.toSlotIndex],
    })

    expect(response.status(), await response.text()).toBe(422)
    expect(await response.text()).toContain('timetable_conflict')

    const after = await admin.json('GET', `timetable-sessions/${moving}`, 200)

    expect(JSON.stringify(after)).toContain(refs.slots[CONFLICT_ATTEMPT.fromSlotIndex]!)
  })

  test('las ocho pantallas cargan sin error de API ni 404', async ({ page }) => {
    test.setTimeout(180_000)
    page.setDefaultTimeout(20_000)

    const failures: string[] = []

    page.on('requestfailed', (request) => {
      // El logo de marca se sirve del almacén S3 (`minio:9000`), que este humo no exige (lo necesitan las capturas de marca, T2b).
      if (/^http:\/\/(minio|127\.0\.0\.1):9000\//.test(request.url())) {
        return
      }

      failures.push(`${request.method()} ${request.url()} ${request.failure()?.errorText}`)
    })

    page.on('response', (response) => {
      if (response.url().includes('/api/v1/') && response.status() >= 400) {
        failures.push(`${response.status()} ${response.request().method()} ${response.url()}`)
      }
    })

    await signIn(page, ADMIN.email, process.env.E2E_ADMIN_PASSWORD ?? '')

    const screens: [string, string][] = [
      ['timetable', '/academico/horarios'],
      ['structure', '/academico/estructura'],
      ['role permissions', `/administracion/roles/${refs.role}/permisos`],
      ['effective permissions', `/administracion/usuarios/${refs.carmenUser}/permisos`],
      ['audit', '/administracion/auditoria'],
      ['next academic year', `/administracion/cursos/${refs.nextYear}/copiar-estructura`],
      ['family unit', `/familias/${refs.unit}`],
      ['door', '/conserjeria'],
      ['branding', '/administracion/centro/marca'],
    ]

    for (const [name, path] of screens) {
      failures.length = 0
      await page.goto(path)
      await page.waitForLoadState('networkidle')

      await expect(page, `${name}: la ruta redirige`).toHaveURL(
        new RegExp(path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')),
      )
      await expect(
        page.getByRole('heading', { level: 1 }).first(),
        `${name}: sin título`,
      ).toBeVisible()
      await expect(
        page.getByText(/page not found|not found|no encontrada/i),
        `${name}: 404`,
      ).toHaveCount(0)
      expect(failures, `${name}: errores de API`).toEqual([])
    }
  })

  test('la lista de docentes del fixture coincide con la del soporte', async () => {
    const users = (await admin.json('GET', 'users?per_page=100', 200)).data as { email: string }[]

    for (const teacher of Object.values(TEACHERS)) {
      expect(users.map((u) => u.email)).toContain(teacher.email)
    }
  })
})
