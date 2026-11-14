/**
 * ULID mínimo (`ADR-029`, `ADR-038 §8`) para la `Idempotency-Key` de las
 * operaciones que la exigen. Sin dependencia nueva (`CLAUDE.md §1`,
 * `RNF-MANT-007`): 48 bits de marca de tiempo y 80 bits aleatorios en
 * Crockford base32, 26 caracteres, el formato que el servidor valida
 * (`^[0-9A-HJKMNP-TV-Z]{26}$`, primer carácter ≤ 7).
 *
 * No garantiza el orden monótono dentro del mismo milisegundo: la
 * `Idempotency-Key` solo necesita ser única e imposible de adivinar.
 */
const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'

export function ulid(now: number = Date.now()): string {
  let time = ''
  let remaining = now

  for (let index = 0; index < 10; index++) {
    time = ALPHABET[remaining % 32] + time
    remaining = Math.floor(remaining / 32)
  }

  const bytes = new Uint8Array(16)
  crypto.getRandomValues(bytes)

  let random = ''

  for (let index = 0; index < 16; index++) {
    random += ALPHABET[bytes[index]! % 32]
  }

  return time + random
}
