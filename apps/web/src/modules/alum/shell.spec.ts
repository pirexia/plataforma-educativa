/**
 * `docs/modulos/REQ-ALUM/funcional.md §10`, `ADR-053`, `AR-11` (lo vigila además `src/modules/architecture.spec.ts`):
 * registro del módulo `alum` y sus rutas de personal. `1.15-d` T1, `REQ-ALUM-001`.
 */
import { describe, expect, it } from 'vitest'
import router from '@/router'
import { moduleShells } from '@/navigation/modules'
import { shell } from './shell'

describe('REQ-ALUM-001 (1.15-d T1): rutas de alumnado y entradas de navegación', () => {
  function routeOf(name: string) {
    const route = router.getRoutes().find((candidate) => candidate.name === name)

    expect(route, `no existe la ruta ${name}`).toBeDefined()

    return route!
  }

  it.each([
    ['alum-students', '/alumnado', ['alumno.leer']],
    ['alum-class-list', '/alumnado/listas', ['matricula.leer']],
    ['alum-group-changes', '/alumnado/cambios-de-grupo', ['matricula.leer']],
    ['alum-student-detail', '/alumnado/:publicId', ['alumno.leer']],
  ])('%s es %s, layout app y exige exactamente %j (nunca [])', (name, path, permissions) => {
    const route = routeOf(name)

    expect(route.path).toBe(path)
    expect(route.meta.layout).toBe('app')
    expect(route.meta.permissions).toEqual(permissions)
  })

  it('el listado, la lista de clase y las solicitudes llevan el selector de curso académico y la ficha no', () => {
    expect(routeOf('alum-students').meta.academicYearScoped).toBe(true)
    expect(routeOf('alum-class-list').meta.academicYearScoped).toBe(true)
    expect(routeOf('alum-group-changes').meta.academicYearScoped).toBe(true)
    expect(routeOf('alum-student-detail').meta.academicYearScoped).toBeFalsy()
  })

  it('declara las entradas de menú, ligadas a rutas con permiso, todas en administración', () => {
    expect(shell.navigation.map((entry) => entry.route)).toEqual([
      'alum-students',
      'alum-class-list',
      'alum-group-changes',
    ])
    expect(shell.navigation.map((entry) => entry.labelKey)).toEqual([
      'alum.nav.students',
      'alum.nav.classList',
      'alum.nav.groupChanges',
    ])
    expect(shell.navigation.map((entry) => entry.section)).toEqual([
      'administracion',
      'administracion',
      'administracion',
    ])
  })

  it('no declara rutas con permissions [] ni bloques de panel', () => {
    expect(shell.routes.filter((route) => route.meta?.permissions?.length === 0)).toEqual([])
    expect(shell.dashboardBlocks).toEqual([])
  })

  it('está registrado en el registro de módulos', () => {
    expect(moduleShells).toContain(shell)
  })

  it('/alumnado/:publicId se resuelve a la ficha', () => {
    expect(router.resolve('/alumnado').name).toBe('alum-students')
    expect(router.resolve('/alumnado/01JB0000000000000000000001').name).toBe('alum-student-detail')
  })

  it('los segmentos fijos ganan al paramétrico (RN-ALUM-42..47)', () => {
    expect(router.resolve('/alumnado/listas').name).toBe('alum-class-list')
    expect(router.resolve('/alumnado/cambios-de-grupo').name).toBe('alum-group-changes')
  })
})
