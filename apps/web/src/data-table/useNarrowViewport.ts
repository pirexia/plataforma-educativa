/**
 * `docs/modulos/REQ-CORE/funcional.md §13.9`, `RN-CORE-55`. «Por debajo de
 * 768 px» (`--breakpoint-md`, `RN-CORE-29`). En el DOM hay **una sola** de
 * las dos representaciones (tabla o tarjetas) a la vez, así que la decisión
 * no puede ser solo CSS: se escucha `matchMedia` y se pinta una u otra.
 * Sin `matchMedia` (entorno sin *layout*), se asume pantalla ancha.
 */
import { onBeforeUnmount, ref, type Ref } from 'vue'
import { CARDS_BREAKPOINT_PX } from './constants'

export function useNarrowViewport(): Ref<boolean> {
  const narrow = ref(false)

  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
    return narrow
  }

  const query = window.matchMedia(`(max-width: ${CARDS_BREAKPOINT_PX - 0.02}px)`)
  const update = (): void => {
    narrow.value = query.matches
  }

  update()
  query.addEventListener?.('change', update)

  onBeforeUnmount(() => {
    query.removeEventListener?.('change', update)
  })

  return narrow
}
