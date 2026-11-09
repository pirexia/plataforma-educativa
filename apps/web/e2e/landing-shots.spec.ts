/* eslint-disable @typescript-eslint/no-explicit-any -- cuerpos JSON de la API real, sin tipar a propósito. */
import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { expect, test, type Page } from '@playwright/test'
import {
  ADMIN,
  CENTRE_NAME,
  CONFLICT_ATTEMPT,
  FAMILY,
  OTHER_STUDENTS,
  TEACHERS,
  ROLE,
  createShowcase,
  ulid,
  type ShowcaseRefs,
} from './fixtures/landingShowcase'
import { RealSession } from './fixtures/realSession'

/**
 * Capturas de la landing (ADR-074 §4.4, issue #579). **No es un test**: solo corre con `E2E_LANDING_SHOTS=1`, lanzado por
 * `npm run landing:shots` (`scripts/landing-shots.sh`), que crea el centro de escaparate y lo retira siempre. No entra en
 * `test:e2e:real` ni en CI.
 *
 * T2a: comprobación de humo del arnés (inicia sesión en inglés, crea el escaparate con el *fixture*
 * `e2e/fixtures/landingShowcase.ts` por la API real y comprueba por API que existen los datos clave).
 * T2b/T2c: captura las ocho pantallas (la de acceso, sin sesión, y las siete de `SHOTS`) como PNG maestros 1440 × 900 CSS px a DPR 2 en
 * `apps/landing/tools/images/masters/<id>.png` (directorio ignorado por Git) y deja junto a cada una `<id>.txt` con el texto
 * visible de la pantalla para la revisión humana (ADR-074 §4.3). Falla si falta una pantalla, si aparece una petición de API
 * con 4xx/5xx inesperada, o si el texto visible contiene algo que no debe salir en una imagen pública.
 */
const HERE = dirname(fileURLToPath(import.meta.url))
const MASTERS_DIR = process.env.E2E_SHOTS_DIR ?? resolve(HERE, '../../landing/tools/images/masters')

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

/** Espera a un estado estable: red en reposo, sin esqueletos, indicadores de carga ni `aria-busy`, y tipografías cargadas. */
async function settle(page: Page): Promise<void> {
  await page.waitForLoadState('networkidle')
  await expect(
    page.locator('[aria-busy="true"], .animate-pulse, [role="progressbar"]'),
  ).toHaveCount(0)
  await page.evaluate(() => document.fonts.ready)
  await page.waitForTimeout(400)
}

/** Texto visible que nunca debe salir en una imagen pública (CLAUDE.md §11, ADR-074 §4.3). Devuelve los hallazgos. */
function forbiddenIn(text: string): string[] {
  const found: string[] = []
  const emails = text.match(/[\w.+-]+@[\w.-]+\.[a-z]{2,}/gi) ?? []

  for (const email of emails) {
    if (!/@example\.com$/i.test(email)) found.push(`correo ${email}`)
  }

  const patterns: [string, RegExp][] = [
    ['marca técnica E2E', /E2E|CA-PERM|Centro ficticio e2e/i],
    ['URL o puerto de desarrollo', /localhost|127\.0\.0\.1|plataforma\.test|:\d{4,5}\b/i],
    ['documento de identidad', /\b\d{8}[A-Z]\b|\b[XYZ]\d{7}[A-Z]\b/],
  ]

  for (const [label, re] of patterns) {
    const m = text.match(re)

    if (m) found.push(`${label}: ${m[0]}`)
  }

  return found
}

interface Shot {
  id: string
  path: string
  /** Patrón de peticiones de API con 4xx/5xx que se esperan en esta pantalla. */
  expected?: RegExp
  prepare?: (page: Page) => Promise<void>
}

/**
 * Acciones de negocio que se dan de alta justo antes de capturar el registro de auditoría, por la API real con la sesión del
 * administrador: el listado va de lo más reciente a lo más antiguo y el inicio de sesión de la captura (y el dispositivo conocido)
 * son lo último que se registra, así que sin esto las primeras filas serían ruido de acceso. Son cambios ficticios y de bajo
 * impacto que no alteran ninguna otra captura (se ejecuta tras ellas, salvo la de familia, que no los muestra).
 */
