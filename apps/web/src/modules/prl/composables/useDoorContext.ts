/**
 * `REQ-FAM-UNIT/funcional.md §23.5.2`, `OPEN-FAM-UNIT-58` A (enmienda M7), UI-3. El nombre del alumno elegido en la
 * búsqueda y el código de su unidad viajan a la lista de puerta **solo por este estado reactivo en memoria del módulo**,
 * indexado por `public_id`: nunca por la URL, `history.state`, `document.title` ni el almacenamiento del navegador. Se
 * pierde al recargar. Acotado a unos pocos alumnos para que no crezca sin límite en un dispositivo compartido.
 */
import { reactive, watch } from 'vue'

import { useSession } from '@/session/useSession'

export interface KnownStudent {
  displayName: string
  familyUnitCode: string | null
}

const MAX_KNOWN = 20

const known = reactive<Map<string, KnownStudent>>(new Map())

/** Al cerrar la sesión (usuario a `null`) la memoria de nombres se vacía: no sobrevive a la persona que la llenó. */
watch(useSession().user, (current) => {
  if (current === null) known.clear()
})

export function useDoorContext() {
  function remember(studentPerson: string, student: KnownStudent): void {
    known.delete(studentPerson)
    known.set(studentPerson, student)

    while (known.size > MAX_KNOWN) {
      const oldest = known.keys().next().value

      if (oldest === undefined) break

      known.delete(oldest)
    }
  }

  function find(studentPerson: string): KnownStudent | null {
    return known.get(studentPerson) ?? null
  }

  function clear(): void {
    known.clear()
  }

  return { remember, find, clear }
}
