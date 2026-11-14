import { readFileSync } from 'node:fs'
import type { RealSession } from './realSession'

/**
 * Escaparate de la landing (ADR-074 §4.4, issue #579): **valores fijos**, versionados, para que la revisión de qué puede salir en
 * una imagen se haga una vez, sobre este texto. Nada es aleatorio.
 *
 * Reglas (`CLAUDE.md §11`, `REQ-SEED-005`): personas **inventadas** y verosímiles (ninguna real); correos solo `@example.com`; **ningún
 * documento de identidad ni dirección** de nadie (no se envían `document_*` ni datos fiscales del centro); sin fotografías (el logo es
 * un gráfico sintético propio, `LOGO_SVG`); el centro lleva un nombre visible ficticio y legible, distinto de la marca de seguridad
 * del soporte destructivo (`apps/api/tests/Support/e2e-real-tenant.php`, modo `showcase`).
 *
 * Todo se da de alta **por la API real** con la sesión del administrador (`INV-006`, `INV-010`), salvo lo que la API no permite hacer
 * (los usuarios que se crean por API quedan «pendientes» y la asignación docente exige docentes activos): los tres docentes y el
 * administrador los deja el soporte (`SHOWCASE_TEACHERS` de `e2e-real-tenant.php`; sus correos están aquí abajo y se contrastan).
 */

/** Nombre visible del centro: debe coincidir con `SHOWCASE_NAME` de `e2e-real-tenant.php` (lo contrasta la comprobación de humo). */
export const CENTRE_NAME = 'Colegio Ejemplo Los Álamos (ficticio)'

export const ADMIN = { email: 'elena.marin@example.com', name: 'Elena Marín' }

export const TEACHERS = {
  carmen: { email: 'carmen.ibarra@example.com', name: 'Carmen Ibarra Solano' },
  javier: { email: 'javier.lorca@example.com', name: 'Javier Lorca Brea' },
  lucia: { email: 'lucia.navarro@example.com', name: 'Lucía Navarro Peña' },
} as const

export const BRAND = { primary: '#1F6F5C', secondary: '#F6F1E3' }
const LOGO_ACCENT = '#E8A33D'

/** Logo sintético (un álamo estilizado, sin texto ni personas). */
export const LOGO_SVG = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128" width="128" height="128">
  <rect width="128" height="128" rx="28" fill="${BRAND.primary}"/>
  <ellipse cx="64" cy="52" rx="20" ry="34" fill="#F4F1E8"/>
  <ellipse cx="64" cy="52" rx="9" ry="28" fill="${LOGO_ACCENT}"/>
  <rect x="60" y="82" width="8" height="26" rx="3" fill="#F4F1E8"/>
