/**
 * `ADR-062 §5.2`, `§5.3`, `§5.7` (REQ-ACAD-001, `CA-ACAD-016`): lógica pura del
 * campo traducible de la SPA.
 */
import { describe, expect, it } from 'vitest'
import {
  cleanTranslations,
  orderedLocales,
  provenanceLocale,
  translationsPatch,
} from './translatable'

describe('CA-ACAD-016 orderedLocales: el idioma por defecto primero', () => {
  it('pone el por defecto delante aunque no sea es-ES y respeta el orden canónico', () => {
    expect(orderedLocales('de', ['fr', 'es-ES', 'de'])).toEqual(['de', 'es-ES', 'fr'])
    expect(orderedLocales('es-ES', ['fr', 'en', 'es-ES'])).toEqual(['es-ES', 'en', 'fr'])
  })

  it('un centro con default en y solo en activo ofrece solo en (OPEN-062-01)', () => {
    expect(orderedLocales('en', ['en'])).toEqual(['en'])
  })
})

describe('translationsPatch: fusión por idioma (ADR-062 §5.2 punto 2)', () => {
  const locales = ['es-ES', 'en', 'fr'] as const

  it('envía solo los idiomas que cambian y null para los que se vacían', () => {
    const patch = translationsPatch(
      { 'es-ES': 'Lengua', en: 'Language', de: 'Sprache' },
      { 'es-ES': 'Lengua', en: '  ', fr: ' Langue ' },
      locales,
    )

    expect(patch).toEqual({ en: null, fr: 'Langue' })
  })

  it('no menciona un idioma retirado (de) aunque esté guardado', () => {
    expect(translationsPatch({ 'es-ES': 'A', de: 'B' }, { 'es-ES': 'A' }, locales)).toEqual({})
  })
})

describe('cleanTranslations', () => {
  it('recorta y descarta vacíos', () => {
    expect(cleanTranslations({ 'es-ES': ' x ', en: '' }, ['es-ES', 'en'])).toEqual({ 'es-ES': 'x' })
  })
})

describe('CA-ACAD-016 provenanceLocale: de qué idioma salió el nombre resuelto', () => {
  it('coincide con el idioma de la página', () => {
    expect(provenanceLocale('Language', { 'es-ES': 'Lengua', en: 'Language' }, 'en', 'es-ES')).toBe(
      'en',
    )
  })

  it('cae al idioma por defecto del centro', () => {
    expect(provenanceLocale('Lengua', { 'es-ES': 'Lengua', en: 'Language' }, 'de', 'es-ES')).toBe(
      'es-ES',
    )
  })

  it('cae a la primera clave del orden canónico', () => {
    expect(provenanceLocale('Sprache', { de: 'Sprache' }, 'fr', 'fr')).toBe('de')
  })

  it('texto desconocido: null', () => {
    expect(provenanceLocale('???', { en: 'x' }, 'en', 'en')).toBeNull()
  })
})
