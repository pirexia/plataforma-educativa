/* eslint-disable @typescript-eslint/no-explicit-any -- cuerpos JSON de la API real, sin tipar a propósito: el test comprueba el contrato, no lo importa. */
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import {
  test,
  expect,
  request as playwrightRequest,
  type APIRequestContext,
  type APIResponse,
} from '@playwright/test'

/**
 * `docs/modulos/REQ-PRL/operacion.md §12.7`, `REQ-PRL-004` (1.14b-b): el proceso de puerta y entrega de menores **contra la API
 * real en ejecución** (HTTP real, sin cliente de test de Laravel y sin navegador: las pantallas son de `1.14b-c`). Cubre, de
 * punta a punta y con cookie de sesión + CSRF: alta de la unidad familiar, tutores y alumno; alta de una persona de recogida,
 * su autorización y su fotografía (orden persona → autorización → foto, `PUT … /photo` multipart); búsqueda del alumno; lista de
 * puerta (foto firmada, sin metadatos); salida verificada por foto; persona que no figura idéntica a persona excluida por
 * revocación; alerta y su resolución; idempotencia; y aislamiento entre dos centros.
 *
 * Solo con `E2E_REAL_API=1`: `npm run test:e2e:real:prl` (apps/web/scripts/e2e-real-prl.sh) prepara los dos centros sintéticos
 * (`@example.com`, nombres explícitamente ficticios, documentos con dígito de control inválido, `REQ-SEED-005`) y un almacén
 * S3 (en desarrollo, el servidor falso de `apps/api/tests/Support/e2e-fake-s3.php` si no hay MinIO) y define las variables.
 *
 * Los dos centros se alcanzan por `127.0.0.1:8000` con la cabecera `Host` de su subdominio (ADR-014), de modo que no hace falta
 * una entrada de `hosts` por centro.
 */

const API = process.env.E2E_API_URL ?? 'http://127.0.0.1:8000/api/v1'
const S3_PUBLIC = process.env.E2E_S3_PUBLIC_URL ?? 'http://127.0.0.1:9000'
const S3_INTERNAL = process.env.E2E_S3_INTERNAL_URL ?? 'http://minio:9000'
const BASE_DOMAIN = process.env.E2E_BASE_DOMAIN ?? 'plataforma.test'

interface Centre {
  slug: string
  adminEmail: string
  adminPassword: string
  conserjeEmail: string
  secretariaEmail: string
  staffPassword: string
  students: [string, string]
}

