/**
 * `docs/modulos/REQ-CORE/funcional.md` `RN-CORE-28`, `CA-CORE-091`.
 */
import { describe, expect, it } from 'vitest'
import router from '@/router'
import { buildRedirectQuery, sanitizeRedirect } from './redirect'

describe('sanitizeRedirect — CA-CORE-091', () => {
  it.each(['//evil.example', 'https://evil.example', '/\\evil.example', 'javascript:alert(1)'])(
    '%s se rechaza y cae a "/"',
    (value) => {
      expect(sanitizeRedirect(value, router)).toBe('/')
    },
  )

  it('una ruta inexistente cae a "/"', () => {
    expect(sanitizeRedirect('/esto/no/existe', router)).toBe('/')
  })

  it('null/undefined/vacío caen a "/"', () => {
    expect(sanitizeRedirect(null, router)).toBe('/')
    expect(sanitizeRedirect(undefined, router)).toBe('/')
    expect(sanitizeRedirect('', router)).toBe('/')
  })

  it('una ruta app registrada se acepta tal cual', () => {
    expect(sanitizeRedirect('/cuenta/sesiones', router)).toBe('/cuenta/sesiones')
  })

  it('una ruta pública (no app) se rechaza', () => {
    expect(sanitizeRedirect('/entrar', router)).toBe('/')
  })

  it('el muro de MFA (régimen bare) se rechaza como destino de redirect', () => {
    expect(sanitizeRedirect('/cuenta/seguridad/obligatorio', router)).toBe('/')
  })
})

describe('buildRedirectQuery', () => {
  it('envuelve la ruta actual en { redirect }', () => {
    expect(buildRedirectQuery('/cuenta/sesiones')).toEqual({ redirect: '/cuenta/sesiones' })
  })
})
