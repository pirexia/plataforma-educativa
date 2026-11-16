/**
 * `docs/modulos/REQ-ALUM/funcional.md §10`, `CA-ALUM-070` (filtros a la URL; sin acciones con un curso de solo
 * lectura), `CA-ALUM-071` (etiquetas, errores asociados, foco en diálogos), `RN-ALUM-42`, `RN-ALUM-43`, `RN-ALUM-44`,
 * `RN-ALUM-45`, `RN-ACAD-42`, `INV-002`, `REQ-ALUM-003`. `1.15-d` T2b: solicitudes de cambio de grupo.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { setLocale } from '@/i18n'
import * as alumApi from '../api'
import * as acadApi from '@/modules/acad/api'
import * as cursoApi from '@/modules/curso/api'
import {
  buttonByText,
  fill,
  group,
  groupChangeRequest,
  mountView,
  page,
  problem,
  stubMatchMedia,
  submitForm,
  year,
} from '../testSupport'

vi.mock('../api')
vi.mock('@/modules/acad/api')
vi.mock('@/modules/curso/api')
vi.mock('@/session/useSession', async () => {
  const { shallowRef } = await import('vue')
  const user = shallowRef<{ public_id: string; permissions: string[] } | null>(null)

  return {
    useSession: () => ({ user }),
    __setPermissions: (permissions: string[]) => {
      user.value = { public_id: 'ME', permissions }
    },
  }
})

const session = (await import('@/session/useSession')) as unknown as {
  __setPermissions: (permissions: string[]) => void
}
const { resetSelectableYears } = await import('@/academic-year')
const { default: GroupChangesView } = await import('./GroupChangesView.vue')

const wrappers: VueWrapper[] = []

async function open(url = '/alumnado/cambios-de-grupo') {
  const mounted = await mountView('alum-group-changes', GroupChangesView, url)

  wrappers.push(mounted.wrapper)

  return mounted
}

function lastList(): Record<string, unknown> {
  return vi.mocked(alumApi.listGroupChangeRequests).mock.calls.at(-1)![0] as Record<string, unknown>
}

function dialog(): HTMLElement | null {
  return document.body.querySelector<HTMLElement>('[data-slot="alum-group-change-decision"]')
}

const MANAGE = ['matricula.leer', 'matricula.aprobar', 'matricula.actualizar']

beforeEach(() => {
  setLocale('es')
  stubMatchMedia()
  window.localStorage.clear()
  resetSelectableYears()
  vi.mocked(cursoApi.listSelectableAcademicYears)
    .mockReset()
    .mockResolvedValue({
      data: [year('Y2', '2026-2027', 'activo'), year('Y1', '2025-2026', 'cerrado')],
    } as never)
  for (const api of [alumApi, acadApi]) {
    for (const fn of Object.values(api)) {
      if (typeof fn === 'function' && 'mockReset' in fn)
        (fn as ReturnType<typeof vi.fn>).mockReset()
    }
  }
  vi.mocked(alumApi.listGroupChangeRequests).mockResolvedValue(
    page([
      groupChangeRequest(),
      groupChangeRequest({
        public_id: 'R2',
        status: 'aprobada',
        enrollment: {
          public_id: 'E2',
          student: { public_id: 'S2', display_name: 'Ana Ejemplo Vidal' },
        },
      }),
    ]) as never,
  )
  vi.mocked(acadApi.listGroups).mockResolvedValue(page([group()]) as never)
  session.__setPermissions(['matricula.leer'])
})

afterEach(() => {
  vi.useRealTimers()
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('CA-ALUM-070: solicitudes de cambio de grupo', () => {
  it('pinta alumno, grupos de origen y destino, motivo, estado y quién la solicitó', async () => {
    const { wrapper } = await open()
    const rows = wrapper.findAll('tbody tr').map((row) => row.text())

    expect(rows).toHaveLength(2)
    expect(rows[0]).toContain('Lucas Ejemplo Ruiz')
    expect(rows[0]).toContain('1º ESO A')
    expect(rows[0]).toContain('1º ESO B')
    expect(rows[0]).toContain('Motivo de ejemplo')
    expect(rows[0]).toContain('Pendiente')
    expect(rows[0]).toContain('Secretaría Ejemplo')
    expect(rows[1]).toContain('Aprobada')
  })

  it('envía el curso efectivo (el activo si no hay parámetro) y los filtros de la URL, sin q', async () => {
    await open('/alumnado/cambios-de-grupo?status=pendiente&group=G1')

    expect(lastList()).toMatchObject({ academic_year: 'Y2', status: 'pendiente', group: 'G1' })
    expect(lastList()).not.toHaveProperty('q')
  })

  it('el curso de la URL manda sobre el activo y las opciones de grupo salen de ese curso', async () => {
    await open('/alumnado/cambios-de-grupo?academic_year=Y1')

    expect(lastList().academic_year).toBe('Y1')
    expect(vi.mocked(acadApi.listGroups).mock.calls.at(-1)![0]).toMatchObject({
      academic_year: 'Y1',
    })
  })

  it('el filtro de estado va a la URL conservando academic_year', async () => {
    const { wrapper, router } = await open('/alumnado/cambios-de-grupo?academic_year=Y1')

    const trigger = [...wrapper.findAll('button')].find((b) => b.text().startsWith('Estado'))!

    await trigger.trigger('click')
    await flushPromises()

    const item = [...document.body.querySelectorAll<HTMLElement>('[role^="menuitem"]')].find((el) =>
      el.textContent?.includes('Pendiente'),
    )!

    item.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    expect(router.currentRoute.value.query.status).toBe('pendiente')
    expect(router.currentRoute.value.query.academic_year).toBe('Y1')
    expect(JSON.stringify(router.currentRoute.value.query)).not.toContain('"q"')
  })

  it('sin curso elegido ni activo no consulta y lo explica', async () => {
    vi.mocked(cursoApi.listSelectableAcademicYears).mockResolvedValue({ data: [] } as never)

    const { wrapper } = await open()

    expect(alumApi.listGroupChangeRequests).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('No hay un curso académico activo ni elegido')
  })

  it('un 403 del servidor se pinta como estado de error de pantalla', async () => {
    vi.mocked(alumApi.listGroupChangeRequests).mockRejectedValue(problem(403))

    const { wrapper } = await open()

    expect(wrapper.findAll('tbody tr')).toHaveLength(0)
    expect(wrapper.text()).toContain('permiso')
  })
})

describe('CA-ALUM-070 (INV-002, RN-ACAD-42): acciones según permiso y curso', () => {
  it('sin matricula.aprobar ni matricula.actualizar no hay acciones', async () => {
    const { wrapper } = await open()

    expect(buttonByText('Aprobar')).toBeUndefined()
    expect(buttonByText('Rechazar')).toBeUndefined()
    expect(buttonByText('Cancelar')).toBeUndefined()
    expect(wrapper.text()).not.toContain('Acciones')
  })

  it('matricula.aprobar da aprobar y rechazar; matricula.actualizar, cancelar; solo en pendientes', async () => {
    session.__setPermissions(['matricula.leer', 'matricula.aprobar'])

    const first = await open()

    expect(buttonByText('Aprobar')).toBeDefined()
    expect(buttonByText('Rechazar')).toBeDefined()
    expect(buttonByText('Cancelar')).toBeUndefined()
    expect(first.wrapper.findAll('tbody tr')[1]!.text()).not.toContain('Aprobar')

    first.wrapper.unmount()
    document.body.innerHTML = ''
    session.__setPermissions(['matricula.leer', 'matricula.actualizar'])
    await open()

    expect(buttonByText('Aprobar')).toBeUndefined()
    expect(buttonByText('Cancelar')).toBeDefined()
  })

  it('con un curso de solo lectura (cerrado) no hay ninguna acción y se avisa', async () => {
    session.__setPermissions(MANAGE)

    const { wrapper } = await open('/alumnado/cambios-de-grupo?academic_year=Y1')

    expect(wrapper.text()).toContain('solo lectura')
    expect(buttonByText('Aprobar')).toBeUndefined()
    expect(buttonByText('Rechazar')).toBeUndefined()
    expect(buttonByText('Cancelar')).toBeUndefined()
  })
})

describe('CA-ALUM-070 (RN-ALUM-42, RN-ALUM-43, RN-ALUM-45): aprobar, rechazar y cancelar', () => {
  it('aprobar: diálogo con foco dentro, nota opcional, envía y recarga con aviso', async () => {
    session.__setPermissions(MANAGE)
    vi.mocked(alumApi.approveGroupChangeRequest).mockResolvedValue({} as never)

    const { wrapper } = await open()
    const loads = vi.mocked(alumApi.listGroupChangeRequests).mock.calls.length

    await buttonByText('Aprobar')!.click()
    await flushPromises()

    expect(dialog()!.getAttribute('role')).toBe('dialog')
    expect(dialog()!.contains(document.activeElement)).toBe(true)
    expect(dialog()!.textContent).toContain('Lucas Ejemplo Ruiz')

    await submitForm('alum-group-change-decision')

    expect(alumApi.approveGroupChangeRequest).toHaveBeenCalledWith('R1', {})
    expect(dialog()).toBeNull()
    expect(wrapper.text()).toContain('La solicitud se ha aprobado.')
    expect(vi.mocked(alumApi.listGroupChangeRequests).mock.calls.length).toBeGreaterThan(loads)
  })

  it('aprobar con nota la envía recortada', async () => {
    session.__setPermissions(MANAGE)
    vi.mocked(alumApi.approveGroupChangeRequest).mockResolvedValue({} as never)

    await open()
    await buttonByText('Aprobar')!.click()
    await flushPromises()
    await fill('#alum-gcd-note', '  De acuerdo  ')
    await submitForm('alum-group-change-decision')

    expect(alumApi.approveGroupChangeRequest).toHaveBeenCalledWith('R1', {
      decision_note: 'De acuerdo',
    })
  })

  it('rechazar: la nota es obligatoria (envío deshabilitado sin ella) y se envía', async () => {
    session.__setPermissions(MANAGE)
    vi.mocked(alumApi.rejectGroupChangeRequest).mockResolvedValue({} as never)

    const { wrapper } = await open()

    await buttonByText('Rechazar')!.click()
    await flushPromises()

    const submit = () =>
      document.body.querySelector<HTMLButtonElement>(
        '[data-slot="alum-group-change-decision"] button[type="submit"]',
      )!

    expect(document.body.querySelector('#alum-gcd-note')!.hasAttribute('required')).toBe(true)
    expect(submit().disabled).toBe(true)

    await fill('#alum-gcd-note', 'No hay plazas')

    expect(submit().disabled).toBe(false)

    await submitForm('alum-group-change-decision')

    expect(alumApi.rejectGroupChangeRequest).toHaveBeenCalledWith('R1', {
      decision_note: 'No hay plazas',
    })
    expect(wrapper.text()).toContain('La solicitud se ha rechazado.')
  })

  it('un 422 decision_note_required se pinta junto al campo con aria-describedby y aria-invalid', async () => {
    session.__setPermissions(MANAGE)
    vi.mocked(alumApi.rejectGroupChangeRequest).mockRejectedValue(
      problem(422, undefined, {
        decision_note: [{ code: 'x', message: 'Indique el motivo del rechazo.' }],
      }),
    )

    await open()
    await buttonByText('Rechazar')!.click()
    await flushPromises()
    await fill('#alum-gcd-note', 'x')
    await submitForm('alum-group-change-decision')

    const note = document.body.querySelector('#alum-gcd-note')!

    expect(dialog()!.textContent).toContain('Indique el motivo del rechazo.')
    expect(note.getAttribute('aria-invalid')).toBe('true')
    expect(note.getAttribute('aria-describedby')).toContain('alum-gcd-note-errors')
    expect(document.body.querySelector('#alum-gcd-note-errors')).not.toBeNull()
  })

  it('cancelar: nota opcional, envía a cancelation y avisa', async () => {
    session.__setPermissions(MANAGE)
    vi.mocked(alumApi.cancelGroupChangeRequest).mockResolvedValue({} as never)

    const { wrapper } = await open()

    await buttonByText('Cancelar')!.click()
    await flushPromises()

    expect(document.body.querySelector('#alum-gcd-note')!.hasAttribute('required')).toBe(false)

    await submitForm('alum-group-change-decision')

    expect(alumApi.cancelGroupChangeRequest).toHaveBeenCalledWith('R1', {})
    expect(wrapper.text()).toContain('La solicitud se ha cancelado.')
  })

  it.each([
    [403, 'Quien solicita no puede aprobar su propia solicitud.', false],
    [409, 'La solicitud ya no está pendiente.', true],
    [409, 'La matrícula ha cambiado.', true],
    [409, 'El curso académico está cerrado.', true],
  ])(
    'el error %i del servidor se muestra en el diálogo, que sigue abierto (recarga: %s)',
    async (status, detail, reloads) => {
      session.__setPermissions(MANAGE)
      vi.mocked(alumApi.approveGroupChangeRequest).mockRejectedValue(problem(status, detail))

      await open()

      const loads = vi.mocked(alumApi.listGroupChangeRequests).mock.calls.length

      await buttonByText('Aprobar')!.click()
      await flushPromises()
      await submitForm('alum-group-change-decision')

      expect(dialog()!.textContent).toContain(detail)
      expect(dialog()!.querySelector('p[role="alert"]')?.textContent).toContain(detail)
      expect(vi.mocked(alumApi.listGroupChangeRequests).mock.calls.length > loads).toBe(reloads)
    },
  )

  it('cancelar el diálogo no envía nada y el foco vuelve al botón de la fila', async () => {
    session.__setPermissions(MANAGE)

    await open()

    const trigger = buttonByText('Aprobar')!

    trigger.focus()
    await trigger.click()
    await flushPromises()
    ;[...dialog()!.querySelectorAll<HTMLButtonElement>('button')]
      .find((button) => button.textContent?.trim() === 'Cancelar')!
      .click()
    await flushPromises()

    expect(alumApi.approveGroupChangeRequest).not.toHaveBeenCalled()
    expect(dialog()).toBeNull()
    expect(document.activeElement).toBe(trigger)
  })
})
