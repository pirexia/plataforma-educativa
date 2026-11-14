/* eslint-disable @typescript-eslint/no-explicit-any -- cuerpos JSON de la API real, sin tipar a propósito: el test comprueba el contrato, no lo importa. */
import {
  test,
  expect,
  request as playwrightRequest,
  type APIRequestContext,
  type APIResponse,
} from '@playwright/test'

/**
 * `docs/modulos/REQ-ALUM/funcional.md §11.1` (1.15-a): alumnado **contra la API real en ejecución** (HTTP real con cookie de
 * sesión y CSRF, sin navegador: las pantallas son de 1.15-d; sin S3). Cubre CA-ALUM-001 (alta con `Idempotency-Key`, ficha, lista,
 * PATCH, baja y restauración), CA-ALUM-009 (`person_exists`), CA-ALUM-012 (tutor con ámbito `unidad_familiar`: solo su alumno,
 * 404 al otro, 404 tras revocar el vínculo), CA-ALUM-014a (el tutor no escribe: 403) y CA-ALUM-018a (public_id de otro centro: 404).
 *
 * Solo con `E2E_REAL_API=1`: `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh) prepara los dos centros sintéticos
 * (`@example.com`, nombres explícitamente ficticios, `REQ-SEED-005`) y define las variables. Cada centro se alcanza por
 * `127.0.0.1:8000` con la cabecera `Host` de su subdominio (ADR-014).
 */

const API = process.env.E2E_API_URL ?? 'http://127.0.0.1:8000/api/v1'
const BASE_DOMAIN = process.env.E2E_BASE_DOMAIN ?? 'plataforma.test'

interface Centre {
  slug: string
  adminEmail: string
  adminPassword: string
  tutorEmail: string
  tutorUser: string
  staffPassword: string
}

function centre(prefix: 'A' | 'B'): Centre {
  const env = (name: string) => process.env[`E2E_${prefix}_${name}`] ?? ''

  return {
    slug: env('SLUG'),
    adminEmail: env('ADMIN_EMAIL'),
    adminPassword: env('ADMIN_PASSWORD'),
    tutorEmail: env('TUTOR_EMAIL'),
    tutorUser: env('TUTOR_USER'),
    staffPassword: env('STAFF_PASSWORD'),
  }
}

/** Un cliente HTTP con su propia cookie de sesión y su token CSRF, en el centro `slug`. */
class Session {
  private constructor(
    readonly ctx: APIRequestContext,
    readonly slug: string,
  ) {}

  static async login(slug: string, email: string, password: string): Promise<Session> {
    const ctx = await playwrightRequest.newContext({
      baseURL: `${API}/`,
      extraHTTPHeaders: { Host: `${slug}.${BASE_DOMAIN}:8000`, Accept: 'application/json' },
    })
    const session = new Session(ctx, slug)

    await ctx.get('auth/csrf-cookie')
    const response = await session.send('POST', 'auth/session', { data: { email, password } })
    expect(
      response.status(),
      `login de ${email} en ${slug}: ${await response.text()}`,
    ).toBeLessThan(300)

    return session
  }

  private async xsrf(): Promise<string> {
    const cookies = (await this.ctx.storageState()).cookies
    const token = cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? ''

    return decodeURIComponent(token)
  }

  async send(
    method: string,
    path: string,
    options: {
      data?: unknown
      multipart?: Record<string, unknown>
      headers?: Record<string, string>
    } = {},
  ): Promise<APIResponse> {
    const headers: Record<string, string> = { ...(options.headers ?? {}) }

    if (method !== 'GET') {
      headers['X-XSRF-TOKEN'] = await this.xsrf()
    }

    return this.ctx.fetch(path, {
      method,
      headers,
      data: options.data as never,
      multipart: options.multipart as never,
    })
  }

  async json<T = Record<string, any>>(
    method: string,
    path: string,
    expected: number,
    options: {
      data?: unknown
      multipart?: Record<string, unknown>
      headers?: Record<string, string>
    } = {},
  ): Promise<T> {
    const response = await this.send(method, path, options)
    const text = await response.text()
    expect(response.status(), `${method} ${path} → ${response.status()}: ${text}`).toBe(expected)

    return (text === '' ? {} : JSON.parse(text)) as T
  }

