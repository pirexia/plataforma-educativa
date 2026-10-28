/**
 * `docs/modulos/REQ-FAM-UNIT/api.md §2` (1.14-d): rutas, métodos y cuerpos del cliente del módulo. El identificador de
 * ruta se codifica; los valores múltiples viajan separados por comas (`ADR-038 §5.2`); los vacíos se omiten; el informe
 * de consentimientos lleva los filtros como listas JSON (`ADR-054 §8.2`) y nunca `q`. `REQ-FAM-UNIT-001`/`-002`/`-003`/`-004`.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const apiFetch = vi.fn()

vi.mock('@/api/client', () => ({
  apiFetch: (...args: unknown[]) => apiFetch(...args),
}))

const api = await import('./index')

const HOSTILE = '01ABC/../x?y=1#z'
const ENCODED = encodeURIComponent(HOSTILE)

beforeEach(() => {
  apiFetch.mockReset().mockResolvedValue({})
})

function last(): [string, { method?: string; body?: string } | undefined] {
  return apiFetch.mock.calls.at(-1) as [string, { method?: string; body?: string } | undefined]
}

describe('REQ-FAM-UNIT-001 (api.md §2.1 a §2.5): unidades, tutores, alumnado y vínculos', () => {
  it('GET /family-units con búsqueda, filtro, orden y paginación; los vacíos se omiten', async () => {
    await api.listFamilyUnits({
      q: 'UF-0',
      has_principal: 'true',
      sort: '-code',
      page: 2,
      per_page: 25,
    })
    expect(last()[0]).toBe('/family-units?q=UF-0&has_principal=true&sort=-code&page=2&per_page=25')

    await api.listFamilyUnits({})
    expect(last()[0]).toBe('/family-units')
  })

  it('POST /family-units sin código lo deja al sistema (cuerpo vacío)', async () => {
    await api.createFamilyUnit({})
    expect(last()).toEqual(['/family-units', { method: 'POST', body: '{}' }])

    await api.createFamilyUnit({ code: 'UF-9' })
    expect(JSON.parse(last()[1]!.body!)).toEqual({ code: 'UF-9' })
  })

  it('GET /family-units/{id} codifica el identificador', async () => {
    await api.getFamilyUnit(HOSTILE)
    expect(last()[0]).toBe(`/family-units/${ENCODED}`)
  })

  it('PATCH /family-units/{id} envía solo el tutor principal (o null)', async () => {
    await api.setPrincipalGuardian('U1', 'G1')
    expect(last()).toEqual([
      '/family-units/U1',
      { method: 'PATCH', body: JSON.stringify({ principal_guardian: 'G1' }) },
    ])

    await api.setPrincipalGuardian('U1', null)
    expect(JSON.parse(last()[1]!.body!)).toEqual({ principal_guardian: null })
  })

  it('GET /guardians filtra por unidad; POST envía el usuario y la unidad; DELETE codifica', async () => {
    await api.listGuardians({ family_unit: 'U1', per_page: 100 })
    expect(last()[0]).toBe('/guardians?family_unit=U1&per_page=100')

    await api.createGuardian({ user: 'USR1', family_unit: 'U1' })
    expect(last()).toEqual([
      '/guardians',
      { method: 'POST', body: JSON.stringify({ user: 'USR1', family_unit: 'U1' }) },
    ])

    await api.deleteGuardian(HOSTILE)
    expect(last()).toEqual([`/guardians/${ENCODED}`, { method: 'DELETE' }])
  })

  it('alumnado de la unidad: lista sin paginar, alta por persona y baja por pertenencia', async () => {
    await api.listFamilyUnitStudents('U1')
    expect(last()[0]).toBe('/family-units/U1/students')

    await api.addFamilyUnitStudent('U1', 'P1')
    expect(last()).toEqual([
      '/family-units/U1/students',
      { method: 'POST', body: JSON.stringify({ student_person: 'P1' }) },
    ])

    await api.removeFamilyUnitStudent('U1', HOSTILE)
    expect(last()).toEqual([`/family-units/U1/students/${ENCODED}`, { method: 'DELETE' }])
  })

  it('vínculos: listado por unidad, alta con el cuerpo completo', async () => {
    await api.listGuardianLinks({ family_unit: 'U1', per_page: 100 })
    expect(last()[0]).toBe('/guardian-links?family_unit=U1&per_page=100')

    const body = {
      guardian: 'G1',
      student_person: 'P1',
      relationship: 'madre' as const,
      is_legal_guardian: true,
      access_level: 'lectura' as const,
      restricted_categories: ['salud'],
      is_emergency_contact: false,
    }

    await api.createGuardianLink(body)
    expect(last()).toEqual(['/guardian-links', { method: 'POST', body: JSON.stringify(body) }])
  })
})

describe('REQ-FAM-UNIT-002 (api.md §2.5): revocación por custodia', () => {
  it('POST …/revocation lleva solo el motivo (sin texto libre) y DELETE levanta', async () => {
    await api.revokeGuardianLink(HOSTILE, 'resolucion_judicial')
    expect(last()).toEqual([
      `/guardian-links/${ENCODED}/revocation`,
      { method: 'POST', body: JSON.stringify({ reason: 'resolucion_judicial' }) },
    ])

    await api.liftGuardianLinkRevocation(HOSTILE)
    expect(last()).toEqual([`/guardian-links/${ENCODED}/revocation`, { method: 'DELETE' }])
  })
})

describe('REQ-FAM-UNIT-004 (api.md §2.7, §4): consentimiento de imagen', () => {
  it('GET /image-consents con filtros múltiples por comas', async () => {
    await api.listImageConsents({
      family_unit: 'U1',
      level: 'web,redes_sociales',
      state: 'pendiente',
    })
    expect(last()[0]).toBe(
      '/image-consents?family_unit=U1&level=web%2Credes_sociales&state=pendiente',
    )
  })

  it('GET /image-consent-decisions exige el alumno y pasa el cursor', async () => {
    await api.listImageConsentDecisions({ student_person: 'P1', cursor: 'abc' })
    expect(last()[0]).toBe('/image-consent-decisions?student_person=P1&cursor=abc')
  })

  it('el informe convierte los filtros del componente en listas JSON y no envía q ni lo desconocido', async () => {
    await api.exportImageConsents({
      level: 'web,redes_sociales',
      state: 'pendiente',
      q: 'x',
      sort: 'state',
    })
    expect(last()[0]).toBe('/image-consents/exports')
    expect(last()[1]!.method).toBe('POST')
    expect(JSON.parse(last()[1]!.body!)).toEqual({
      level: ['web', 'redes_sociales'],
      state: ['pendiente'],
    })

    await api.exportImageConsents({})
    expect(JSON.parse(last()[1]!.body!)).toEqual({})
  })
})

describe('REQ-FAM-UNIT-003 (api.md §2.8): versiones de los textos legales y aceptaciones', () => {
  it('GET /terms-versions con filtros por kind y estado', async () => {
    await api.listTermsVersions({
      kind: 'condiciones_uso',
      status: 'borrador,publicada',
      sort: '-version_number',
    })
    expect(last()[0]).toBe(
      '/terms-versions?kind=condiciones_uso&status=borrador%2Cpublicada&sort=-version_number',
    )
  })

  it('POST crea el borrador con kind y traducciones; PATCH envía la fusión por idioma; DELETE codifica', async () => {
    await api.createTermsVersion({
      kind: 'condiciones_uso',
      title_translations: { 'es-ES': 'T' },
      body_translations: { 'es-ES': 'B' },
    })
    expect(JSON.parse(last()[1]!.body!)).toEqual({
      kind: 'condiciones_uso',
      title_translations: { 'es-ES': 'T' },
      body_translations: { 'es-ES': 'B' },
    })

    await api.updateTermsVersion('V1', { title_translations: { en: null } })
    expect(last()).toEqual([
      '/terms-versions/V1',
      { method: 'PATCH', body: JSON.stringify({ title_translations: { en: null } }) },
    ])

    await api.deleteTermsVersion(HOSTILE)
    expect(last()).toEqual([`/terms-versions/${ENCODED}`, { method: 'DELETE' }])
  })

  it('publicar: requires_reacceptance solo en condiciones de uso; en un texto de imagen se omite', async () => {
    await api.publishTermsVersion('V1', true)
    expect(last()).toEqual([
      '/terms-versions/V1/publish',
      { method: 'POST', body: JSON.stringify({ requires_reacceptance: true }) },
    ])

    await api.publishTermsVersion('V2', undefined)
    expect(last()[1]!.body).toBe('{}')
  })

  it('GET /terms-acceptances pasa el cursor; el autoservicio del muro sigue igual', async () => {
    await api.listTermsAcceptances({ cursor: 'c1' })
    expect(last()[0]).toBe('/terms-acceptances?cursor=c1')

    await api.getMyTerms()
    expect(last()[0]).toBe('/me/terms')

    await api.acceptMyTerms('V1')
    expect(last()).toEqual([
      '/me/terms-acceptances',
      { method: 'POST', body: JSON.stringify({ terms_version: 'V1' }) },
    ])
  })
})
