/**
 * `docs/modulos/REQ-CURSO/funcional.md §10.6` paso 2 (1.12b), `RN-CURSO-64`, `RN-CURSO-82`. Lógica pura de la selección de
 * elementos del asistente de apertura del curso siguiente: al marcar un elemento se marcan los que necesita; al desmarcar
 * uno se desmarcan los que lo requieren. El servidor **no** completa la selección (`422 rollover_element_requires`): lo
 * hace la interfaz, con un aviso textual de qué ha cambiado.
 */
import type { RolloverElement } from './types'

/** Único paquete que existe (`RN-CURSO-82`): sus elementos se piden con una casilla propia, no uno a uno. */
export const TEACHING_ASSIGNMENTS_BUNDLE = 'teaching_assignments'

/** Elementos que se eligen uno a uno (sin paquete). */
export function selectableElements(elements: readonly RolloverElement[]): RolloverElement[] {
  return elements.filter((element) => element.bundle === null)
}

/** Elementos del paquete de asignación docente (de ellos sale la casilla única). */
export function bundleElements(elements: readonly RolloverElement[]): RolloverElement[] {
  return elements.filter((element) => element.bundle === TEACHING_ASSIGNMENTS_BUNDLE)
}

/** Requisitos transitivos de `code` (sin incluirlo), en el orden en que se descubren. */
export function transitiveRequires(code: string, elements: readonly RolloverElement[]): string[] {
  const byCode = new Map(elements.map((element) => [element.code, element]))
  const found: string[] = []
  const pending = [...(byCode.get(code)?.requires ?? [])]

  while (pending.length > 0) {
    const next = pending.shift()!

    if (next === code || found.includes(next)) {
      continue
    }

    found.push(next)
    pending.push(...(byCode.get(next)?.requires ?? []))
  }

  return found
}

/**
 * ¿Se puede marcar? Hace falta poder crear el elemento y cada uno de sus requisitos (`allowed`) y que existan en el
 * catálogo. Si no, el motivo para mostrarlo junto al control.
 */
export function blockedReason(
  code: string,
  elements: readonly RolloverElement[],
): 'forbidden' | 'requiresForbidden' | null {
  const byCode = new Map(elements.map((element) => [element.code, element]))
  const element = byCode.get(code)

  if (!element || !element.allowed) {
    return 'forbidden'
  }

  for (const required of transitiveRequires(code, elements)) {
    const requiredElement = byCode.get(required)

    if (!requiredElement || !requiredElement.allowed) {
      return 'requiresForbidden'
    }
  }

  return null
}

/** Marca `code` y sus requisitos. Devuelve la nueva selección y los códigos añadidos además de `code`. */
export function selectWithRequires(
  code: string,
  selected: readonly string[],
  elements: readonly RolloverElement[],
): { selected: string[]; added: string[] } {
  const next = new Set(selected)
  const added: string[] = []

  next.add(code)

  for (const required of transitiveRequires(code, elements)) {
    if (!next.has(required)) {
      next.add(required)
      added.push(required)
    }
  }

  return { selected: [...next], added }
}

/**
 * Desmarca `code` y los elementos seleccionados que lo requieren (transitivamente). `bundleDropped` es `true` si alguno de
 * los desmarcados hace imposible la casilla del paquete (algún elemento del paquete requiere el desmarcado).
 */
export function deselectWithDependents(
  code: string,
  selected: readonly string[],
  elements: readonly RolloverElement[],
  bundleSelected: boolean,
): { selected: string[]; removed: string[]; bundleDropped: boolean } {
  const removedSet = new Set<string>([code])
  let changed = true

  while (changed) {
    changed = false

    for (const element of elements) {
      if (
        selected.includes(element.code) &&
        !removedSet.has(element.code) &&
        element.requires.some((required) => removedSet.has(required))
      ) {
        removedSet.add(element.code)
        changed = true
      }
    }
  }

  const bundleCodes = new Set(bundleElements(elements).map((element) => element.code))
  const bundleDropped =
    bundleSelected &&
    bundleElements(elements).some((element) => {
      const needs = [element.code, ...transitiveRequires(element.code, elements)]

      return needs.some((need) => !bundleCodes.has(need) && removedSet.has(need))
    })

  return {
    selected: selected.filter((item) => !removedSet.has(item)),
    removed: [...removedSet].filter((item) => item !== code && selected.includes(item)),
    bundleDropped,
  }
}

/**
 * Marca los requisitos de los elementos del paquete que no son del paquete (al marcar la casilla). Devuelve la nueva
 * selección y los añadidos.
 */
export function selectBundleRequires(
  selected: readonly string[],
  elements: readonly RolloverElement[],
): { selected: string[]; added: string[] } {
  const bundle = new Set(bundleElements(elements).map((element) => element.code))
  const next = new Set(selected)
  const added: string[] = []

  for (const element of bundleElements(elements)) {
    for (const required of transitiveRequires(element.code, elements)) {
      if (!bundle.has(required) && !next.has(required)) {
        next.add(required)
        added.push(required)
      }
    }
  }

  return { selected: [...next], added }
}