  async dispose(): Promise<void> {
    await this.ctx.dispose()
  }
}

/** DNI ficticio (número alto reservado a pruebas) con la letra de control que exige `Core`. */
function dni(number: number): string {
  const n = String(number).padStart(8, '0')

  return n + 'TRWAGMYFPDXBNJZSQVHLCKE'[number % 23]
}

const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'

/** ULID válido (48 bits de tiempo + 80 de azar, Crockford): la `Idempotency-Key` de `POST /students` (RN-ALUM-27). */
function ulid(): string {
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

const idem = () => ({ 'Idempotency-Key': ulid() })

test.describe('REQ-ALUM-001 (1.15-a): alumnado contra la API real', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real y dos centros de pruebas: ejecútalo con `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).',
  )

  test.describe.configure({ mode: 'serial' })

  const a = centre('A')
  const b = centre('B')
  let admin: Session
  let tutor: Session
  let adminB: Session

  test.beforeAll(async () => {
    admin = await Session.login(a.slug, a.adminEmail, a.adminPassword)
    tutor = await Session.login(a.slug, a.tutorEmail, a.staffPassword)
    adminB = await Session.login(b.slug, b.adminEmail, b.adminPassword)
  })

  test.afterAll(async () => {
    await Promise.all([admin, tutor, adminB].map((s) => s?.dispose()))
  })

  test('CA-ALUM-001 CA-ALUM-009: ciclo de vida del alumno (alta idempotente, ficha enmascarada, lista, PATCH, baja, restauración) y person_exists', async () => {
    const document = dni(99000001)
    const key = idem()
    const body = {
      given_name: 'Lucía',
      family_name_1: 'Ficticia',
      family_name_2: 'Alumna',
      birth_date: '2015-04-12',
      document_type: 'dni',
      document_number: document,
    }

    const response = await admin.send('POST', 'students', { data: body, headers: key })
    expect(response.status(), await response.text()).toBe(201)
    const created = await response.json()
    expect(response.headers()['location']).toContain(`/api/v1/students/${created.public_id}`)
    expect(created.document_number).toBe('•'.repeat(5) + document.slice(-4))
    expect(created.document_number).not.toContain(document)
    expect(created.birth_date).toBe('2015-04-12')
    expect(created.locale).toBeTruthy()

    // Repetir la misma petición con la misma clave no crea otro alumno (RN-ALUM-27).
    const replay = await admin.send('POST', 'students', { data: body, headers: key })
    expect(replay.status(), await replay.text()).toBe(201)
    expect((await replay.json()).public_id).toBe(created.public_id)

    // CA-ALUM-009: el mismo documento con otra clave → 422 person_exists.
    const dup = await admin.send('POST', 'students', {
      data: { ...body, given_name: 'Otra' },
      headers: idem(),
    })
    expect(dup.status()).toBe(422)
    const problem = await dup.json()
    expect(JSON.stringify(problem)).toContain('alum.validation.person_exists')

    // Alta sin clave de idempotencia: rechazada.
    const noKey = await admin.send('POST', 'students', {
      data: { ...body, document_number: undefined, document_type: undefined },
    })
    expect(noKey.status()).toBeGreaterThanOrEqual(400)
    expect(noKey.status()).toBeLessThan(500)

    const detail = await admin.json('GET', `students/${created.public_id}`, 200)
    expect(detail.given_name).toBe('Lucía')
    expect(detail.document_number).toBe(created.document_number)

    const list = await admin.json('GET', 'students', 200)
    expect(list.data.map((s: any) => s.public_id)).toContain(created.public_id)

    const patched = await admin.json('PATCH', `students/${created.public_id}`, 200, {
      data: { given_name: 'Lucía María', birth_date: '2015-05-01' },
    })
    expect(patched.given_name).toBe('Lucía María')
    expect(patched.birth_date).toBe('2015-05-01')

    const del = await admin.send('DELETE', `students/${created.public_id}`)
    expect(del.status(), await del.text()).toBeLessThan(300)
    await admin.json('GET', `students/${created.public_id}`, 404)
    expect(
      ((await admin.json('GET', 'students', 200)).data as any[]).map((s) => s.public_id),
    ).not.toContain(created.public_id)

    const restored = await admin.send('POST', `students/${created.public_id}/restore`)
    expect(restored.status(), await restored.text()).toBe(200)
    await admin.json('GET', `students/${created.public_id}`, 200)
  })

  test('CA-ALUM-012 CA-ALUM-014a: el tutor ve solo al alumno con vínculo activo, no escribe, y deja de verlo al revocar el vínculo', async () => {
    const unit = (await admin.json('POST', 'family-units', 201, { data: {} })).public_id as string
    const mk = async (family: string) =>
      admin.json('POST', 'students', 201, {
        data: {
          given_name: 'Alumno',
          family_name_1: family,
          birth_date: '2016-02-02',
          family_unit: unit,
        },
        headers: idem(),
      })
    const a1 = await mk('Tutelado')
    const a2 = await mk('Ajeno')

    const guardian = (
      await admin.json('POST', 'guardians', 201, { data: { user: a.tutorUser, family_unit: unit } })
    ).public_id as string
    const link = (
      await admin.json('POST', 'guardian-links', 201, {
        data: {
          guardian,
          student_person: a1.person.public_id,
          relationship: 'padre',
          is_legal_guardian: true,
        },
      })
    ).public_id as string

    const list = await tutor.json('GET', 'students', 200)
    expect(list.data.map((s: any) => s.public_id)).toEqual([a1.public_id])
    expect(list.meta.total).toBe(1)

    await tutor.json('GET', `students/${a1.public_id}`, 200)
    await tutor.json('GET', `students/${a2.public_id}`, 404)

    // CA-ALUM-014a: el tutor no tiene alumno.crear / actualizar / eliminar.
    expect(
      (
        await tutor.send('POST', 'students', {
          data: { given_name: 'X', family_name_1: 'Y', birth_date: '2016-01-01' },
          headers: idem(),
        })
      ).status(),
    ).toBe(403)
    expect(
      (
        await tutor.send('PATCH', `students/${a1.public_id}`, { data: { given_name: 'X' } })
      ).status(),
    ).toBe(403)
    expect((await tutor.send('DELETE', `students/${a1.public_id}`)).status()).toBe(403)
    expect((await tutor.send('POST', `students/${a1.public_id}/restore`)).status()).toBe(403)

    // Revocación vía API: la petición siguiente ya no ve al alumno.
    await admin.json('POST', `guardian-links/${link}/revocation`, 200, {
      data: { reason: 'resolucion_judicial' },
    })
    await tutor.json('GET', `students/${a1.public_id}`, 404)
    const after = await tutor.json('GET', 'students', 200)
    expect(after.data).toEqual([])
    expect(after.meta.total).toBe(0)
  })

  test('CA-ALUM-018a INV-001: un public_id de otro centro es 404 en lectura y escritura, y no aparece en la lista', async () => {
    const own = await admin.json('POST', 'students', 201, {
      data: { given_name: 'Centro', family_name_1: 'Uno', birth_date: '2015-01-01' },
      headers: idem(),
    })
    const other = await adminB.json('POST', 'students', 201, {
      data: { given_name: 'Centro', family_name_1: 'Dos', birth_date: '2015-01-01' },
      headers: idem(),
    })

    await admin.json('GET', `students/${other.public_id}`, 404)
    expect(
      (
        await admin.send('PATCH', `students/${other.public_id}`, { data: { given_name: 'X' } })
      ).status(),
    ).toBe(404)
    expect((await admin.send('DELETE', `students/${other.public_id}`)).status()).toBe(404)
    expect((await admin.send('POST', `students/${other.public_id}/restore`)).status()).toBe(404)
    await adminB.json('GET', `students/${own.public_id}`, 404)

    const listA = (await admin.json('GET', 'students', 200)).data.map((s: any) => s.public_id)
    expect(listA).not.toContain(other.public_id)
    expect(listA).toContain(own.public_id)
    // El alumno de B sigue intacto.
    expect((await adminB.json('GET', `students/${other.public_id}`, 200)).given_name).toBe('Centro')
  })
})
