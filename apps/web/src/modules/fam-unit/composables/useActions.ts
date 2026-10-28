/**
 * Acciones de una pantalla (`funcional.md §10`): confirmación accesible (`RN-CORE-64`), estado de ocupado y mensajes de
 * resultado. Un `409`/`422`/`429` se muestra con el `detail` del servidor, ya traducido, junto a la acción; la pantalla
 * no cambia hasta que el llamador recarga.
 */
import { ref } from 'vue'
import { useT } from '@/i18n'
import { useConfirm, type ConfirmRequest } from '@/components/useConfirm'
import { problemDetail } from './problem'

export function useActions() {
  const t = useT()
  const confirmation = useConfirm()

  const busy = ref(false)
  const message = ref<string | null>(null)
  const actionError = ref<string | null>(null)

  function clear(): void {
    message.value = null
    actionError.value = null
  }

  function fail(err: unknown): void {
    actionError.value = problemDetail(err) ?? t('famUnit.errors.unexpected')
  }

  /** Ejecuta la acción sin confirmar. Devuelve si tuvo éxito. */
  async function run(action: () => Promise<unknown>, successMessage: string): Promise<boolean> {
    clear()
    busy.value = true

    try {
      await action()
      message.value = successMessage

      return true
    } catch (err) {
      fail(err)

      return false
    } finally {
      busy.value = false
    }
  }

  /** Pide confirmación y, solo si se confirma, ejecuta la acción. */
  async function confirmAndRun(
    request: ConfirmRequest,
    action: () => Promise<unknown>,
    successMessage: string,
  ): Promise<boolean> {
    clear()

    if (!(await confirmation.ask(request))) {
      return false
    }

    return run(action, successMessage)
  }

  return { confirmation, busy, message, actionError, clear, fail, run, confirmAndRun }
}
