import { expect, type Browser, type Page } from '@playwright/test'
import { RealSession } from './realSession'

/**
 * Soporte de los E2E de navegador de `REQ-ALUM` (`1.15-d` T3, `CA-ALUM-071/072`): variables del centro A que prepara
 * `apps/web/scripts/e2e-real-alum.sh`, acceso por la pantalla de entrada y estructura académica mínima por la API real.
 * Las contraseñas llegan por entorno, nunca escritas en el repositorio.
 */
export const BASE_URL = process.env.E2E_BASE_URL ?? 'http://demo.plataforma.test:5173'

const env = (name: string): string => process.env[`E2E_A_${name}`] ?? ''

export const centreA = {
  slug: env('SLUG'),
  adminEmail: env('ADMIN_EMAIL'),
  adminPassword: env('ADMIN_PASSWORD'),
  staffPassword: env('STAFF_PASSWORD'),
  secretariaEmail: env('SECRETARIA_EMAIL'),
  direccionEmail: env('DIRECCION_EMAIL'),
  docenteEmail: env('DOCENTE_EMAIL'),
  docenteUser: env('DOCENTE_USER'),
  docente2Email: env('DOCENTE2_EMAIL'),
}

/** Sufijo corto y único por ejecución para códigos y nombres ficticios. */
export const tag = (): string => String(Date.now()).slice(-6)

export async function signIn(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/entrar')
  await page.getByLabel(/correo/i).fill(email)
  await page.getByLabel(/contraseña/i).fill(password)
  await page.getByRole('button', { name: /entrar|acceder/i }).click()
  await expect(page).not.toHaveURL(/\/entrar/)
}

export interface YearInfo {
  id: string
  startsOn: string
}

/** El curso activo del centro; si no hay ninguno (spec lanzado solo), crea uno y lo activa. */
export async function ensureActiveYear(admin: RealSession): Promise<YearInfo> {
  const current = await admin.send('GET', 'academic-years/current')

  if (current.status() === 200) {
    const body = await current.json()

    return { id: body.public_id as string, startsOn: body.starts_on as string }
  }

  // Sin curso activo (spec lanzado solo, o el anterior se cerró): uno nuevo que no se solape con los existentes.
  const all = (await admin.json('GET', 'academic-years?per_page=100', 200)).data as Array<{
    ends_on: string
  }>
  const lastEnd =
    all
      .map((y) => y.ends_on as string)
      .sort()
      .pop() ?? '2026-08-31'
  const next = new Date(`${lastEnd}T00:00:00Z`)

  next.setUTCDate(next.getUTCDate() + 1)

  const end = new Date(next)

  end.setUTCFullYear(end.getUTCFullYear() + 1)
  end.setUTCDate(end.getUTCDate() - 1)

  const day = (d: Date): string => d.toISOString().slice(0, 10)
  const created = await admin.json('POST', 'academic-years', 201, {
    code: `E2E-${tag()}`,
    starts_on: day(next),
    ends_on: day(end),
  })

  await admin.json('POST', `academic-years/${created.public_id}/status`, 200, { status: 'activo' })

  return { id: created.public_id as string, startsOn: created.starts_on as string }
}

export interface Structure {
  course: string
  courseName: string
  groupFrom: string
  groupFromName: string
  groupTo: string
  groupToName: string
  subject: string
}

/** Etapa, curso, dos grupos y una asignatura (ofertada a ambos grupos) en el curso académico, con nombres únicos. */
export async function createStructure(
  admin: RealSession,
  year: string,
  label: string,
): Promise<Structure> {
  const level = (
    await admin.json('POST', 'education-levels', 201, {
      academic_year: year,
      code: `L${label}`,
      name_translations: { 'es-ES': `Etapa ficticia ${label}` },
      stage: 'eso',
      regime: 'concertado',
    })
  ).public_id as string
  const courseName = `Curso ficticio ${label}`
  const course = (
    await admin.json('POST', 'courses', 201, {
      education_level: level,
      code: `C${label}`,
      name_translations: { 'es-ES': courseName },
    })
  ).public_id as string
  const groupFromName = `Grupo origen ${label}`
  const groupToName = `Grupo destino ${label}`
  const groupFrom = (
    await admin.json('POST', 'groups', 201, { course, code: `O${label}`, name: groupFromName })
  ).public_id as string
  const groupTo = (
    await admin.json('POST', 'groups', 201, { course, code: `D${label}`, name: groupToName })
  ).public_id as string
  const subject = (
    await admin.json('POST', 'subjects', 201, {
      course,
      code: `S${label}`,
      name_translations: { 'es-ES': `Asignatura ficticia ${label}` },
    })
  ).public_id as string

  for (const group of [groupFrom, groupTo]) {
    await admin.json('PUT', `groups/${group}/subjects`, 200, { subjects: [subject] })
  }

  return { course, courseName, groupFrom, groupFromName, groupTo, groupToName, subject }
}

const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'

/** ULID válido: la `Idempotency-Key` de `POST /students` (`RN-ALUM-27`). */
export function ulid(): string {
  let time = Date.now()
  let out = ''

  for (let i = 0; i < 10; i++) {
    out = CROCKFORD[time % 32] + out
    time = Math.floor(time / 32)
  }

  for (let i = 0; i < 16; i++) {
    out += CROCKFORD[Math.floor(Math.random() * 32)]
  }

  return out
}

/** Alumno por la API con nombre ficticio (`REQ-SEED-005`). */
export async function createStudent(
  admin: RealSession,
  given: string,
  family: string,
): Promise<{ id: string; displayName: string }> {
  const student = await admin.json(
    'POST',
    'students',
    201,
    { given_name: given, family_name_1: family, birth_date: '2015-04-12' },
    { 'Idempotency-Key': ulid() },
  )

  return { id: student.public_id as string, displayName: student.display_name as string }
}

export async function enroll(
  admin: RealSession,
  student: string,
  year: YearInfo,
  structure: Structure,
  group: string,
): Promise<string> {
  return (
    await admin.json('POST', 'enrollments', 201, {
      student,
      academic_year: year.id,
      course: structure.course,
      group,
      enrolled_on: year.startsOn,
    })
  ).public_id as string
}

type StorageState = Awaited<ReturnType<ReturnType<Browser['contexts']>[number]['storageState']>>

/**
 * Estado de sesión de navegador a partir de una sesión HTTP real (cookie de sesión y CSRF de la API real, con el dominio del centro
 * en la SPA): la API limita los intentos de acceso (429) y cada actor entra una sola vez. La pantalla de acceso la ejercitan
 * `fam-unit-pickup-real.spec.ts` y `prl-door-real.spec.ts`.
 */
export async function browserState(session: RealSession): Promise<StorageState> {
  const host = new URL(BASE_URL).hostname
  const { cookies } = await session.ctx.storageState()

  return { cookies: cookies.map((cookie) => ({ ...cookie, domain: host })), origins: [] }
}

export async function loginState(email: string, password: string): Promise<StorageState> {
  const session = await RealSession.login(centreA.slug, email, password)
  const state = await browserState(session)

  await session.dispose()

  return state
}

/** Una página ya autenticada con el estado de `loginState`; el contexto se cierra con `closeAll`. */
const opened: Array<{ close: () => Promise<void> }> = []

export async function pageAs(browser: Browser, state: StorageState): Promise<Page> {
  const context = await browser.newContext({ baseURL: BASE_URL, storageState: state })

  opened.push(context)

  return context.newPage()
}

export async function closeAll(): Promise<void> {
  await Promise.all(opened.splice(0).map((context) => context.close()))
}
