/* eslint-disable @typescript-eslint/no-explicit-any -- cuerpos JSON de la API real, sin tipar a propósito: el test comprueba el contrato, no lo importa. */
import { test, expect } from '@playwright/test'
import { RealSession } from './fixtures/realSession'
import {
  centreA,
  createStructure,
  ensureActiveYear,
  tutorGuardian,
  enroll,
  tag,
  ulid,
  type Structure,
  type YearInfo,
} from './fixtures/alumReal'

/**
 * `docs/proceso/e2e-real-ci.md §9.4`, `CA-E2ECI-27` (E2E-CI-d2, filas T-02 a T-06 de `e2e-real-ci-matriz.md §4.2`): el tutor del
 * modo `alum` consulta por HTTP real (cookie de sesión y CSRF reales, sin navegador) familia, tutores, vínculos, consentimientos de
 * imagen, personas de recogida y matrículas. Recibe solo lo de sus alumnos (ámbito `unidad_familiar`), `404` en lo ajeno, nunca un
 * vínculo revocado (`RN-FAM-UNIT-36`) y `403` en las escrituras que no tiene. Datos sintéticos de `REQ-SEED-005` (`@example.com`,
 * nombres ficticios, documentos con dígito de control inválido). El alumno y el vínculo con revocación de `alum-students-real`
 * (T-01, `CA-ALUM-012`/`014a`) no se repiten: este spec crea sus propias unidades, alumnos y vínculos.
 *
 * El nombre ordena el fichero **después** de `alum-students-real.spec.ts` (Playwright ejecuta los ficheros por orden alfabético, no por el de
 * la línea de órdenes): ese spec da de alta al tutor con `POST /guardians` y el tutor solo puede serlo una vez; este reutiliza su ficha (`tutorGuardian`).
 *
 * No publica condiciones de uso (el muro del tutor lo cubre otro test de `-d2`).
 *
 * Solo con `E2E_REAL_API=1`: `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).
 */
