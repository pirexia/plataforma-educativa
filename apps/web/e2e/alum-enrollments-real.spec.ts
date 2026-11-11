/* eslint-disable @typescript-eslint/no-explicit-any -- cuerpos JSON de la API real, sin tipar a propósito: el test comprueba el contrato, no lo importa. */
import {
  test,
  expect,
  request as playwrightRequest,
  type APIRequestContext,
  type APIResponse,
} from '@playwright/test'

/**
 * `docs/modulos/REQ-ALUM/funcional.md §11.3` y `§11.4` (1.15-b): matrícula y exportación **contra la API real en ejecución** (HTTP
 * real con cookie de sesión y CSRF, sin navegador: las pantallas son de 1.15-d). Cubre CA-ALUM-020 (alta, repetición 422),
 * CA-ALUM-025/028 (PATCH de fecha, cambio de grupo 422), CA-ALUM-024 (baja, reintento 409), CA-ALUM-031 (anulación y nueva
 * matrícula), CA-ALUM-026 (`enrollments[]` en la ficha y filtros de `GET /students`), CA-ALUM-040 (lista de clase),
 * CA-ALUM-041/042 (exportación 202, 403 sin permiso y, si el entorno lo permite, descarga del CSV) y CA-ALUM-018b (otro centro: 404).
 *
 * Solo con `E2E_REAL_API=1`: `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh) prepara los dos centros sintéticos
 * (`@example.com`, nombres explícitamente ficticios, `REQ-SEED-005`) y define las variables. La estructura (curso académico,
 * etapa, curso y grupos) y el alumnado los crea el propio spec por la API. La exportación se genera en cola: la descarga solo se
 * comprueba si el *worker* y el almacenamiento S3/MinIO responden dentro del plazo; si no, el spec comprueba el `202` y el estado
 * de la exportación y lo deja como anotación del test (no declara probada la descarga).
 */
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

const uniq = () => String(Date.now()).slice(-8)