</svg>
`

export const YEAR = { code: '2026-2027', starts_on: '2026-09-07', ends_on: '2027-06-23' }
/** Destino de la copia del curso siguiente (sin activar): la pantalla «copiar estructura» parte de aquí. */
export const NEXT_YEAR = { code: '2027-2028', starts_on: '2027-09-06', ends_on: '2028-06-21' }

const T = (en: string, es: string) => ({ en, 'es-ES': es })

export const LEVELS = [
  {
    code: 'PRI',
    name: T('Primary Education', 'Educación Primaria'),
    stage: 'primaria',
    regime: 'concertado',
  },
  {
    code: 'ESO',
    name: T('Compulsory Secondary Education', 'Educación Secundaria Obligatoria'),
    stage: 'eso',
    regime: 'concertado',
  },
] as const

export const COURSES = [
  { level: 'PRI', code: '5PRI', name: T('5th Primary', '5.º Primaria') },
  { level: 'PRI', code: '6PRI', name: T('6th Primary', '6.º Primaria') },
  { level: 'ESO', code: '1ESO', name: T('1st ESO', '1.º ESO') },
  { level: 'ESO', code: '2ESO', name: T('2nd ESO', '2.º ESO') },
] as const

export const GROUPS = [
  { course: '5PRI', code: 'A', name: '5th Primary A' },
  { course: '6PRI', code: 'A', name: '6th Primary A' },
  { course: '1ESO', code: 'A', name: '1st ESO A' },
  { course: '1ESO', code: 'B', name: '1st ESO B' },
  { course: '2ESO', code: 'A', name: '2nd ESO A' },
] as const

const SUBJECTS_BY_COURSE: Record<string, [string, [string, string]][]> = {
  '5PRI': [
    ['MAT', ['Mathematics', 'Matemáticas']],
    ['LEN', ['Spanish Language', 'Lengua']],
    ['ENG', ['English', 'Inglés']],
    ['SCI', ['Natural Science', 'Ciencias de la Naturaleza']],
  ],
  '6PRI': [
    ['MAT', ['Mathematics', 'Matemáticas']],
    ['LEN', ['Spanish Language', 'Lengua']],
    ['ENG', ['English', 'Inglés']],
    ['SCI', ['Natural Science', 'Ciencias de la Naturaleza']],
  ],
  '1ESO': [
    ['MAT', ['Mathematics', 'Matemáticas']],
    ['LEN', ['Spanish Language', 'Lengua']],
    ['ENG', ['English', 'Inglés']],
    ['BIO', ['Biology and Geology', 'Biología y Geología']],
  ],
  '2ESO': [
    ['MAT', ['Mathematics', 'Matemáticas']],
    ['LEN', ['Spanish Language', 'Lengua']],
    ['ENG', ['English', 'Inglés']],
    ['PHY', ['Physics and Chemistry', 'Física y Química']],
  ],
}

export const FACILITIES = [
  { type: 'aula', code: 'A101', name: 'Classroom 101' },
  { type: 'aula', code: 'A102', name: 'Classroom 102' },
  { type: 'aula', code: 'A201', name: 'Classroom 201' },
  { type: 'recurso', code: 'LAB', name: 'Science lab' },
] as const

/** Cuatro franjas de lunes a viernes (ISO: 1 = lunes). */
export const SLOT_TIMES: [string, string][] = [
  ['08:30', '09:25'],
  ['09:25', '10:20'],
  ['10:40', '11:35'],
  ['11:35', '12:30'],
]
export const WEEKDAYS = [1, 2, 3, 4, 5]
export const FRAME_NAME = T('Regular timetable', 'Jornada ordinaria')

/** Asignatura de 1.º ESO → docente. Javier imparte Lengua y Biología: de ahí sale el conflicto de profesor de `CONFLICT_ATTEMPT`. */
export const ESO1_TEACHING: Record<string, keyof typeof TEACHERS> = {
  MAT: 'carmen',
  LEN: 'javier',
  ENG: 'lucia',
  BIO: 'javier',
}
export const ESO1_SUBJECT_ORDER = ['MAT', 'LEN', 'ENG', 'BIO']

/**
 * Intento de movimiento con conflicto de profesor (lo muestra la rejilla junto a la acción como `422 timetable_conflict`):
 * la sesión de 1.º ESO B del lunes a primera hora (Lengua, Javier) a la última franja del viernes, donde Javier da Biología a 1.º ESO A.
 * 1.º ESO B no tiene sesión en esa franja, de modo que el único choque es el del profesor.
 */
export const CONFLICT_ATTEMPT = { group: '1ESO-B', fromSlotIndex: 0, toSlotIndex: 19 }

export const ROLE = {
  code: 'jefatura_estudios',
  name: 'Head of studies',
  /** Permisos (todos con ámbito `todos`) y un `deny` explícito, que prevalece sobre cualquier `allow` (RPERM-007). */
  allow: [
    'estructura_academica.leer',
    'horarios.leer',
    'horarios.crear',
    'horarios.actualizar',
    'asignacion_docente.leer',
    'asignacion_docente.crear',
    'espacios.leer',
    'reservas.leer',
    'alumno.leer',
    'familia.leer',
    'curso_academico.leer',
    'auditoria.leer',
  ],
  deny: ['usuario.eliminar', 'rol.eliminar', 'horarios.eliminar', 'espacios.eliminar'],
}

export const FAMILY = {
  guardians: [
    {
      email: 'isabel.ferrer@example.com',
      given: 'Isabel',
      family1: 'Ferrer',
      family2: 'Campos',
      relationship: 'madre',
      legal: true,
      emergency: true,
      access: 'lectura_escritura',
    },
    {
      email: 'tomas.aguirre@example.com',
      given: 'Tomás',
      family1: 'Aguirre',
      family2: 'Lozano',
      relationship: 'padre',
      legal: true,
      emergency: false,
      access: 'lectura_escritura',
    },
    {
      email: 'rosario.campos@example.com',
      given: 'Rosario',
      family1: 'Campos',
      family2: 'Vega',
      relationship: 'abuelo',
      legal: false,
      emergency: false,
      access: 'lectura',
    },
  ],
  students: [
    { given: 'Daniel', family1: 'Aguirre', family2: 'Ferrer', birth: '2014-03-21' },
    { given: 'Sofía', family1: 'Aguirre', family2: 'Ferrer', birth: '2016-09-08' },
  ],
}

/** Alumnado sin unidad familiar (rellena la lista de la puerta de entrega). */
export const OTHER_STUDENTS = [
  { given: 'Álvaro', family1: 'Quintana', family2: 'Mora', birth: '2015-01-14' },
  { given: 'Noa', family1: 'Beltrán', family2: 'Cruz', birth: '2013-11-02' },
  { given: 'Hugo', family1: 'Santana', family2: 'Rey', birth: '2016-05-27' },
]

/** Persona autorizada a recoger (sin documento ni fotografía: avatar de iniciales). */
export const PICKUP = { given: 'Joaquín', family1: 'Ferrer', family2: 'Soto' }

const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'

/** ULID válido: la `Idempotency-Key` de `POST /students` (RN-ALUM-27). */
export function ulid(): string {
  let time = Date.now()
  let out = ''

  for (let i = 0; i < 10; i++) {
    out = CROCKFORD[time % 32]! + out
    time = Math.floor(time / 32)
  }

  for (let i = 0; i < 16; i++) {
    out += CROCKFORD[Math.floor(Math.random() * 32)]
  }

  return out
}

export interface ShowcaseRefs {
  year: string
  nextYear: string
  role: string
  carmenUser: string
  unit: string
  studentPersons: string[]
  groups: Record<string, string>
  courses: Record<string, string>
  frame: string
  slots: string[]
  sessions: Record<string, string[]>
}

/** Da de alta el escaparate completo por la API con la sesión del administrador. Devuelve las referencias que usan las capturas. */
export async function createShowcase(admin: RealSession): Promise<ShowcaseRefs> {
  // --- Marca del centro ---------------------------------------------------------------------------------------------------
  await admin.json('PATCH', 'tenant/settings', 200, {
    regional: { default_locale: 'en', active_locales: ['en', 'es-ES'] },
    branding: { color_primary: BRAND.primary, color_secondary: BRAND.secondary },
  })
  await admin.upload('PUT', 'tenant/settings/assets/logo', 200, {
    file: { name: 'logo.svg', mimeType: 'image/svg+xml', buffer: Buffer.from(LOGO_SVG) },
  })
  // Fondo de acceso sintético (paisaje estilizado; el SVG fuente está al lado y la API no admite SVG como fondo).
  await admin.upload('PUT', 'tenant/settings/assets/login-background', 200, {
    file: {
      name: 'login-background.png',
      mimeType: 'image/png',
      buffer: readFileSync(new URL('./login-background.png', import.meta.url)),
    },
  })

  // --- Cursos académicos: el activo y el siguiente (borrador) --------------------------------------------------------------
  const year = (await admin.json('POST', 'academic-years', 201, YEAR)).public_id as string
  await admin.json('POST', `academic-years/${year}/status`, 200, { status: 'activo' })
  const nextYear = (await admin.json('POST', 'academic-years', 201, NEXT_YEAR)).public_id as string

  // --- Estructura académica -------------------------------------------------------------------------------------------------
  const levels: Record<string, string> = {}

  for (const [position, level] of LEVELS.entries()) {
    levels[level.code] = (
      await admin.json('POST', 'education-levels', 201, {
        academic_year: year,
        code: level.code,
        name_translations: level.name,
        stage: level.stage,
        regime: level.regime,
        position: position + 1,
      })
    ).public_id as string
  }

  const courses: Record<string, string> = {}

  for (const [position, course] of COURSES.entries()) {
    courses[course.code] = (
      await admin.json('POST', 'courses', 201, {
        education_level: levels[course.level],
        code: course.code,
        name_translations: course.name,
        position: position + 1,
      })
    ).public_id as string
  }

  const subjects: Record<string, Record<string, string>> = {}

  for (const [courseCode, list] of Object.entries(SUBJECTS_BY_COURSE)) {
    subjects[courseCode] = {}

    for (const [position, [code, [en, es]]] of list.entries()) {
      subjects[courseCode]![code] = (
        await admin.json('POST', 'subjects', 201, {
          course: courses[courseCode],
          code,
          name_translations: T(en, es),
          position: position + 1,
        })
      ).public_id as string
    }
  }

  const groups: Record<string, string> = {}

  for (const [position, group] of GROUPS.entries()) {
    const created = await admin.json('POST', 'groups', 201, {
      course: courses[group.course],
      code: group.code,
      name: group.name,
      position: position + 1,
    })

    groups[`${group.course}-${group.code}`] = created.public_id as string
    await admin.json('PUT', `groups/${created.public_id}/subjects`, 200, {
      subjects: Object.values(subjects[group.course]!),
    })
  }

  // --- Espacios, marco horario, asignaciones y sesiones --------------------------------------------------------------------
  const facilities: Record<string, string> = {}

  for (const facility of FACILITIES) {
    facilities[facility.code] = (
      await admin.json('POST', 'facilities', 201, {
        facility_type: facility.type,
        code: facility.code,
        name: facility.name,
      })
    ).public_id as string
  }

  const slotInputs = WEEKDAYS.flatMap((weekday) =>
    SLOT_TIMES.map(([starts_at, ends_at]) => ({ weekday, starts_at, ends_at })),
  )
  const frame = await admin.json('POST', 'timetable-frames', 201, {
    education_level: levels['ESO'],
    frame_type: 'ordinaria',
    name_translations: FRAME_NAME,
    slots: slotInputs,
  })
  // Orden estable: por día y hora (índice 0 = lunes primera hora; 19 = viernes última).
  const slots = [...(frame.slots as { public_id: string; weekday: number; starts_at: string }[])]
    .sort((a, b) => a.weekday - b.weekday || a.starts_at.localeCompare(b.starts_at))
    .map((s) => s.public_id)

  const teachers: Record<string, string> = {}

  for (const [key, teacher] of Object.entries(TEACHERS)) {
    const found = await admin.json('GET', `users?search=${encodeURIComponent(teacher.email)}`, 200)

    teachers[key] = (found.data as { public_id: string; email: string }[]).find(
      (u) => u.email === teacher.email,
    )!.public_id
  }

  const sessions: Record<string, string[]> = {}

  // 1.º ESO A y B: mismas asignaturas y docentes; B rota una posición para que no haya choques de profesor entre ambos grupos.
  for (const [groupKey, shift, count, facility] of [
    ['1ESO-A', 0, 20, 'A101'],
    ['1ESO-B', 1, 19, 'A102'],
  ] as const) {
    const assignment: Record<string, string> = {}

    for (const code of ESO1_SUBJECT_ORDER) {
      assignment[code] = (
        await admin.json('POST', 'teaching-assignments', 201, {
          teacher: teachers[ESO1_TEACHING[code]!],
          group: groups[groupKey],
          subject: subjects['1ESO']![code],
        })
      ).public_id as string
    }

    sessions[groupKey] = []

    for (let i = 0; i < count; i++) {
      const code = ESO1_SUBJECT_ORDER[(i + Math.floor(i / 4) + shift) % 4]!

      sessions[groupKey].push(
        (
          await admin.json('POST', 'timetable-sessions', 201, {
            time_slot: slots[i],
            teaching_assignment: assignment[code],
            facility: facilities[facility],
          })
        ).public_id as string,
      )
    }
  }

  // --- Roles y permisos --------------------------------------------------------------------------------------------------------
  const role = (
    await admin.json('POST', 'roles', 201, {
      code: ROLE.code,
      name: ROLE.name,
      permissions: [
        ...ROLE.allow.map((code) => ({ code, effect: 'allow', scope: 'todos' })),
        ...ROLE.deny.map((code) => ({ code, effect: 'deny', scope: 'todos' })),
      ],
    })
  ).public_id as string

  const allRoles = (await admin.json('GET', 'roles?per_page=100', 200)).data as {
    public_id: string
    code: string
  }[]
  const teacherRole = allRoles.find((r) => r.code === 'docente')!.public_id

  await admin.json('PUT', `users/${teachers['carmen']}/roles`, 200, {
    role_ids: [teacherRole, role],
  })
  await admin.json('PUT', `users/${teachers['javier']}/roles`, 200, { role_ids: [teacherRole] })
  await admin.json('PUT', `users/${teachers['lucia']}/roles`, 200, { role_ids: [teacherRole] })

  // --- Unidad familiar con tutores y custodia ------------------------------------------------------------------------------------
  const unit = (await admin.json('POST', 'family-units', 201, {})).public_id as string
  const studentPersons: string[] = []

  for (const student of FAMILY.students) {
    const created = await admin.json(
      'POST',
      'students',
      201,
      {
        given_name: student.given,
        family_name_1: student.family1,
        family_name_2: student.family2,
        birth_date: student.birth,
        family_unit: unit,
      },
      { 'Idempotency-Key': ulid() },
    )

    studentPersons.push(created.person.public_id as string)
  }

  const guardians: string[] = []

  for (const g of FAMILY.guardians) {
    const user = await admin.json('POST', 'users', 201, {
      email: g.email,
      person: {
        given_name: g.given,
        family_name_1: g.family1,
        family_name_2: g.family2,
        locale: 'en',
      },
      send_invitation: false,
      role_ids: [],
    })
    const guardian = (
      await admin.json('POST', 'guardians', 201, { user: user.public_id, family_unit: unit })
    ).public_id as string

    guardians.push(guardian)

    for (const student of studentPersons) {
      await admin.json('POST', 'guardian-links', 201, {
        guardian,
        student_person: student,
        relationship: g.relationship,
        is_legal_guardian: g.legal,
        is_emergency_contact: g.emergency,
        access_level: g.access,
        ...(g.legal ? {} : { restricted_categories: ['salud'] }),
      })
    }
  }

  await admin.json('PATCH', `family-units/${unit}`, 200, { principal_guardian: guardians[0] })

  // --- Alumnado sin unidad y puerta de entrega (sin fotografías) ---------------------------------------------------------------
  for (const student of OTHER_STUDENTS) {
    await admin.json(
      'POST',
      'students',
      201,
      {
        given_name: student.given,
        family_name_1: student.family1,
        family_name_2: student.family2,
        birth_date: student.birth,
      },
      { 'Idempotency-Key': ulid() },
    )
  }

  const text = (
    await admin.json('POST', 'terms-versions', 201, {
      kind: 'autorizacion_recogida',
      title_translations: T('Pick-up authorisation', 'Autorización de recogida'),
      body_translations: T(
        'I authorise the person named to collect my child from school.',
        'Autorizo a la persona indicada a recoger a mi hijo o hija.',
      ),
    })
  ).public_id as string

  await admin.json('POST', `terms-versions/${text}/publish`, 200)

  const pickup = await admin.json('POST', 'pickup-persons', 201, {
    given_name: PICKUP.given,
    family_name_1: PICKUP.family1,
    family_name_2: PICKUP.family2,
  })

  await admin.json('POST', 'pickup-authorizations', 201, {
    student_person: studentPersons[0],
    person: pickup.person,
    relationship: 'abuelo',
    kind: 'permanente',
    valid_from: '2026-09-07',
    valid_until: null,
    authorized_by: guardians[0],
    terms_version: text,
    channel: 'presencial',
  })

  return {
    year,
    nextYear,
    role,
    carmenUser: teachers['carmen']!,
    unit,
    studentPersons,
    groups,
    courses,
    frame: frame.public_id as string,
    slots,
    sessions,
  }
}
