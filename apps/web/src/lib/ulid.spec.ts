/**
 * `docs/modulos/REQ-CORE/funcional.md §14.6.2`, `RN-CORE-73`, `ADR-038 §8`:
 * la `Idempotency-Key` es un ULID generado sin dependencia nueva.
 */
import { describe, expect, it } from 'vitest'
import { ulid } from './ulid'

describe('ulid (RN-CORE-73)', () => {
  it('tiene 26 caracteres Crockford base32 y un primer carácter válido (≤ 7)', () => {
    for (let index = 0; index < 50; index++) {
      expect(ulid()).toMatch(/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/)
    }
  })

  it('codifica la marca de tiempo en los 10 primeros caracteres, en orden', () => {
    expect(ulid(0).slice(0, 10)).toBe('0000000000')
    expect(ulid(1).slice(0, 10)).toBe('0000000001')
    expect(ulid(32).slice(0, 10)).toBe('0000000010')
    expect(ulid(1_000).slice(0, 10) < ulid(2_000).slice(0, 10)).toBe(true)
  })

  it('no repite claves', () => {
    const keys = new Set(Array.from({ length: 500 }, () => ulid(1_700_000_000_000)))

    expect(keys.size).toBe(500)
  })
})