async function businessActivity(admin: RealSession, refs: ShowcaseRefs): Promise<void> {
  await admin.json(
    'POST',
    'students',
    201,
    {
      given_name: 'Marta',
      family_name_1: 'Olmedo',
      family_name_2: 'Pons',
      birth_date: '2015-06-30',
    },
    { 'Idempotency-Key': ulid() },
  )
  await admin.json(
    'POST',
    'students',
    201,
    {
      given_name: 'Pablo',
      family_name_1: 'Rueda',
      family_name_2: 'Gil',
      birth_date: '2014-12-05',
    },
    { 'Idempotency-Key': ulid() },
  )
  await admin.json('POST', 'facilities', 201, {
    facility_type: 'aula',
    code: 'A202',
    name: 'Classroom 202',
  })

  const [first, second] = [refs.sessions['1ESO-A']![0]!, refs.sessions['1ESO-A']![5]!]
  const rooms = (await admin.json('GET', 'facilities?per_page=100', 200)).data as {
    public_id: string
    code: string
  }[]
  const a202 = rooms.find((r) => r.code === 'A202')!.public_id
  const a201 = rooms.find((r) => r.code === 'A201')!.public_id

  await admin.json('PATCH', `timetable-sessions/${first}`, 200, { facility: a201 })
  await admin.json('PATCH', `timetable-sessions/${second}`, 200, { facility: a202 })

  await admin.json('PUT', `roles/${refs.role}/permissions`, 200, {
    permissions: [
      ...[...ROLE.allow, 'reservas.crear'].map((code) => ({
        code,
        effect: 'allow',
        scope: 'todos',
      })),
      ...ROLE.deny.map((code) => ({ code, effect: 'deny', scope: 'todos' })),
    ],
  })
}

const SHOTS = (refs: ShowcaseRefs, admin: RealSession): Shot[] => [
  {
    // Rejilla limpia de 1.º ESO A (vista por grupo): sin diálogo ni aviso.
    id: 'hero-schedule',
    path: `/academico/horarios?view=group&id=${refs.groups['1ESO-A']}`,
    prepare: async (page) => {
      await page.getByRole('table').first().scrollIntoViewIfNeeded()
    },
  },
  {
    // Intento de mover una sesión de 1.º ESO B a una franja donde el profesor ya da clase: el servidor lo rechaza.
    id: 'schedule-conflict',
    path: `/academico/horarios?view=group&id=${refs.groups['1ESO-B']}`,
    expected: /^422 PATCH .*timetable-sessions/,
    prepare: async (page) => {
      await page.getByRole('button', { name: /edit/i }).first().click()
      await page.locator('#acad-session-slot').click()
      await page
        .getByRole('option', { name: /11:35.*12:30/ })
        .last()
        .click()
      await page.getByRole('button', { name: /^save$/i }).click()
      await expect(page.getByRole('alert').first()).toBeVisible()
    },
  },
  {
    id: 'role-permissions',
    path: `/administracion/roles/${refs.role}/permisos`,
    // «Academic management»: el rol concede, deniega de forma explícita (`deny`: no puede borrar horarios ni espacios) y deja sin conceder.
    prepare: async (page) => {
      await page.locator('#role-editor-module').selectOption({ label: 'Academic management' })
    },
  },
  {
    id: 'audit-log',
    path: '/administracion/auditoria',
    prepare: async (page) => {
      await businessActivity(admin, refs)
      await page.reload()
    },
  },
  { id: 'family-unit', path: `/familias/${refs.unit}` },
  {
    id: 'pickup-door',
    path: '/conserjeria',
    prepare: async (page) => {
      await page.getByRole('searchbox').fill(FAMILY.students[0]!.family1)
      await expect(page.getByText(FAMILY.students[0]!.given).first()).toBeVisible()
    },
  },
  { id: 'school-branding', path: '/administracion/centro/marca' },
]

