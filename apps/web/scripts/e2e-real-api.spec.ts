/**
 * `scripts/e2e-real-api.sh`: un `E2E_ONLY` no vacío y fuera de la lista cerrada se rechaza con código distinto de cero y un
 * mensaje claro, antes de tocar ningún contenedor (no puede salir en verde sin haber ejecutado ninguna suite).
 */
import { spawnSync } from 'node:child_process'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

describe('e2e-real-api.sh: E2E_ONLY', () => {
  it('rechaza un valor inválido (p. ej. famunit) con código 2 y mensaje', () => {
    const result = spawnSync('bash', [resolve(__dirname, 'e2e-real-api.sh')], {
      env: { ...process.env, E2E_ONLY: 'famunit', PATH: process.env.PATH },
      encoding: 'utf8',
    })

    expect(result.status).toBe(2)
    expect(result.stderr).toContain('E2E_ONLY no válido')
    expect(result.stderr).toContain('fam-unit')
  })
})