test.describe('CA-E2ECI-27 (REQ-FAM-UNIT, REQ-ALUM): el tutor por HTTP real solo ve lo suyo', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real y un centro de pruebas: ejecútalo con `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).',
  )

  test.describe.configure({ mode: 'serial' })

  const label = tag()
  let admin: RealSession
  let tutor: RealSession
  let year: YearInfo
  let structure: Structure

  // Unidad propia del tutor: alumno vinculado y alumno con el vínculo revocado.
  let unit = ''
  let own = { id: '', person: '' }
  let revoked = { id: '', person: '' }
  let guardian = ''
  let linkActive = ''
  let linkRevoked = ''
  // Unidad ajena: otro tutor, otro alumno.
  let otherUnit = ''
  let other = { id: '', person: '' }
  let otherGuardian = ''
  let otherLink = ''
  // Recogida: una persona autorizada por alumno.
  let authOwn = ''
  let authOther = ''
  let enrollmentOwn = ''
  let enrollmentOther = ''

  const student = async (family: string, unitId: string) => {
    const created = await admin.json(
      'POST',
      'students',
      201,
      {
        given_name: 'Alumno',
        family_name_1: `${family}${label}`,
        birth_date: '2016-02-02',
        family_unit: unitId,
      },
      { 'Idempotency-Key': ulid() },
    )

    return { id: created.public_id as string, person: created.person.public_id as string }
  }

  test.beforeAll(async () => {
    test.setTimeout(240_000)
    admin = await RealSession.login(centreA.slug, centreA.adminEmail, centreA.adminPassword)
    tutor = await RealSession.login(centreA.slug, centreA.tutorEmail, centreA.staffPassword)

    year = await ensureActiveYear(admin)
    structure = await createStructure(admin, year.id, `T${label}`)

    // El tutor solo puede ser tutor una vez en el centro: se reutiliza su ficha y su unidad (`tutorGuardian`).
    ;({ guardian, unit } = await tutorGuardian(admin))
    own = await student('Propio', unit)
    revoked = await student('Revocado', unit)
    const link = async (g: string, personId: string) =>
      (
        await admin.json('POST', 'guardian-links', 201, {
          guardian: g,
          student_person: personId,
          relationship: 'madre',
          is_legal_guardian: true,
        })
      ).public_id as string

    linkActive = await link(guardian, own.person)
    linkRevoked = await link(guardian, revoked.person)
    await admin.json('PATCH', `family-units/${unit}`, 200, { principal_guardian: guardian })
    await admin.json('POST', `guardian-links/${linkRevoked}/revocation`, 200, {
      reason: 'resolucion_judicial',
    })

    // Otra familia del mismo centro, con otro tutor (usuario sin roles, sin invitación).
    otherUnit = (await admin.json('POST', 'family-units', 201, {})).public_id as string
    other = await student('Ajeno', otherUnit)
    const otherUser = await admin.json('POST', 'users', 201, {
      email: `e2e-tutor-ajeno-${label}@example.com`,
      person: { given_name: 'Tutora', family_name_1: `Ajena${label}` },
      send_invitation: false,
      role_ids: [],
    })

    otherGuardian = (
      await admin.json('POST', 'guardians', 201, {
        user: otherUser.public_id,
        family_unit: otherUnit,
      })
    ).public_id as string
    otherLink = await link(otherGuardian, other.person)
    await admin.json('PATCH', `family-units/${otherUnit}`, 200, {
      principal_guardian: otherGuardian,
    })

    // Matrículas de los dos alumnos.
    enrollmentOwn = await enroll(admin, own.id, year, structure, structure.groupFrom)
    enrollmentOther = await enroll(admin, other.id, year, structure, structure.groupFrom)

    // Una persona autorizada a recoger por cada alumno (texto publicado, persona, autorización permanente).
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

    const authorize = async (studentPerson: string, byGuardian: string, family: string) => {
      const person = (
        await admin.json('POST', 'pickup-persons', 201, {
          given_name: 'Abuelo',
          family_name_1: `${family}${label}`,
          contact_phone: '600555000',
        })
      ).person as string

      return (
        await admin.json('POST', 'pickup-authorizations', 201, {
          student_person: studentPerson,
          person,
          relationship: 'abuelo',
          kind: 'permanente',
          valid_from: '2026-01-01',
          valid_until: null,
          authorized_by: byGuardian,
          terms_version: text,
          channel: 'presencial',
        })
      ).public_id as string
    }

    authOwn = await authorize(own.person, guardian, 'Propio')
    authOther = await authorize(other.person, otherGuardian, 'Ajeno')
  })

  test.afterAll(async () => {
    await Promise.all([admin, tutor].map((s) => s?.dispose()))
  })

  test('CA-E2ECI-27 T-03: familia, tutores y vínculos del tutor son solo los suyos y nunca un vínculo revocado (RN-FAM-UNIT-36)', async () => {
    const units = (await tutor.json('GET', 'family-units', 200)).data.map((u: any) => u.public_id)

    expect(units).toContain(unit)
    expect(units).not.toContain(otherUnit)
    await tutor.json('GET', `family-units/${unit}`, 200)
    await tutor.json('GET', `family-units/${otherUnit}`, 404)

    const guardians = (await tutor.json('GET', 'guardians', 200)).data.map((g: any) => g.public_id)

    expect(guardians).toContain(guardian)
    expect(guardians).not.toContain(otherGuardian)
    await tutor.json('GET', `guardians/${guardian}`, 200)
    await tutor.json('GET', `guardians/${otherGuardian}`, 404)

    const links = (await tutor.json('GET', 'guardian-links', 200)).data.map((l: any) => l.public_id)

    expect(links).toContain(linkActive)
    expect(links).not.toContain(linkRevoked)
    expect(links).not.toContain(otherLink)
    await tutor.json('GET', `guardian-links/${linkActive}`, 200)
    await tutor.json('GET', `guardian-links/${linkRevoked}`, 404)
    await tutor.json('GET', `guardian-links/${otherLink}`, 404)

    // Ni siquiera pidiéndolos por el filtro de estado: sin `revocacion_tutor.leer` el filtro está prohibido (`RN-FAM-UNIT-40`).
    expect((await tutor.send('GET', 'guardian-links?status=revocado')).status()).toBe(403)

    // El alumnado de la unidad que ve el tutor no trae al ajeno.
    await tutor.json('GET', `family-units/${otherUnit}/students`, 404)
  })

  test('CA-E2ECI-27 T-04: el tutor ve y decide el consentimiento de imagen solo de su alumno', async () => {
    const consents = (await tutor.json('GET', 'image-consents', 200)).data
    const persons = [...new Set(consents.map((c: any) => c.student_person))]

    expect(persons).toContain(own.person)
    expect(persons).not.toContain(other.person)
    expect(persons).not.toContain(revoked.person)

    await tutor.json('GET', 'image-consent-texts', 200)

    // Decide por su alumno (portal, `no_autorizo` no necesita texto publicado) y no por el ajeno ni por el del vínculo revocado.
    const decided = await tutor.json('POST', 'image-consent-decisions', 201, {
      student_person: own.person,
      level: 'web',
      decision: 'no_autorizo',
    })

    expect(decided.decision.channel).toBe('portal')
    await tutor.json('POST', 'image-consent-decisions', 404, {
      student_person: other.person,
      level: 'web',
      decision: 'no_autorizo',
    })
    await tutor.json('POST', 'image-consent-decisions', 404, {
      student_person: revoked.person,
      level: 'web',
      decision: 'no_autorizo',
    })
    await tutor.json('GET', `image-consent-decisions?student_person=${other.person}`, 404)
  })

  test('CA-E2ECI-27 T-05: el tutor ve las personas autorizadas a recoger de su alumno y no las del ajeno', async () => {
    const list = (await tutor.json('GET', 'pickup-authorizations', 200)).data.map(
      (a: any) => a.public_id,
    )

    expect(list).toContain(authOwn)
    expect(list).not.toContain(authOther)
    await tutor.json('GET', `pickup-authorizations/${authOwn}`, 200)
    await tutor.json('GET', `pickup-authorizations/${authOther}`, 404)
    await tutor.json('GET', `pickup-lists/${own.person}`, 200)
    await tutor.json('GET', `pickup-lists/${other.person}`, 404)
    await tutor.json('GET', `pickup-lists/${revoked.person}`, 404)
  })

  test('CA-E2ECI-27 T-02: el tutor ve las matrículas de su alumno y no las del ajeno', async () => {
    const rows = (await tutor.json('GET', `enrollments?academic_year=${year.id}`, 200)).data
    const ids = rows.map((e: any) => e.public_id)

    expect(ids).toContain(enrollmentOwn)
    expect(ids).not.toContain(enrollmentOther)
    await tutor.json('GET', `enrollments/${enrollmentOwn}`, 200)
    await tutor.json('GET', `enrollments/${enrollmentOther}`, 404)
  })

  test('CA-E2ECI-27 T-06: el tutor no escribe familia, tutores, vínculos, revocaciones ni autorizados (403)', async () => {
    const forbidden = async (method: string, path: string, data?: unknown) => {
      const response = await tutor.send(method, path, data)

      expect(response.status(), `${method} ${path}: ${await response.text()}`).toBe(403)
    }

    await forbidden('POST', 'family-units', {})
    await forbidden('PATCH', `family-units/${unit}`, { principal_guardian: guardian })
    await forbidden('DELETE', `family-units/${unit}`)
    await forbidden('POST', `family-units/${unit}/students`, { student_person: other.person })
    await forbidden('POST', 'guardians', { user: centreA.tutorUser, family_unit: unit })
    await forbidden('PATCH', `guardians/${guardian}`, {})
    await forbidden('DELETE', `guardians/${guardian}`)
    await forbidden('POST', 'guardian-links', {
      guardian,
      student_person: other.person,
      relationship: 'madre',
      is_legal_guardian: true,
    })
    await forbidden('PATCH', `guardian-links/${linkActive}`, { is_emergency_contact: true })
    await forbidden('DELETE', `guardian-links/${linkActive}`)
    await forbidden('POST', `guardian-links/${linkActive}/revocation`, {
      reason: 'resolucion_judicial',
    })
    await forbidden('POST', 'pickup-persons', { given_name: 'X', family_name_1: 'Y' })
    await forbidden('POST', 'pickup-authorizations', {})
    await forbidden('POST', `pickup-authorizations/${authOwn}/withdrawal`, {})

    // Nada de eso cambió el estado: el vínculo sigue activo y la autorización vigente.
    await tutor.json('GET', `guardian-links/${linkActive}`, 200)
    await tutor.json('GET', `pickup-authorizations/${authOwn}`, 200)
  })
})