test.describe('landing:shots: escaparate, comprobación de humo y capturas', () => {
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
    // #592: la API responde 200 aunque el almacén falle; el fondo debe quedar registrado y descargarse (bytes PNG).
    expect(tenant.login_background_url, 'fondo de acceso no registrado').toBeTruthy()

    const s3Internal = process.env.E2E_S3_INTERNAL_URL ?? 'http://minio:9000'
    const s3Public = process.env.E2E_S3_PUBLIC_URL ?? 'http://127.0.0.1:9000'
    const bg = await fetch(String(tenant.login_background_url).replace(s3Internal, s3Public))

    expect(bg.status, 'el fondo de acceso no se descarga').toBe(200)
    expect(bg.headers.get('content-type')).toContain('image/png')

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

  test('captura las ocho pantallas (PNG maestros)', async ({ page }) => {
    test.setTimeout(480_000)
    page.setDefaultTimeout(20_000)
    mkdirSync(MASTERS_DIR, { recursive: true })

    // El logo se sirve por URL firmada al `minio` interno de la red de contenedores: el navegador la pide por loopback.
    const s3Internal = process.env.E2E_S3_INTERNAL_URL ?? 'http://minio:9000'
    const s3Public = process.env.E2E_S3_PUBLIC_URL ?? 'http://127.0.0.1:9000'

    await page.route(`${s3Internal}/**`, async (route) => {
      const response = await route.fetch({
        url: route.request().url().replace(s3Internal, s3Public),
      })

      await route.fulfill({ response })
    })

    // Errores inesperados: cualquier 4xx/5xx de la API, salvo los que se esperan en el paso (`expected`), y cualquier fallo de red.
    let expected: RegExp | null = null
    const failures: string[] = []

    page.on('requestfailed', (request) => {
      failures.push(`${request.method()} ${request.url()} ${request.failure()?.errorText}`)
    })
    page.on('response', (response) => {
      const line = `${response.status()} ${response.request().method()} ${response.url()}`

      if (
        response.url().includes('/api/v1/') &&
        response.status() >= 400 &&
        !expected?.test(line)
      ) {
        failures.push(line)
      }
    })

    const results: string[] = []

    const capture = async (id: string): Promise<void> => {
      const text = await page.locator('body').innerText()
      const problems = forbiddenIn(text)

      expect(problems, `${id}: texto que no debe salir en una imagen`).toEqual([])
      expect(failures, `${id}: errores de red o de API inesperados`).toEqual([])

      await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur())
      await page.mouse.move(1439, 899)
      await page.screenshot({
        path: `${MASTERS_DIR}/${id}.png`,
        animations: 'disabled',
        caret: 'hide',
        scale: 'device',
      })
      writeFileSync(`${MASTERS_DIR}/${id}.txt`, text)
      results.push(id)
    }

    const only = process.env.E2E_SHOTS_ONLY?.split(',').filter(Boolean)
    const wanted = (id: string) => !only || only.includes(id)

    // Pantalla de acceso tal como la ve un visitante: sin sesión, antes de iniciarla (marca del centro, sin datos de nadie).
    if (wanted('sign-in')) {
      failures.length = 0
      await page.addInitScript(() => window.localStorage.setItem('plataforma.locale', 'en'))
      await page.goto('/entrar')
      await settle(page)
      await expect(page.getByLabel(/email/i)).toBeVisible()
      await capture('sign-in')
    }

    await signIn(page, ADMIN.email, process.env.E2E_ADMIN_PASSWORD ?? '')

    const shots = SHOTS(refs, admin).filter((shot) => wanted(shot.id))

    for (const shot of shots) {
      failures.length = 0
      expected = shot.expected ?? null

      await page.goto(shot.path)
      await settle(page)
      await shot.prepare?.(page)
      await settle(page)
      await capture(shot.id)
      expected = null
    }

    expect(results, 'faltan pantallas').toEqual([
      ...(wanted('sign-in') ? ['sign-in'] : []),
      ...shots.map((s) => s.id),
    ])
  })

  test('la lista de docentes del fixture coincide con la del soporte', async () => {
    const users = (await admin.json('GET', 'users?per_page=100', 200)).data as { email: string }[]

    for (const teacher of Object.values(TEACHERS)) {
      expect(users.map((u) => u.email)).toContain(teacher.email)
    }
  })
})
