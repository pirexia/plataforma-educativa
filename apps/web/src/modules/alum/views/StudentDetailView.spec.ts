/**
 * `docs/modulos/REQ-ALUM/funcional.md §10`, `CA-ALUM-070` (la ficha oculta las secciones de `fam_unit` sin sus permisos;
 * sin acciones de escritura con un curso de solo lectura, `RN-ACAD-42`; la subida de foto muestra el error de tipo o
 * tamaño), `CA-ALUM-071` (accesibilidad), `RN-ALUM-10`, `RN-ALUM-50` a `RN-ALUM-52`, `RN-ALUM-64`, `INV-002`.
 * `REQ-ALUM-001`.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { setLocale } from '@/i18n'
import * as alumApi from '../api'
import * as cursoApi from '@/modules/curso/api'
import * as famApi from '@/modules/fam-unit/api'
import {
  buttonByText,
  consent,
  fill,
  link,
  mountView,
  page,
  problem,
  stubMatchMedia,
  student,
  submitForm,
  year,
} from '../testSupport'

vi.mock('../api')
vi.mock('@/modules/curso/api')
vi.mock('@/modules/fam-unit/api')
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
const { default: StudentDetailView } = await import('./StudentDetailView.vue')

const wrappers: VueWrapper[] = []

const ALL = [
  'alumno.leer',
  'alumno.actualizar',
  'alumno.eliminar',
  'vinculo_tutor.leer',
  'consentimiento_imagen.leer',
]

async function open(url = '/alumnado/S1') {
  const mounted = await mountView('alum-student-detail', StudentDetailView, url)

  wrappers.push(mounted.wrapper)

  return mounted
}

function chooseFile(file: File): void {
  const input = document.body.querySelector<HTMLInputElement>('input[type="file"]')!

  Object.defineProperty(input, 'files', { configurable: true, value: [file] })
  input.dispatchEvent(new Event('change', { bubbles: true }))
}

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
  for (const api of [alumApi, famApi]) {
    for (const fn of Object.values(api)) {
      if (typeof fn === 'function' && 'mockReset' in fn)
        (fn as ReturnType<typeof vi.fn>).mockReset()
    }
  }
  vi.mocked(alumApi.getStudent).mockResolvedValue(student() as never)
  vi.mocked(alumApi.listStudentGuardianLinks).mockResolvedValue(page([link()]) as never)
  vi.mocked(famApi.listImageConsents).mockResolvedValue(page([consent()]) as never)
  session.__setPermissions(ALL)
})

afterEach(() => {
  while (wrappers.length > 0) wrappers.pop()!.unmount()
  document.body.innerHTML = ''
})

describe('REQ-ALUM-001 / RN-ALUM-02: datos personales', () => {
  it('pinta los datos y el documento enmascarado, nunca completo', async () => {
    const { wrapper } = await open()

    expect(alumApi.getStudent).toHaveBeenCalledWith('S1')
    expect(wrapper.find('h1').text()).toBe('Lucas Ejemplo Ruiz')
    expect(wrapper.text()).toContain('•••••6789')
    expect(wrapper.text()).toContain('Ejemplo')
    expect(wrapper.text()).toContain('DNI')
  })

  it('RN-ALUM-64: un 404 (inexistente, baja o fuera de ámbito) pinta el estado de error', async () => {
    vi.mocked(alumApi.getStudent).mockRejectedValue(problem(404))

    const { wrapper } = await open()

    expect(wrapper.find('h1').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Lucas')
  })
})

describe('CA-ALUM-070: la ficha oculta las secciones de fam_unit sin sus permisos', () => {
  it('sin vinculo_tutor.leer ni consentimiento_imagen.leer no hay esas secciones', async () => {
    session.__setPermissions(['alumno.leer'])

    const { wrapper } = await open()

    expect(wrapper.find('#alum-guardians').exists()).toBe(false)
    expect(wrapper.find('#alum-consents').exists()).toBe(false)
    expect(alumApi.listStudentGuardianLinks).not.toHaveBeenCalled()
    expect(famApi.listImageConsents).not.toHaveBeenCalled()
  })

  it('con ambos permisos las secciones existen pero no leen al montar (lectura auditada bajo demanda)', async () => {
    const { wrapper } = await open()

    expect(wrapper.find('#alum-guardians').exists()).toBe(true)
    expect(wrapper.find('#alum-consents').exists()).toBe(true)
    expect(alumApi.listStudentGuardianLinks).not.toHaveBeenCalled()
    expect(famApi.listImageConsents).not.toHaveBeenCalled()
  })

  it('al pedirlos, los vínculos se leen por la persona del alumno y se pintan con texto', async () => {
    vi.mocked(alumApi.listStudentGuardianLinks).mockResolvedValue(
      page([
        link(),
        link({
          public_id: 'L2',
          relationship: 'abuelo',
          is_emergency_contact: false,
          status: 'revocado',
        }),
      ]) as never,
    )

    const { wrapper } = await open()

    await buttonByText('Mostrar vínculos')!.click()
    await flushPromises()

    expect(alumApi.listStudentGuardianLinks).toHaveBeenCalledWith('P1', { per_page: 100 })

    const rows = wrapper
      .findAll('section[aria-labelledby="alum-guardians"] tbody tr')
      .map((row) => row.text())

    expect(rows[0]).toContain('Madre')
    expect(rows[0]).toContain('Activo')
    expect(rows[1]).toContain('Abuelo/a')
    expect(rows[1]).toContain('Revocado')
  })

  it('al pedirlos, el estado de imagen se lee por la persona del alumno', async () => {
    const { wrapper } = await open()

    await buttonByText('Mostrar consentimientos')!.click()
    await flushPromises()

    expect(famApi.listImageConsents).toHaveBeenCalledWith({ student_person: 'P1', per_page: 100 })
    expect(wrapper.text()).toContain('Web del centro')
    expect(wrapper.text()).toContain('Autorizo')
  })

  it('un 403 al pedir los vínculos se muestra junto a la sección con el mensaje del servidor', async () => {
    vi.mocked(alumApi.listStudentGuardianLinks).mockRejectedValue(problem(403, 'No tiene permiso.'))

    const { wrapper } = await open()

    await buttonByText('Mostrar vínculos')!.click()
    await flushPromises()

    expect(wrapper.find('#alum-guardians ~ p[role="alert"]').text()).toBe('No tiene permiso.')
  })
})

describe('CA-ALUM-070 / RN-ACAD-42: sin acciones de escritura con un curso de solo lectura', () => {
  it('con un curso escribible y permisos hay corregir, baja y subida de foto', async () => {
    const { wrapper } = await open()

    expect(wrapper.text()).toContain('Corregir datos')
    expect(wrapper.text()).toContain('Dar de baja')
    expect(document.body.querySelector('input[type="file"]')).not.toBeNull()
  })

  it('con un curso cerrado no hay ninguna acción de escritura aunque se tengan los permisos', async () => {
    vi.mocked(alumApi.getStudent).mockResolvedValue(student({ has_photo: true }) as never)

    const { wrapper } = await open('/alumnado/S1?academic_year=Y1')

    expect(wrapper.text()).not.toContain('Corregir datos')
    expect(wrapper.text()).not.toContain('Dar de baja')
    expect(wrapper.text()).not.toContain('Sustituir')
    expect(wrapper.text()).not.toContain('Quitar')
    expect(document.body.querySelector('input[type="file"]')).toBeNull()
    expect(wrapper.find('[role="status"]').text()).toContain('solo lectura')
  })

  it('INV-002: sin alumno.actualizar ni alumno.eliminar no hay acciones', async () => {
    session.__setPermissions(['alumno.leer'])

    const { wrapper } = await open()

    expect(wrapper.text()).not.toContain('Corregir datos')
    expect(wrapper.text()).not.toContain('Dar de baja')
    expect(document.body.querySelector('input[type="file"]')).toBeNull()
  })
})

describe('RN-ALUM-10: corrección de datos', () => {
  it('solo envía las claves modificadas y no precarga el documento enmascarado', async () => {
    vi.mocked(alumApi.updateStudent).mockResolvedValue(
      student({ family_name_2: 'Vidal', display_name: 'Lucas Ejemplo Vidal' }) as never,
    )

    const { wrapper } = await open()

    await buttonByText('Corregir datos')!.click()
    await flushPromises()

    expect(
      document.body.querySelector<HTMLInputElement>('#alum-student-document_number')!.value,
    ).toBe('')
    expect(document.body.textContent).toContain('•••••6789')

    await fill('#alum-student-family_name_2', 'Vidal')
    await submitForm('alum-student-form')

    expect(alumApi.updateStudent).toHaveBeenCalledWith('S1', { family_name_2: 'Vidal' })
    expect(wrapper.text()).toContain('Los datos del alumno se han guardado.')
  })

  it('sin cambios el botón de guardar está deshabilitado', async () => {
    await open()

    await buttonByText('Corregir datos')!.click()
    await flushPromises()

    expect(buttonByText('Guardar cambios')!.disabled).toBe(true)
  })
})

describe('RN-ALUM-05: baja del alumno', () => {
  it('pide confirmación con el nombre y solo entonces borra y vuelve al listado', async () => {
    vi.mocked(alumApi.deleteStudent).mockResolvedValue(null)

    const { router } = await open()

    await buttonByText('Dar de baja')!.click()
    await flushPromises()
    expect(alumApi.deleteStudent).not.toHaveBeenCalled()

    await buttonByText('Dar de baja a Lucas Ejemplo Ruiz')!.click()
    await flushPromises()

    expect(alumApi.deleteStudent).toHaveBeenCalledWith('S1')
    expect(router.currentRoute.value.name).toBe('alum-students')
  })

  it('un 409 (matrícula activa) se muestra junto a la acción y la ficha no cambia', async () => {
    vi.mocked(alumApi.deleteStudent).mockRejectedValue(
      problem(409, 'El alumno tiene matrículas activas.'),
    )

    const { wrapper, router } = await open()

    await buttonByText('Dar de baja')!.click()
    await flushPromises()
    await buttonByText('Dar de baja a Lucas Ejemplo Ruiz')!.click()
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toContain('El alumno tiene matrículas activas.')
    expect(router.currentRoute.value.name).toBe('alum-student-detail')
  })
})

describe('CA-ALUM-070 / RN-ALUM-50 a RN-ALUM-52: fotografía', () => {
  it('sin foto no se pide ninguna URL; la subida acepta JPEG o PNG', async () => {
    vi.mocked(alumApi.putStudentPhoto).mockResolvedValue({ has_photo: true })
    vi.mocked(alumApi.getStudent)
      .mockResolvedValueOnce(student() as never)
      .mockResolvedValue(student({ has_photo: true }) as never)

    const { wrapper } = await open()

    expect(alumApi.getStudentPhotoUrl).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('El alumno no tiene fotografía.')

    chooseFile(new File(['x'], 'foto.png', { type: 'image/png' }))
    await flushPromises()
    await buttonByText('Subir fotografía')!.click()
    await flushPromises()

    expect(alumApi.putStudentPhoto).toHaveBeenCalledTimes(1)
    expect(vi.mocked(alumApi.putStudentPhoto).mock.calls[0]![0]).toBe('S1')
    expect(wrapper.text()).toContain('La fotografía se ha guardado.')
    expect(alumApi.getStudent).toHaveBeenCalledTimes(2)
  })

  it('un fichero de otro tipo se rechaza en cliente con el error de tipo, sin llamar a la API', async () => {
    await open()

    chooseFile(new File(['x'], 'foto.webp', { type: 'image/webp' }))
    await flushPromises()

    const input = document.body.querySelector('input[type="file"]')!
    const errors = document.getElementById(input.getAttribute('aria-describedby')!.split(' ')[1]!)

    expect(input.getAttribute('aria-invalid')).toBe('true')
    expect(errors?.getAttribute('role')).toBe('alert')
    expect(errors?.textContent).toContain('JPEG o PNG')
    expect(buttonByText('Subir fotografía')!.disabled).toBe(true)
    expect(alumApi.putStudentPhoto).not.toHaveBeenCalled()
  })

  it('un fichero de más de 5 MB se rechaza en cliente con el error de tamaño', async () => {
    await open()

    const big = new File(['x'], 'foto.jpg', { type: 'image/jpeg' })

    Object.defineProperty(big, 'size', { value: 6 * 1024 * 1024 })
    chooseFile(big)
    await flushPromises()

    expect(document.body.querySelector('[role="alert"]')?.textContent).toContain('5 MB')
    expect(alumApi.putStudentPhoto).not.toHaveBeenCalled()
  })

  it('el 422 del servidor (alum.photo.*) se muestra junto al campo con su mensaje', async () => {
    vi.mocked(alumApi.putStudentPhoto).mockRejectedValue(
      problem(422, undefined, {
        file: [{ code: 'alum.photo.invalid_image', message: 'La imagen no se puede leer.' }],
      }),
    )

    await open()

    chooseFile(new File(['x'], 'foto.png', { type: 'image/png' }))
    await flushPromises()
    await buttonByText('Subir fotografía')!.click()
    await flushPromises()

    const input = document.body.querySelector('input[type="file"]')!

    expect(input.getAttribute('aria-invalid')).toBe('true')
    expect(document.body.textContent).toContain('La imagen no se puede leer.')
  })

  it('con foto, la URL firmada solo se pide al pulsar «Mostrar» y se pinta sin referrer', async () => {
    vi.mocked(alumApi.getStudent).mockResolvedValue(student({ has_photo: true }) as never)
    vi.mocked(alumApi.getStudentPhotoUrl).mockResolvedValue({
      url: 'https://almacen.example.com/firmada/s1.jpg?firma=abc',
      expires_at: new Date(Date.now() + 300_000).toISOString(),
    })

    const { wrapper } = await open()

    expect(alumApi.getStudentPhotoUrl).not.toHaveBeenCalled()
    expect(wrapper.find('img').exists()).toBe(false)

    await buttonByText('Mostrar fotografía')!.click()
    await flushPromises()

    const img = wrapper.find('img')

    expect(alumApi.getStudentPhotoUrl).toHaveBeenCalledWith('S1')
    expect(img.attributes('src')).toBe('https://almacen.example.com/firmada/s1.jpg?firma=abc')
    expect(img.attributes('referrerpolicy')).toBe('no-referrer')
    expect(img.attributes('alt')).toBe('Fotografía de Lucas Ejemplo Ruiz')
    expect(window.localStorage.length).toBe(0)
  })

  it.each([
    ['vacío', ''],
    ['mal formado', 'no-es-una-fecha'],
    ['vencido por reloj desfasado', '2000-01-01T00:00:00Z'],
    ['lejano (1 día)', new Date(Date.now() + 86_400_000).toISOString()],
  ])(
    'CA-ALUM-070: con expires_at %s la imagen se retira con el tope de 5 minutos',
    async (_label, expiresAt) => {
      vi.mocked(alumApi.getStudent).mockResolvedValue(student({ has_photo: true }) as never)
      vi.mocked(alumApi.getStudentPhotoUrl).mockResolvedValue({
        url: 'https://almacen.example.com/firmada/s1.jpg?firma=abc',
        expires_at: expiresAt,
      })

      const { wrapper } = await open()

      vi.useFakeTimers()
      await buttonByText('Mostrar fotografía')!.click()
      await flushPromises()

      expect(wrapper.find('img').exists()).toBe(true)

      await vi.advanceTimersByTimeAsync(5 * 60 * 1000 - 1000)
      expect(wrapper.find('img').exists()).toBe(true)

      await vi.advanceTimersByTimeAsync(2000)
      await flushPromises()

      expect(wrapper.find('img').exists()).toBe(false)
      expect(wrapper.text()).toContain('El enlace de la fotografía ha caducado')
    },
  )

  it('CA-ALUM-070: con una caducidad válida más corta que el tope, manda la caducidad', async () => {
    vi.mocked(alumApi.getStudent).mockResolvedValue(student({ has_photo: true }) as never)
    vi.mocked(alumApi.getStudentPhotoUrl).mockResolvedValue({
      url: 'https://almacen.example.com/firmada/s1.jpg?firma=abc',
      expires_at: new Date(Date.now() + 60_000).toISOString(),
    })

    const { wrapper } = await open()

    vi.useFakeTimers()
    await buttonByText('Mostrar fotografía')!.click()
    await flushPromises()
    await vi.advanceTimersByTimeAsync(61_000)
    await flushPromises()

    expect(wrapper.find('img').exists()).toBe(false)
  })

  it('CA-ALUM-070 (INV-002): al cambiar :publicId se descartan datos, foto, vínculos y consentimientos del anterior', async () => {
    vi.mocked(alumApi.getStudent).mockImplementation(((id: string) =>
      Promise.resolve(
        id === 'S1'
          ? student({ has_photo: true })
          : student({
              public_id: 'S9',
              person: { public_id: 'P9' },
              display_name: 'Ana Ejemplo Vidal',
              has_photo: false,
            }),
      )) as never)
    vi.mocked(alumApi.getStudentPhotoUrl).mockResolvedValue({
      url: 'https://almacen.example.com/firmada/s1.jpg?firma=abc',
      expires_at: new Date(Date.now() + 300_000).toISOString(),
    })
    session.__setPermissions(['alumno.leer', 'vinculo_tutor.leer', 'consentimiento_imagen.leer'])

    const { wrapper, router } = await open()

    await buttonByText('Mostrar fotografía')!.click()
    await flushPromises()
    await buttonByText('Mostrar vínculos')!.click()
    await flushPromises()

    expect(wrapper.find('img').exists()).toBe(true)
    expect(wrapper.text()).toContain('Lucas Ejemplo Ruiz')
    expect(wrapper.text()).toContain('Madre')

    await router.push('/alumnado/S9')
    await flushPromises()

    expect(alumApi.getStudent).toHaveBeenLastCalledWith('S9')
    expect(wrapper.text()).toContain('Ana Ejemplo Vidal')
    expect(wrapper.text()).not.toContain('Lucas Ejemplo Ruiz')
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Madre')
    expect(buttonByText('Mostrar vínculos')).toBeDefined()
  })

  it('un 429 al pedir la URL muestra el mensaje del servidor y no pinta imagen', async () => {
    vi.mocked(alumApi.getStudent).mockResolvedValue(student({ has_photo: true }) as never)
    vi.mocked(alumApi.getStudentPhotoUrl).mockRejectedValue(
      problem(429, 'Demasiadas consultas de fotografías.'),
    )

    const { wrapper } = await open()

    await buttonByText('Mostrar fotografía')!.click()
    await flushPromises()

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.text()).toContain('Demasiadas consultas de fotografías.')
  })

  it('quitar la foto pide confirmación y recarga la ficha', async () => {
    vi.mocked(alumApi.getStudent)
      .mockResolvedValueOnce(student({ has_photo: true }) as never)
      .mockResolvedValue(student() as never)
    vi.mocked(alumApi.deleteStudentPhoto).mockResolvedValue(null)

    const { wrapper } = await open()

    await buttonByText('Quitar')!.click()
    await flushPromises()
    expect(alumApi.deleteStudentPhoto).not.toHaveBeenCalled()

    await buttonByText('Quitar la fotografía de Lucas Ejemplo Ruiz')!.click()
    await flushPromises()

    expect(alumApi.deleteStudentPhoto).toHaveBeenCalledWith('S1')
    expect(wrapper.text()).toContain('La fotografía se ha quitado.')
    expect(wrapper.text()).toContain('El alumno no tiene fotografía.')
  })
})

describe('CA-ALUM-071: accesibilidad de la ficha', () => {
  it('cada sección está nombrada por su encabezado y los iconos decorativos van ocultos', async () => {
    const { wrapper } = await open()

    for (const section of wrapper.findAll('section')) {
      const heading = wrapper.find(`#${section.attributes('aria-labelledby')}`)

      expect(heading.exists()).toBe(true)
      expect(heading.element.tagName).toBe('H2')
    }

    await buttonByText('Mostrar vínculos')!.click()
    await flushPromises()

    for (const icon of wrapper.findAll('section[aria-labelledby="alum-guardians"] tbody svg')) {
      expect(icon.attributes('aria-hidden')).toBe('true')
    }
  })

  it('el diálogo de corrección tiene título, etiquetas por campo y un error asociado por aria-describedby', async () => {
    vi.mocked(alumApi.updateStudent).mockRejectedValue(
      problem(422, undefined, {
        document_number: [
          { code: 'core.validation.document_invalid', message: 'El documento no es válido.' },
        ],
      }),
    )

    await open()
    await buttonByText('Corregir datos')!.click()
    await flushPromises()

    expect(document.body.querySelector('[role="dialog"] h2')?.textContent).toContain(
      'Corregir datos del alumno',
    )
    expect(document.body.querySelector('label[for="alum-student-document_number"]')).not.toBeNull()

    await fill('#alum-student-document_number', 'X')
    await submitForm('alum-student-form')

    const input = document.body.querySelector('#alum-student-document_number')!
    const ids = input.getAttribute('aria-describedby')!.split(' ')

    expect(input.getAttribute('aria-invalid')).toBe('true')
    expect(
      ids.some((id) =>
        document.getElementById(id)?.textContent?.includes('El documento no es válido.'),
      ),
    ).toBe(true)
  })
})
