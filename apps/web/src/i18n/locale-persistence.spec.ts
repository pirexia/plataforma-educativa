/**
 * Issue #674, `RN-UI-15`: aplicar un idioma no lo persiste; solo la
 * elección explícita (`setLocale`) escribe `plataforma.locale`.
 */
import { beforeEach, describe, expect, it } from 'vitest'
import { applyLocale, setLocale } from '@/i18n'

beforeEach(() => {
  window.localStorage.clear()
})

describe('applyLocale / setLocale — #674', () => {
  it('applyLocale cambia el idioma y el lang del documento sin escribir plataforma.locale', () => {
    applyLocale('fr')

    expect(document.documentElement.lang).toBe('fr')
    expect(window.localStorage.getItem('plataforma.locale')).toBeNull()
  })

  it('setLocale (elección explícita) sí lo guarda', () => {
    setLocale('de')

    expect(document.documentElement.lang).toBe('de')
    expect(window.localStorage.getItem('plataforma.locale')).toBe('de')
    setLocale('es')
  })
})
