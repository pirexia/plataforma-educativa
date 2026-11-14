import { describe, expect, it, vi } from 'vitest'

// REQ-AUTH-003 (1.3b, pieza 3), CA-AUTH-176: "buscar usuario" de las
// áreas de restablecimiento y excepciones filtra en cliente sobre
// `GET /mfa-compliance/users` — sin endpoint de texto libre propio
// (api.md §D.5.1). Se mockea `getMfaComplianceUsers` para no depender de
// la API real.
const getMfaComplianceUsers = vi.fn()

vi.mock('../api', () => ({
  getMfaComplianceUsers: (...args: unknown[]) => getMfaComplianceUsers(...args),
}))

const { useMfaUserSearch } = await import('./useMfaUserSearch')

const PAGE = {
  data: [
    {
      user: {
        public_id: '01J-MARTA',
        given_name: 'Marta',
        family_name_1: 'Ruiz',
        family_name_2: 'Soto',
        email: 'marta.ruiz@example.com',
      },
      state: 'pending',
      grace_deadline_at: null,
      enrolled_methods: [],
      required_by_roles: ['docente'],
    },
    {
      user: {
        public_id: '01J-LUIS',
        given_name: 'Luis',
        family_name_1: 'Ortiz',
        family_name_2: null,
        email: 'luis.ortiz@example.com',
      },
      state: 'enrolled',
      grace_deadline_at: null,
      enrolled_methods: ['totp'],
      required_by_roles: [],
    },
  ],
  meta: { current_page: 1, per_page: 100, total: 2, last_page: 1 },
}

describe('useMfaUserSearch', () => {
  it('no llama a la API hasta que se pide cargar (ensureLoaded)', () => {
    getMfaComplianceUsers.mockReset()
    useMfaUserSearch()

    expect(getMfaComplianceUsers).not.toHaveBeenCalled()
  })

  it('carga una sola vez aunque ensureLoaded se llame varias veces', async () => {
    getMfaComplianceUsers.mockReset().mockResolvedValue(PAGE)
    const { ensureLoaded } = useMfaUserSearch()

    await Promise.all([ensureLoaded(), ensureLoaded(), ensureLoaded()])
    await ensureLoaded()

    expect(getMfaComplianceUsers).toHaveBeenCalledTimes(1)
  })

  it('filtra por nombre o por correo, sin distinguir mayúsculas', async () => {
    getMfaComplianceUsers.mockReset().mockResolvedValue(PAGE)
    const { ensureLoaded, search } = useMfaUserSearch()
    await ensureLoaded()

    expect(search('marta').map((u) => u.public_id)).toEqual(['01J-MARTA'])
    expect(search('RUIZ').map((u) => u.public_id)).toEqual(['01J-MARTA'])
    expect(search('luis.ortiz@example.com').map((u) => u.public_id)).toEqual(['01J-LUIS'])
    expect(search('nadie-coincide')).toEqual([])
  })

  it('con la consulta vacía no propone nada (evita listar a todo el mundo por accidente)', async () => {
    getMfaComplianceUsers.mockReset().mockResolvedValue(PAGE)
    const { ensureLoaded, search } = useMfaUserSearch()
    await ensureLoaded()

    expect(search('')).toEqual([])
    expect(search('   ')).toEqual([])
  })

  it('marca el error sin lanzar si la API falla', async () => {
    getMfaComplianceUsers.mockReset().mockRejectedValue(new Error('network'))
    const { ensureLoaded, errored, loaded } = useMfaUserSearch()

    await ensureLoaded()

    expect(errored.value).toBe(true)
    expect(loaded.value).toBe(false)
  })
})
