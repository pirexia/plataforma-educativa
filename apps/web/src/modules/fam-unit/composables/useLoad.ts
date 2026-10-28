/**
 * Carga de un recurso para una ficha (`funcional.md §10`): estado de carga, error de pantalla (`404`/`403`/
 * `module-disabled`, `ADR-038 §6.4`) y recarga. `ErrorState` pinta el error.
 */
import { ref, type Ref } from 'vue'
import { resolveErrorState, type ShellErrorState } from '@/layouts/errorState'

export function useLoad<T>(loader: () => Promise<T>): {
  data: Ref<T | null>
  loading: Ref<boolean>
  error: Ref<ShellErrorState | null>
  load: () => Promise<void>
} {
  const data = ref(null) as Ref<T | null>
  const loading = ref(true)
  const error = ref<ShellErrorState | null>(null)

  async function load(): Promise<void> {
    loading.value = true
    error.value = null

    try {
      data.value = await loader()
    } catch (err) {
      data.value = null
      error.value = resolveErrorState(err) ?? { kind: 'unexpected' }
    } finally {
      loading.value = false
    }
  }

  return { data, loading, error, load }
}