function centre(prefix: 'A' | 'B'): Centre {
  const env = (name: string) => process.env[`E2E_${prefix}_${name}`] ?? ''

  return {
    slug: env('SLUG'),
    adminEmail: env('ADMIN_EMAIL'),
    adminPassword: env('ADMIN_PASSWORD'),
    conserjeEmail: env('CONSERJE_EMAIL'),
    secretariaEmail: env('SECRETARIA_EMAIL'),
    staffPassword: env('STAFF_PASSWORD'),
    students: [env('STUDENT_1'), env('STUDENT_2')],
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

/** El cuerpo de un problema sin lo que cambia en cada petición. */
function stableProblem(problem: Record<string, any>): Record<string, unknown> {
  const rest = { ...problem }

  for (const volatile of ['request_id', 'instance', 'trace_id']) {
    delete rest[volatile]
  }

  return rest
}

test.describe('REQ-PRL-004 (1.14b-b): puerta y entrega de menores contra la API real', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real y dos centros de pruebas: ejecútalo con `npm run test:e2e:real:prl` (apps/web/scripts/e2e-real-prl.sh).',
  )

  test.describe.configure({ mode: 'serial' })

  const a = centre('A')
  const b = centre('B')
  let admin: Session
  let secretaria: Session
  let conserje: Session
  let adminB: Session
  let conserjeB: Session

  test.beforeAll(async () => {
    admin = await Session.login(a.slug, a.adminEmail, a.adminPassword)
    secretaria = await Session.login(a.slug, a.secretariaEmail, a.staffPassword)
    conserje = await Session.login(a.slug, a.conserjeEmail, a.staffPassword)
    adminB = await Session.login(b.slug, b.adminEmail, b.adminPassword)
    conserjeB = await Session.login(b.slug, b.conserjeEmail, b.staffPassword)
  })

  test.afterAll(async () => {
    await Promise.all([admin, secretaria, conserje, adminB, conserjeB].map((s) => s?.dispose()))
  })

  test('CA-PRL-035 (REQ-PRL-004): recorrido completo de la puerta: familia, tercero con foto, salida verificada, persona que no figura, alerta, idempotencia', async () => {
    // ── 1. Familia: alumno, dos tutores (la madre es la principal), texto legal publicado ──
    const unit = (await admin.json('POST', 'family-units', 201, { data: {} })).public_id as string
    await admin.json('POST', `family-units/${unit}/students`, 201, {
      data: { student_person: a.students[0] },
    })

    const guardians: Record<string, { guardian: string; link: string; person: string }> = {}

    for (const [key, given, relationship, legal] of [
      ['madre', 'Marta', 'madre', true],
      ['padre', 'Pablo', 'padre', false],
    ] as const) {
      const user = await admin.json('POST', 'users', 201, {
        data: {
          email: `e2e-${key}@example.com`,
          person: {
            given_name: given,
            family_name_1: 'Ficticia',
            contact_phone: key === 'madre' ? '600111222' : '600333444',
          },
          send_invitation: false,
          role_ids: [],
        },
      })
      const guardian = (
        await admin.json('POST', 'guardians', 201, {
          data: { user: user.public_id, family_unit: unit },
        })
      ).public_id as string
      const link = (
        await admin.json('POST', 'guardian-links', 201, {
          data: { guardian, student_person: a.students[0], relationship, is_legal_guardian: legal },
        })
      ).public_id as string
      guardians[key] = { guardian, link, person: user.person.public_id as string }
    }

    await admin.json('PATCH', `family-units/${unit}`, 200, {
      data: { principal_guardian: guardians.madre!.guardian },
    })
    await admin.json('PATCH', `guardian-links/${guardians.padre!.link}`, 200, {
      data: { is_emergency_contact: true },
    })

    const text = (
      await admin.json('POST', 'terms-versions', 201, {
        data: {
          kind: 'autorizacion_recogida',
          title_translations: { 'es-ES': 'Autorización de recogida' },
          body_translations: {
            'es-ES': 'Autorizo a la persona indicada a recoger a mi hijo o hija.',
          },
        },
      })
    ).public_id as string
    await admin.json('POST', `terms-versions/${text}/publish`, 200)

    // ── 2. Secretaría: persona → autorización → fotografía (OPEN-FAM-UNIT-48). Documento con dígito de control inválido (REQ-SEED-005). ──
    const abuelo = await secretaria.json('POST', 'pickup-persons', 201, {
      data: {
        given_name: 'Abuelo',
        family_name_1: 'Ficticio',
        document_type: 'pasaporte',
        document_number: 'E2EFICT0001',
        contact_phone: '600555666',
      },
    })
    const authorization = (await secretaria.json('POST', 'pickup-authorizations', 201, {
      data: {
        student_person: a.students[0],
        person: abuelo.person,
        relationship: 'abuelo',
        kind: 'permanente',
        valid_from: '2020-01-01',
        valid_until: null,
        authorized_by: guardians.madre!.guardian,
        terms_version: text,
        channel: 'presencial',
      },
    })) as Record<string, any>
    expect(authorization.is_effective).toBe(false)
    expect(authorization.ineffective_reason).toBe('sin_foto')

    // Sin fotografía el tercero NO figura en la puerta (RN-FAM-UNIT-84).
    const searched = await conserje.json('GET', 'door/students?q=Luc', 200)
    expect(searched.data).toHaveLength(1)
    const student = searched.data[0] as {
      student_person: string
      display_name: string
      family_unit_code: string | null
    }
    expect(student.student_person).toBe(a.students[0])
    expect(student.display_name).toContain('Lucía')

    const before = await conserje.json(
      'GET',
      `door/students/${student.student_person}/pickup-list`,
      200,
    )
    expect((before.data as Array<{ person: string }>).map((e) => e.person)).not.toContain(
      abuelo.person,
    )
    expect(before.data).toHaveLength(2)

    // Subida multipart de la fotografía (con un APP1 EXIF sintético que el servidor debe eliminar).
    const photo = readFileSync(join(process.cwd(), 'e2e', 'fixtures', 'pickup-photo-e2e.jpg'))
    expect(photo.includes('FAKEGPS-E2E')).toBe(true)
    await secretaria.json('PUT', `pickup-persons/${abuelo.person}/photo`, 200, {
      multipart: { file: { name: 'foto.jpg', mimeType: 'image/jpeg', buffer: photo } },
    })

    // ── 3. Puerta: la lista incluye al abuelo con foto firmada, teléfono y documento enmascarado ──
    const list = await conserje.json(
      'GET',
      `door/students/${student.student_person}/pickup-list`,
      200,
    )
    const entries = list.data as Array<Record<string, any>>
    const entry = entries.find((e) => e.person === abuelo.person)!

    expect(entries).toHaveLength(3)
    expect(entries.map((e) => e.bases[0].basis)).toEqual(['tutor', 'tutor', 'tercero'])
    expect(entry.has_photo).toBe(true)
    expect(entry.contact_phone).toBe('600555666')
    expect(entry.document.masked).toMatch(/^•+0001$/)
    expect(entry.document.masked).not.toContain('E2EFICT0001')
    expect(list.meta.protocol_available).toBe(true)

    // La URL firmada se descarga del almacén y la imagen guardada NO conserva los metadatos del original.
    const photoUrl = (entry.photo_url as string).replace(S3_INTERNAL, S3_PUBLIC)
    const downloaded = await playwrightRequest.newContext()
    const image = await downloaded.get(photoUrl)
    expect(image.status()).toBe(200)
    expect(image.headers()['content-type']).toContain('image/jpeg')
    const bytes = await image.body()
    expect(bytes.subarray(0, 2).toString('hex')).toBe('ffd8')
    expect(bytes.includes('FAKEGPS-E2E')).toBe(false)
    expect(bytes.includes('Exif')).toBe(false)
    await downloaded.dispose()

    // ── 4. Salida verificada por foto, con Idempotency-Key ──
    const body = {
      student_person: student.student_person,
      direction: 'salida',
      picked_up_by: abuelo.person,
      verification_method: 'foto',
    }
    const key = { 'Idempotency-Key': `e2e-${Date.now()}` }
    const exit = await conserje.json<Record<string, any>>('POST', 'student-movements', 201, {
      data: body,
      headers: key,
    })

    expect(exit).toMatchObject({
      direction: 'salida',
      basis: 'tercero',
      verification_method: 'foto',
      student_person: student.student_person,
    })
    expect(exit.picked_up_by.person).toBe(abuelo.person)

    const replay = await conserje.send('POST', 'student-movements', { data: body, headers: key })
    expect(replay.status()).toBe(201)
    expect(replay.headers()['idempotency-replayed']).toBe('true')
    expect((await replay.json()).public_id).toBe(exit.public_id)
    const mismatch = await conserje.send('POST', 'student-movements', {
      data: { ...body, verification_method: 'foto_y_documento' },
      headers: key,
    })
    expect(mismatch.status()).toBe(409)

    const history = await conserje.json(
      'GET',
      `student-movements?student_person=${student.student_person}`,
      200,
    )
    expect(history.data).toHaveLength(1)

    // Un movimiento no se modifica ni se borra (RN-PRL-04).
    expect(
      (
        await admin.send('PATCH', `student-movements/${exit.public_id}`, { data: { notes: 'x' } })
      ).status(),
    ).toBe(405)
    expect((await admin.send('DELETE', `student-movements/${exit.public_id}`)).status()).toBe(405)

    // Un tutor sin fotografía solo se verifica por documento (RN-PRL-06).
    const tutorWithPhoto = await conserje.send('POST', 'student-movements', {
      data: { ...body, picked_up_by: guardians.madre!.person, verification_method: 'foto' },
    })
    expect(tutorWithPhoto.status()).toBe(422)
    expect((await tutorWithPhoto.json()).errors.verification_method[0].code).toBe(
      'prl.movement.verification_requires_document',
    )
    await conserje.json('POST', 'student-movements', 201, {
      data: { ...body, picked_up_by: guardians.madre!.person, verification_method: 'documento' },
    })

    // Entrada fuera de horario: exige motivo (RN-PRL-07).
    const lateEntry = {
      student_person: student.student_person,
      direction: 'entrada',
      off_schedule: true,
    }
    expect((await conserje.send('POST', 'student-movements', { data: lateEntry })).status()).toBe(
      422,
    )
    await conserje.json('POST', 'student-movements', 201, {
      data: { ...lateEntry, off_schedule_reason: 'medico' },
    })

    // ── 5. Persona que no figura == persona excluida por revocación (CA-PRL-002, OPEN-FAM-UNIT-34) ──
    const stranger = await secretaria.json('POST', 'pickup-persons', 201, {
      data: {
        given_name: 'Vecino',
        family_name_1: 'Ficticio',
        document_type: 'pasaporte',
        document_number: 'E2EFICT0002',
      },
    })
    await admin.json('POST', `guardian-links/${guardians.padre!.link}/revocation`, 200, {
      data: { reason: 'resolucion_judicial', notes: null },
    })

    const notListed = await conserje.send('POST', 'student-movements', {
      data: { ...body, picked_up_by: stranger.person, verification_method: 'documento' },
    })
    const excluded = await conserje.send('POST', 'student-movements', {
      data: { ...body, picked_up_by: guardians.padre!.person, verification_method: 'documento' },
    })
    const unknown = await conserje.send('POST', 'student-movements', {
      data: {
        ...body,
        picked_up_by: '01JZZZZZZZZZZZZZZZZZZZZZZZ',
        verification_method: 'documento',
      },
    })

    expect(notListed.status()).toBe(422)
    expect(excluded.status()).toBe(422)
    expect(stableProblem(await excluded.json())).toEqual(stableProblem(await notListed.json()))
    expect(stableProblem(await unknown.json())).toEqual(stableProblem(await notListed.json()))
    expect((await notListed.json()).errors.picked_up_by[0].code).toBe(
      'prl.movement.person_not_authorized',
    )
    expect(
      (
        await conserje.json(
          'GET',
          `student-movements?student_person=${student.student_person}`,
          200,
        )
      ).data,
    ).toHaveLength(3)

    // Y la lista de puerta ya no incluye al padre (sin rastro de que esté excluido).
    const afterRevocation = await conserje.json(
      'GET',
      `door/students/${student.student_person}/pickup-list`,
      200,
    )
    expect((afterRevocation.data as Array<{ person: string }>).map((e) => e.person)).not.toContain(
      guardians.padre!.person,
    )
    expect(JSON.stringify(afterRevocation)).not.toMatch(/revoc/i)

    // ── 6. Alerta y su resolución ──
    const alert = await conserje.json<Record<string, any>>('POST', 'pickup-alerts', 201, {
      data: {
        student_person: student.student_person,
        presenter_description: 'Dice llamarse Carlos F., exhibe pasaporte E2EFICT0002',
      },
      headers: { 'Idempotency-Key': `e2e-alert-${Date.now()}` },
    })

    expect(alert.status).toBe('abierta')
    expect(alert.protocol).toEqual(expect.any(String))
    expect(alert.contacts).toEqual({ principal_phone: '600111222', emergency_phone: null })

    // Conserjería abre pero no cierra; secretaría solo lee.
    expect(
      (
        await conserje.send('PATCH', `pickup-alerts/${alert.public_id}`, {
          data: { resolution: 'no_entregado' },
        })
      ).status(),
    ).toBe(403)
    expect(
      (
        await secretaria.send('POST', 'pickup-alerts', {
          data: { student_person: student.student_person, presenter_description: 'x' },
        })
      ).status(),
    ).toBe(403)

    const called = await admin.json('PATCH', `pickup-alerts/${alert.public_id}`, 200, {
      data: { principal_contacted_at: alert.raised_at },
    })
    expect(called.status).toBe('abierta')
    const closed = await admin.json('PATCH', `pickup-alerts/${alert.public_id}`, 200, {
      data: { resolution: 'no_entregado', resolution_notes: 'El alumno esperó en dirección.' },
    })
    expect(closed).toMatchObject({ status: 'cerrada', resolution: 'no_entregado' })
    expect(closed.protocol).toBeUndefined()
    const reopen = await admin.send('PATCH', `pickup-alerts/${alert.public_id}`, {
      data: { resolution: 'otro' },
    })
    expect(reopen.status()).toBe(409)
    expect((await reopen.json()).errors.status[0].code).toBe('prl.alert.closed')

    const open = await admin.json('GET', 'pickup-alerts?status=abierta', 200)
    expect(open.data).toHaveLength(0)

    // ── 7. Auditoría: cada consulta de la lista dejó una fila `read` sobre la persona del alumno, sin valores (ADR-068 §5.4) ──
    // La API de auditoría no expone el `context` (finalidad, recuentos): la finalidad `puerta` la cubren los tests de Pest.
    const audit = await admin.json('GET', 'audit-logs?event=read&per_page=100', 200)
    const reads = (audit.data as Array<Record<string, any>>).filter(
      (row) =>
        row.auditable_type === 'person' && row.auditable_public_id === student.student_person,
    )

    expect(reads.length).toBeGreaterThanOrEqual(3) // las tres consultas de la lista de puerta de este recorrido
    expect(reads.every((row) => row.changes === null && row.actor_type === 'user')).toBe(true)
    expect(JSON.stringify(reads)).not.toContain('Abuelo')
    expect(JSON.stringify(reads)).not.toContain('600555666')
    expect(JSON.stringify(reads)).not.toContain('pickup-photos')

    // Registrar la salida, abrir y cerrar la alerta quedó auditado con política Selective.
    const movementAudit = await admin.json(
      'GET',
      'audit-logs?auditable_type=student_movement&per_page=100',
      200,
    )
    const alertAudit = await admin.json(
      'GET',
      'audit-logs?auditable_type=pickup_alert&per_page=100',
      200,
    )
    expect((movementAudit.data as unknown[]).length).toBeGreaterThanOrEqual(3)
    // Las lecturas de teléfonos (`read`, DP-11) se auditan aparte de la creación y la resolución.
    const alertEvents = (alertAudit.data as Array<{ event: string }>).map((row) => row.event)
    expect(alertEvents.filter((event) => event !== 'read').sort()).toEqual([
      'created',
      'updated',
      'updated',
    ])
    expect(alertEvents.filter((event) => event === 'read').length).toBeGreaterThanOrEqual(2)
    expect(JSON.stringify([movementAudit, alertAudit])).not.toContain('Carlos F.')
    expect(JSON.stringify([movementAudit, alertAudit])).not.toContain('esperó en dirección')

    // ── 8. Aislamiento entre centros: lo de A no se ve desde B, ni su sesión sirve en B ──
    expect((await conserjeB.json('GET', 'student-movements', 200)).data).toEqual([])
    expect((await conserjeB.json('GET', 'pickup-alerts', 200)).data).toEqual([])
    expect((await conserjeB.send('GET', `student-movements/${exit.public_id}`)).status()).toBe(404)
    expect((await conserjeB.send('GET', `pickup-alerts/${alert.public_id}`)).status()).toBe(404)
    expect(
      (await conserjeB.send('GET', `door/students/${student.student_person}/pickup-list`)).status(),
    ).toBe(404)
    expect(
      (
        await conserjeB.send('POST', 'student-movements', {
          data: { ...body, student_person: student.student_person },
        })
      ).status(),
    ).toBe(422)
    expect((await conserjeB.json('GET', 'door/students?q=Luc', 200)).data).toEqual([])

    const crossed = await playwrightRequest.newContext({
      baseURL: `${API}/`,
      extraHTTPHeaders: { Host: `${b.slug}.${BASE_DOMAIN}:8000`, Accept: 'application/json' },
      storageState: await conserje.ctx.storageState(),
    })
    expect((await crossed.get('student-movements')).status()).toBe(401)
    await crossed.dispose()

    // Y lo registrado en B (con los alumnos de B) no aparece en A.
    const unitB = (await adminB.json('POST', 'family-units', 201, { data: {} })).public_id as string
    await adminB.json('POST', `family-units/${unitB}/students`, 201, {
      data: { student_person: b.students[0] },
    })
    await conserjeB.json('POST', 'student-movements', 201, {
      data: { student_person: b.students[0], direction: 'entrada' },
    })
    expect((await conserjeB.json('GET', 'student-movements', 200)).data).toHaveLength(1)
    expect((await conserje.json('GET', 'student-movements', 200)).data).toHaveLength(3)
  })
})
