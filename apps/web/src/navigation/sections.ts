import type { SectionId } from './types'

export interface SectionDefinition {
  id: SectionId
  labelKey: string
}

/**
 * `ADR-053 §4.2`. Catálogo cerrado de aplicación, en orden: el orden de
 * esta lista es el orden en que se pintan las secciones del menú
 * (`ADR-053 §4.3`). Un módulo futuro no añade la suya: se amplía este
 * fichero en el paso del primer módulo que la necesite, con su propia
 * justificación (`funcional.md §12.5`).
 */
export const SECTIONS: readonly SectionDefinition[] = [
  { id: 'inicio', labelKey: 'shell.nav.section.inicio' },
  { id: 'cuenta', labelKey: 'shell.nav.section.cuenta' },
  { id: 'administracion', labelKey: 'shell.nav.section.administracion' },
]

export const SECTION_IDS: readonly SectionId[] = SECTIONS.map((section) => section.id)

export function isKnownSection(id: string): id is SectionId {
  return SECTION_IDS.includes(id as SectionId)
}
