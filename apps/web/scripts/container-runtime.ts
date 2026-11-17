/**
 * Runtime de contenedores de los E2E (ADR-080 §5.3, paso 0.10e-b). Espejo TypeScript de `infra/lib/container-runtime.sh`:
 * misma resolución (CONTAINER_RUNTIME -> alias CONTAINER_CLI -> podman) y misma lista cerrada (`podman`, `docker`).
 */
export type ContainerRuntime = 'podman' | 'docker'

export function resolveContainerRuntime(
  env: Record<string, string | undefined> = process.env,
): ContainerRuntime {
  const primary = env.CONTAINER_RUNTIME ?? ''
  const alias = env.CONTAINER_CLI ?? ''
  if (primary !== '' && alias !== '' && primary !== alias) {
    throw new Error(
      `CONTAINER_RUNTIME («${primary}») y CONTAINER_CLI («${alias}») difieren: define solo CONTAINER_RUNTIME.`,
    )
  }
  const value = primary || alias || 'podman'
  if (value !== 'podman' && value !== 'docker') {
    throw new Error(
      `CONTAINER_RUNTIME no válido: «${value}». Valores admitidos: podman, docker (por defecto podman).`,
    )
  }
  return value
}
