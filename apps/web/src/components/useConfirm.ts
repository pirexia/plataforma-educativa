/**
 * `docs/modulos/REQ-CORE/funcional.md §14.14`, `RN-CORE-64`, `OPEN-CORE-42`
 * (= A). Estado de la confirmación accesible de acciones destructivas o
 * masivas: una vista llama a `ask(...)`, que abre `ConfirmDialog` y se
 * resuelve con `true` (confirmó) o `false` (canceló, `Esc` o clic fuera).
 *
 * Una instancia por vista: la petición de la acción solo sale si la
 * promesa se resuelve a `true` (`CA-CORE-220`: «no sale ninguna petición
 * hasta confirmar»).
 */
import { ref } from 'vue'

export interface ConfirmRequest {
  /** Nombra la entidad afectada y la acción («Dar de baja a Ana López»). */
  title: string
  /** La consecuencia, en una frase. */
  description: string
  /**
   * Ampliación aditiva de 1.5b (`REQ-PERM/funcional.md RN-PERM-36` punto 2): una
   * lista bajo la descripción (p. ej. los cambios que se van a guardar). Sin ella,
   * el diálogo es el de siempre.
   */
  details?: readonly string[]
  /** Botón de confirmar: incluye la identidad de la fila (`RN-CORE-64`, WCAG 2.4.6). */
  confirmLabel: string
  /** Por defecto, el «Cancelar» común (`shell.confirm.cancel`). */
  cancelLabel?: string
  /** Acción destructiva: botón con la variante `destructive`. */
  destructive?: boolean
}

export function useConfirm() {
  const open = ref(false)
  const request = ref<ConfirmRequest | null>(null)
  let resolver: ((confirmed: boolean) => void) | null = null

  function settle(confirmed: boolean): void {
    open.value = false

    const resolve = resolver

    resolver = null
    resolve?.(confirmed)
  }

  function ask(next: ConfirmRequest): Promise<boolean> {
    // Una confirmación abierta se cancela si llega otra: nunca quedan dos.
    if (resolver !== null) {
      settle(false)
    }

    request.value = next
    open.value = true

    return new Promise<boolean>((resolve) => {
      resolver = resolve
    })
  }

  return {
    open,
    request,
    ask,
    confirm: (): void => settle(true),
    cancel: (): void => settle(false),
  }
}
