/**
 * `docs/modulos/REQ-ACAD/funcional.md §18.8`, `ADR-054`. «A 320 px, lista por día,
 * sin desplazamiento horizontal»: la rejilla semanal y la lista por día son dos
 * representaciones de los mismos datos y en el DOM hay **una sola** a la vez, así
 * que la decisión no puede ser solo CSS (se escucha `matchMedia`). Sin `matchMedia`
 * (entorno sin *layout*), se asume pantalla ancha. Mismo umbral que las tablas
 * (`--breakpoint-md`, 768 px).
 */
import { onBeforeUnmount, ref, type Ref } from 'vue'

const NARROW_MAX_WIDTH_PX = 767.98

export function useNarrowGrid(): Ref<boolean> {
  const narrow = ref(false)

  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
    return narrow
  }

  const query = window.matchMedia(`(max-width: ${NARROW_MAX_WIDTH_PX}px)`)
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
