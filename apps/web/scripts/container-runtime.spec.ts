import { describe, it, expect } from 'vitest'
import { resolveContainerRuntime } from './container-runtime'

// ADR-080 §5, CS-03: lista cerrada de runtimes y orden CONTAINER_RUNTIME -> CONTAINER_CLI -> podman.
describe('resolveContainerRuntime (ADR-080 §5, CS-03)', () => {
  it('sin variables usa podman', () => {
    expect(resolveContainerRuntime({})).toBe('podman')
  })
  it('CONTAINER_RUNTIME=docker usa docker', () => {
    expect(resolveContainerRuntime({ CONTAINER_RUNTIME: 'docker' })).toBe('docker')
  })
  it('solo CONTAINER_CLI=docker (alias) usa docker', () => {
    expect(resolveContainerRuntime({ CONTAINER_CLI: 'docker' })).toBe('docker')
  })
  it('ambas iguales valen', () => {
    expect(resolveContainerRuntime({ CONTAINER_RUNTIME: 'docker', CONTAINER_CLI: 'docker' })).toBe(
      'docker',
    )
  })
  it('si difieren, aborta', () => {
    expect(() =>
      resolveContainerRuntime({ CONTAINER_RUNTIME: 'podman', CONTAINER_CLI: 'docker' }),
    ).toThrow(/difieren/)
  })
  it.each(['rm', 'podman; ls', '  ', 'Docker', 'nerdctl'])(
    'valor no admitido «%s» aborta nombrando la variable',
    (value) => {
      expect(() => resolveContainerRuntime({ CONTAINER_RUNTIME: value })).toThrow(
        /CONTAINER_RUNTIME/,
      )
      expect(() => resolveContainerRuntime({ CONTAINER_CLI: value })).toThrow(/CONTAINER_RUNTIME/)
    },
  )
})
