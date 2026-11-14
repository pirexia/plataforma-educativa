/**
 * Respuesta de un `PATCH` sobre una alerta ya mostrada. Abierta: se conservan el protocolo y los teléfonos de la
 * lectura anterior si la respuesta no los trae; cerrada: la respuesta manda y no lleva teléfonos ni protocolo (`DP-5`).
 */
import type { PickupAlert } from '../types'

export function mergeAlert(previous: PickupAlert | null, next: PickupAlert): PickupAlert {
  if (previous === null || next.status !== 'abierta') {
    return next
  }

  return { ...previous, ...next }
}
