/**
 * `docs/modulos/REQ-CURSO/funcional.md §10.6` paso 2, `RN-CURSO-64`, `RN-CURSO-82`, `CA-CURSO-143`, `CA-CURSO-153`
 * (1.12b): lógica pura de la selección de elementos del asistente.
 */
import { describe, expect, it } from 'vitest'
import {
  blockedReason,
  bundleElements,
  deselectWithDependents,
  selectBundleRequires,
  selectWithRequires,
  selectableElements,
  transitiveRequires,
} from './rolloverSelection'
import type { RolloverElement } from './types'

function el(
  code: string,
  requires: string[] = [],
  overrides: Partial<RolloverElement> = {},
): RolloverElement {
  return { code, module: 'acad', label: code, requires, allowed: true, bundle: null, ...overrides }
}

const CATALOG: RolloverElement[] = [
  el('acad.education_levels'),
  el('acad.courses', ['acad.education_levels']),
  el('acad.groups', ['acad.courses']),
  el('acad.subgroups', ['acad.groups']),
  el('acad.subjects', ['acad.courses']),
  el('acad.group_subjects', ['acad.groups', 'acad.subjects']),
  el('acad.teaching_assignments', ['acad.group_subjects', 'acad.subgroups'], {
    bundle: 'teaching_assignments',
  }),
  el('acad.group_tutors', ['acad.groups'], { bundle: 'teaching_assignments' }),
  el('acad.timetable_sessions', ['acad.teaching_assignments'], { bundle: 'teaching_assignments' }),
]

describe('CA-CURSO-143 RN-CURSO-64: marcar y desmarcar con sus requisitos', () => {
  it('los requisitos transitivos de un elemento', () => {
    expect(transitiveRequires('acad.groups', CATALOG)).toEqual([
      'acad.courses',
      'acad.education_levels',
    ])
    expect(transitiveRequires('acad.education_levels', CATALOG)).toEqual([])
  })

  it('marcar acad.groups marca acad.courses y acad.education_levels y dice cuáles ha añadido', () => {
    const result = selectWithRequires('acad.groups', [], CATALOG)

    expect(new Set(result.selected)).toEqual(
      new Set(['acad.groups', 'acad.courses', 'acad.education_levels']),
    )
    expect(new Set(result.added)).toEqual(new Set(['acad.courses', 'acad.education_levels']))
  })

  it('no repite como añadido lo que ya estaba marcado', () => {
    const result = selectWithRequires(
      'acad.groups',
      ['acad.courses', 'acad.education_levels'],
      CATALOG,
    )

    expect(result.added).toEqual([])
  })

  it('desmarcar acad.courses desmarca los que lo requieren (grupos, asignaturas, subgrupos…)', () => {
    const all = CATALOG.filter((element) => element.bundle === null).map((element) => element.code)
    const result = deselectWithDependents('acad.courses', all, CATALOG, false)

    expect(result.selected).toEqual(['acad.education_levels'])
    expect(new Set(result.removed)).toEqual(
      new Set(['acad.groups', 'acad.subgroups', 'acad.subjects', 'acad.group_subjects']),
    )
    expect(result.bundleDropped).toBe(false)
  })

  it('desmarcar un requisito de la casilla de asignación docente la desmarca y lo avisa', () => {
    const selected = [
      'acad.education_levels',
      'acad.courses',
      'acad.groups',
      'acad.subgroups',
      'acad.subjects',
      'acad.group_subjects',
    ]
    const result = deselectWithDependents('acad.subgroups', selected, CATALOG, true)

    expect(result.bundleDropped).toBe(true)
    expect(result.selected).not.toContain('acad.subgroups')
  })

  it('desmarcar un requisito transitivo de la casilla (aquí, la etapa) también la desmarca', () => {
    const result = deselectWithDependents(
      'acad.education_levels',
      ['acad.education_levels'],
      CATALOG,
      true,
    )

    expect(result.bundleDropped).toBe(true)
  })
})

describe('CA-CURSO-153 / CA-ACAD-153 RN-CURSO-82: los elementos de paquete no son casillas sueltas', () => {
  it('separa los elementos elegibles de los del paquete', () => {
    expect(selectableElements(CATALOG)).toHaveLength(6)
    expect(bundleElements(CATALOG).map((element) => element.code)).toEqual([
      'acad.teaching_assignments',
      'acad.group_tutors',
      'acad.timetable_sessions',
    ])
  })

  it('marcar la casilla marca solo los requisitos que no son del paquete', () => {
    const result = selectBundleRequires([], CATALOG)

    expect(new Set(result.selected)).toEqual(
      new Set([
        'acad.group_subjects',
        'acad.subgroups',
        'acad.groups',
        'acad.subjects',
        'acad.courses',
        'acad.education_levels',
      ]),
    )
    expect(result.selected).not.toContain('acad.teaching_assignments')
    expect(result.added.length).toBe(6)
  })
})

describe('CA-CURSO-143 RN-CURSO-78: elementos no permitidos', () => {
  it('un elemento allowed:false está bloqueado, y también el que lo necesita', () => {
    const catalog = [el('a'), el('b', ['a'], { allowed: false }), el('c', ['b'])]

    expect(blockedReason('a', catalog)).toBeNull()
    expect(blockedReason('b', catalog)).toBe('forbidden')
    expect(blockedReason('c', catalog)).toBe('requiresForbidden')
  })
})