test.describe('REQ-ALUM-002 (1.15-b): matrícula y exportación contra la API real', () => {
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
  let year = ''
  let course = ''
  let groupA = ''
  let groupB = ''
  const students: Array<{ public_id: string; person: { public_id: string } }> = []

  test.beforeAll(async () => {
    admin = await Session.login(a.slug, a.adminEmail, a.adminPassword)
    tutor = await Session.login(a.slug, a.tutorEmail, a.staffPassword)
    adminB = await Session.login(b.slug, b.adminEmail, b.adminPassword)

    // Estructura mínima por la API: curso académico activo, etapa, curso de etapa y dos grupos.
    year = (
      await admin.json('POST', 'academic-years', 201, {
        data: { code: `E2E-${uniq()}`, starts_on: '2026-09-01', ends_on: '2027-06-30' },
      })
    ).public_id as string
    await admin.json('POST', `academic-years/${year}/status`, 200, { data: { status: 'activo' } })
    const level = (
      await admin.json('POST', 'education-levels', 201, {
        data: {
          academic_year: year,
          code: 'ESO',
          name_translations: { 'es-ES': 'Etapa ficticia' },
          stage: 'eso',
          regime: 'concertado',
        },
      })
    ).public_id as string
    course = (
      await admin.json('POST', 'courses', 201, {
        data: { education_level: level, code: '1ESO', name_translations: { 'es-ES': 'Primero' } },
      })
    ).public_id as string
    groupA = (
      await admin.json('POST', 'groups', 201, { data: { course, code: 'A', name: 'Grupo A' } })
    ).public_id as string
    groupB = (
      await admin.json('POST', 'groups', 201, { data: { course, code: 'B', name: 'Grupo B' } })
    ).public_id as string

    for (const family of ['Uno', 'Dos', 'Tres']) {
      students.push(
        (await admin.json('POST', 'students', 201, {
          data: { given_name: 'Alumno', family_name_1: family, birth_date: '2015-04-12' },
          headers: idem(),
        })) as never,
      )
    }
  })

  test.afterAll(async () => {
    await Promise.all([admin, tutor, adminB].map((s) => s?.dispose()))
  })

  const enroll = (index: number, group: string | null = groupA, enrolledOn = '2026-09-10') => ({
    student: students[index].public_id,
    academic_year: year,
    course,
    group,
    enrolled_on: enrolledOn,
  })

  let enrollmentOne = ''
  let enrollmentTwo = ''

  test('CA-ALUM-020 CA-ALUM-021: alta de matrícula (201 con Location), repetición 422 already_enrolled y grupo ajeno al curso 422', async () => {
    const response = await admin.send('POST', 'enrollments', { data: enroll(0) })
    expect(response.status(), await response.text()).toBe(201)
    const created = await response.json()
    enrollmentOne = created.public_id
    expect(response.headers()['location']).toContain(`/api/v1/enrollments/${enrollmentOne}`)
    expect(created.status).toBe('activa')
    expect(created.group.name).toBe('Grupo A')
    expect(created.student.public_id).toBe(students[0].public_id)

    const again = await admin.send('POST', 'enrollments', { data: enroll(0) })
    expect(again.status()).toBe(422)
    expect(JSON.stringify(await again.json())).toContain('alum.validation.already_enrolled')

    enrollmentTwo = (await admin.json('POST', 'enrollments', 201, { data: enroll(1, groupB) }))
      .public_id as string
    await admin.json('POST', 'enrollments', 201, { data: enroll(2, null) })
  })

  test('CA-ALUM-040 CA-ALUM-016: lista de clase por grupo y por estado, y detalle', async () => {
    const all = await admin.json('GET', `enrollments?academic_year=${year}`, 200)
    expect(all.data).toHaveLength(3)

    const inA = await admin.json(
      'GET',
      `enrollments?academic_year=${year}&group=${groupA}&status=activa`,
      200,
    )
    expect(inA.data.map((e: any) => e.public_id)).toEqual([enrollmentOne])

    expect((await admin.json('GET', `enrollments/${enrollmentOne}`, 200)).public_id).toBe(
      enrollmentOne,
    )

    // `academic_year` es obligatorio (422) y uno inexistente es 404.
    expect((await admin.send('GET', 'enrollments')).status()).toBe(422)
    expect(
      (await admin.send('GET', 'enrollments?academic_year=01JZZZZZZZZZZZZZZZZZZZZZZZ')).status(),
    ).toBe(404)
  })

  test('CA-ALUM-025 CA-ALUM-028: PATCH de la fecha de matrícula; cambiar un grupo ya asignado es 422 y los campos inmutables también', async () => {
    const patched = await admin.json('PATCH', `enrollments/${enrollmentOne}`, 200, {
      data: { enrolled_on: '2026-09-12' },
    })
    expect(patched.enrolled_on).toBe('2026-09-12')

    const change = await admin.send('PATCH', `enrollments/${enrollmentOne}`, {
      data: { group: groupB },
    })
    expect(change.status()).toBe(422)
    expect(JSON.stringify(await change.json())).toContain(
      'alum.validation.group_change_requires_request',
    )

    const immutable = await admin.send('PATCH', `enrollments/${enrollmentOne}`, {
      data: { course },
    })
    expect(immutable.status()).toBe(422)
    expect(JSON.stringify(await immutable.json())).toContain('alum.validation.immutable')

    // Fuera del curso académico: 422.
    expect(
      (
        await admin.send('PATCH', `enrollments/${enrollmentOne}`, {
          data: { enrolled_on: '2025-01-01' },
        })
      ).status(),
    ).toBe(422)
  })

  test('CA-ALUM-026: la ficha trae enrollments[] y GET /students filtra por group, course y enrollment_status', async () => {
    const detail = await admin.json('GET', `students/${students[0].public_id}`, 200)
    expect(detail.enrollments.map((e: any) => e.public_id)).toEqual([enrollmentOne])

    const ids = async (query: string) =>
      ((await admin.json('GET', `students?academic_year=${year}${query}`, 200)).data as any[])
        .map((s) => s.public_id)
        .sort()

    expect(await ids(`&group=${groupB}`)).toEqual([students[1].public_id])
    expect(await ids(`&course=${course}&enrollment_status=activa`)).toEqual(
      [students[0].public_id, students[1].public_id, students[2].public_id].sort(),
    )
    expect(await ids('&enrollment_status=baja')).toEqual([])

    // El listado trae la matrícula vigente del curso pedido.
    const row = (
      (await admin.json('GET', `students?academic_year=${year}`, 200)).data as any[]
    ).find((s) => s.public_id === students[0].public_id)
    expect(row.enrollment?.public_id).toBe(enrollmentOne)
  })

  test('CA-ALUM-024 CA-ALUM-034: baja con fecha y motivo, reintento 409 already_withdrawn y filtro por estado', async () => {
    const bad = await admin.send('POST', `enrollments/${enrollmentTwo}/withdrawal`, {
      data: { withdrawn_on: '2026-09-01', withdrawal_reason: 'traslado' },
    })
    expect(bad.status()).toBe(422)

    const done = await admin.json('POST', `enrollments/${enrollmentTwo}/withdrawal`, 200, {
      data: { withdrawn_on: '2026-10-01', withdrawal_reason: 'traslado' },
    })
    expect(done.status).toBe('baja')
    expect(done.withdrawn_on).toBe('2026-10-01')

    const retry = await admin.send('POST', `enrollments/${enrollmentTwo}/withdrawal`, {
      data: { withdrawn_on: '2026-10-01', withdrawal_reason: 'traslado' },
    })
    expect(retry.status()).toBe(409)
    expect(JSON.stringify(await retry.json())).toContain('alum.conflict.already_withdrawn')

    const bajas = await admin.json('GET', `enrollments?academic_year=${year}&status=baja`, 200)
    expect(bajas.data.map((e: any) => e.public_id)).toEqual([enrollmentTwo])
  })

  test('CA-ALUM-031 CA-ALUM-004: anulación de una matrícula (204), nueva matrícula posterior y baja de alumno con matrícula activa 409', async () => {
    const third = (
      await admin.json('GET', `enrollments?academic_year=${year}&status=activa`, 200)
    ).data.find((e: any) => e.student.public_id === students[2].public_id).public_id as string

    // Un alumno con matrícula activa no se da de baja (RN-ALUM-05).
    const del = await admin.send('DELETE', `students/${students[2].public_id}`)
    expect(del.status()).toBe(409)
    expect(JSON.stringify(await del.json())).toContain('alum.conflict.has_active_enrollments')

    expect((await admin.send('DELETE', `enrollments/${third}`)).status()).toBe(204)
    await admin.json('GET', `enrollments/${third}`, 404)
    await admin.json('POST', 'enrollments', 201, { data: enroll(2, groupA) })
  })

  test('CA-ALUM-041 CA-ALUM-042: exportación 202, 403 sin permiso, 422 con q y, si el entorno lo permite, CSV descargable con el esquema cerrado', async () => {
    // Sin matricula.exportar (el tutor legal): 403. Con q: 422.
    expect(
      (await tutor.send('POST', 'enrollments/exports', { data: { academic_year: year } })).status(),
    ).toBe(403)
    const q = await admin.send('POST', 'enrollments/exports', {
      data: { academic_year: year, q: 'Uno' },
    })
    expect(q.status()).toBe(422)
    expect(JSON.stringify(await q.json())).toContain('alum.validation.export_query_not_allowed')

    const accepted = await admin.send('POST', 'enrollments/exports', {
      data: { academic_year: year },
    })
    expect(accepted.status(), await accepted.text()).toBe(202)
    const exportId = (await accepted.json()).public_id as string

    // CA-ALUM-018b: la exportación de otro centro no existe.
    expect((await adminB.send('GET', `data-exports/${exportId}`)).status()).toBe(404)

    // El trabajo va en cola: se espera hasta 30 s a que el worker lo termine.
    let status = 'pendiente'
    let downloadUrl = ''
    for (let i = 0; i < 15; i++) {
      const response = await admin.send('GET', `data-exports/${exportId}`)

      if (response.status() === 200) {
        const body = await response.json()
        status = body.status
        downloadUrl = body.download_url ?? ''
        break
      }

      expect(response.status(), 'export aún no listo: 409').toBe(409)
      await new Promise((resolve) => setTimeout(resolve, 2000))
    }

    expect(status, 'la exportación no debe quedar fallida').not.toBe('fallida')

    if (status !== 'completada') {
      test.info().annotations.push({
        type: 'no-probado',
        description: `Exportación aceptada (202) pero no terminada en 30 s (estado ${status}): ¿worker parado? La descarga del CSV NO se ha comprobado.`,
      })

      return
    }

    // La URL firmada apunta al almacenamiento S3/MinIO; puede no ser alcanzable desde el host del test.
    let csv: string | null = null
    try {
      const download = await playwrightRequest.newContext()
      const file = await download.get(downloadUrl)
      csv = file.ok() ? await file.text() : null
      await download.dispose()
    } catch {
      csv = null
    }

    if (csv === null) {
      test.info().annotations.push({
        type: 'no-probado',
        description:
          'Exportación completada, pero la URL firmada no es alcanzable desde el test (S3/MinIO): el contenido del CSV NO se ha comprobado.',
      })

      return
    }

    const lines = csv
      .replace(/^\uFEFF/, '')
      .trim()
      .split(/\r?\n/)
    expect(lines[0]).toBe(
      'enrollment_public_id,student_public_id,family_name,given_name,birth_date,academic_year_code,course_code,group_name,status,enrolled_on,withdrawn_on,withdrawal_reason',
    )
    expect(lines).toHaveLength(1 + 3)
    expect(csv).not.toContain('document')
  })

  test('CA-ALUM-018b INV-001: un public_id de matrícula, curso o grupo de otro centro es 404/422 y no aparece en la lista del otro centro', async () => {
    // El otro centro no ve la matrícula ni puede editarla, darla de baja o anularla.
    await adminB.json('GET', `enrollments/${enrollmentOne}`, 404)
    expect(
      (
        await adminB.send('PATCH', `enrollments/${enrollmentOne}`, {
          data: { enrolled_on: '2026-09-13' },
        })
      ).status(),
    ).toBe(404)
    expect(
      (
        await adminB.send('POST', `enrollments/${enrollmentOne}/withdrawal`, {
          data: { withdrawn_on: '2026-10-02', withdrawal_reason: 'otro' },
        })
      ).status(),
    ).toBe(404)
    expect((await adminB.send('DELETE', `enrollments/${enrollmentOne}`)).status()).toBe(404)

    // Un curso académico del otro centro es 404 al listar.
    expect((await adminB.send('GET', `enrollments?academic_year=${year}`)).status()).toBe(404)

    // Matricular a un alumno propio en una estructura ajena es 422 sin revelar nada.
    const own = await adminB.json('POST', 'students', 201, {
      data: { given_name: 'Centro', family_name_1: 'Dos', birth_date: '2015-01-01' },
      headers: idem(),
    })
    const foreign = await adminB.send('POST', 'enrollments', {
      data: {
        student: own.public_id,
        academic_year: year,
        course,
        group: groupA,
        enrolled_on: '2026-09-10',
      },
    })
    expect(foreign.status()).toBe(422)

    // La matrícula de A sigue intacta.
    expect((await admin.json('GET', `enrollments/${enrollmentOne}`, 200)).enrolled_on).toBe(
      '2026-09-12',
    )
  })
})
