/**
 * Acciones de una ficha (`funcional.md §4.5`, `§10.1`, `CA-ACAD-050`): borrado
 * con `ConfirmDialog` (`RN-CORE-64`) y mensajes de resultado. Un `409`
 * (`has_dependents`, `academic-year-closed`) o un `422` se muestran con el
 * mensaje del servidor, ya traducido, junto a la acción; **la pantalla no cambia
 * hasta recargar** (no se recarga sola ni se modifica el estado local).
 */
import { ref } from 'vue'
import { useT } from '@/i18n'
import { useConfirm, type ConfirmRequest } from '@/components/useConfirm'
import { problemDependents, problemDetail } from './problem'

export function useStructureActions() {
  const t = useT()
  const confirmation = useConfirm()

  const busy = ref(false)
  const message = ref<string | null>(null)
  const actionError = ref<string | null>(null)
  /** `RN-ACAD-20`: nombres de los dependientes que impiden borrar. */
  const dependents = ref<string[]>([])

  function clear(): void {
    message.value = null
    actionError.value = null
    dependents.value = []
  }

  function fail(err: unknown): void {
    dependents.value = problemDependents(err).map((name) => {
      const key = `acad.dependents.${name}`
      const label = t(key)

      return label === key ? name : label
    })
    actionError.value = problemDetail(err) ?? t('acad.errors.unexpected')
  }

  /** Pide confirmación y, solo si se confirma, ejecuta la acción. Devuelve si se ejecutó con éxito. */
  async function confirmAndRun(
    request: ConfirmRequest,
    action: () => Promise<unknown>,
    successMessage: string,
  ): Promise<boolean> {
    clear()

    if (!(await confirmation.ask(request))) {
      return false
    }

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

  return { confirmation, busy, message, actionError, dependents, clear, fail, confirmAndRun }
}
