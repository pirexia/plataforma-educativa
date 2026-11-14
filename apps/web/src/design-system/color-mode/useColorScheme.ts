/**
 * `docs/design-system.md` §9. Modo oscuro: tres estados, persistencia
 * local, ortogonal a la marca (`ADR-052 §2`). Envoltorio de `useColorMode`
 * de `@vueuse/core` (`RNF-MANT-007`): este es el **único** fichero de
 * `src/` que importa `useColorMode`, `usePreferredDark` o
 * `usePreferredColorScheme` (`RN-DS-13`, `CA-DS-040`). Ningún consumidor
 * ve el valor `'auto'` de VueUse: la traducción a `'system'` queda aquí
 * dentro (`RN-DS-13`).
 */

import { computed, type ComputedRef, type Ref } from 'vue'
import { useColorMode, useStorage } from '@vueuse/core'

export type ColorModePreference = 'system' | 'light' | 'dark'
export const COLOR_MODE_STORAGE_KEY = 'plataforma.color-mode'

type VueUseColorMode = 'auto' | 'light' | 'dark'

const VALID_VUEUSE_MODES = new Set<VueUseColorMode>(['auto', 'light', 'dark'])

function isValidVueUseColorMode(value: string): value is VueUseColorMode {
  return VALID_VUEUSE_MODES.has(value as VueUseColorMode)
}

function toVueUseValue(preference: ColorModePreference): VueUseColorMode {
  return preference === 'system' ? 'auto' : preference
}

function fromVueUseValue(value: VueUseColorMode): ColorModePreference {
  return value === 'auto' ? 'system' : value
}

// Parámetro de tipo explícito fijado a `'light' | 'dark'` (el valor por
// defecto de VueUse, `BasicColorMode`): así `state`/`system` quedan
// tipados exactamente como `'light' | 'dark'`. Sin el explícito, TS
// infiere `T` a partir de `storageRef` (abajo) como `'auto' | 'light' |
// 'dark'`, y esa unión se filtraría también a `state`, que en tiempo de
// ejecución nunca vale `'auto'` (`state = store.value === 'auto' ?
// system.value : store.value`).
type ColorModeInstance = ReturnType<typeof useColorMode<'light' | 'dark'>>

let instance: ColorModeInstance | null = null

/**
 * Crea el singleton de `useColorMode` en la primera llamada (a
 * `initColorScheme()` o a `useColorScheme()`, lo que ocurra antes) y
 * devuelve siempre el mismo a partir de ahí — un único `useStorage` y una
 * única suscripción a `prefers-color-scheme` para toda la SPA.
 *
 * `RN-DS-14`: `disableTransition: false` — la opción por defecto de
 * VueUse inyecta un `<style>` al cambiar de modo para suprimir
 * transiciones, lo que una CSP estricta sin `'unsafe-inline'` en
 * `style-src` bloquea. No hace falta esa supresión aquí.
 */
function ensureInstance(): ColorModeInstance {
  if (instance === null) {
    // Ref de almacenamiento propio, con serializador que valida
    // (`RN-DS-12`: "un valor desconocido equivale a system/'auto'"). Se
    // pasa como `storageRef` en vez de `storageKey` porque `useColorMode`
    // usa el valor crudo de `store` sin validar — un valor corrupto
    // llegaría tal cual a la clase de `<html>` y rompería `RN-DS-11`.
    const storageRef = useStorage<VueUseColorMode>(COLOR_MODE_STORAGE_KEY, 'auto', undefined, {
      serializer: {
        read: (raw) => (isValidVueUseColorMode(raw) ? raw : 'auto'),
        write: (value) => value,
      },
    })

    instance = useColorMode<'light' | 'dark'>({
      selector: 'html',
      attribute: 'class',
      storageRef,
      initialValue: 'auto',
      disableTransition: false,
    })
  }

  return instance
}

/**
 * Arranque (`main.ts`, §8), síncrono e idempotente (`RN-DS-10`).
 * `useColorMode` aplica la clase resuelta en `<html>` de forma síncrona
 * en cuanto se crea (`watch(state, onChanged, { immediate: true })`), así
 * que tras esta llamada `<html>` ya tiene exactamente una de `light`/
 * `dark` (`RN-DS-11`) — también con preferencia `system`, con la
 * resuelta.
 */
export function initColorScheme(): void {
  ensureInstance()
}

export function useColorScheme(): {
  preference: Readonly<Ref<ColorModePreference>>
  resolved: Readonly<Ref<'light' | 'dark'>>
  setPreference: (value: ColorModePreference) => void
} {
  const colorMode = ensureInstance()

  const preference: ComputedRef<ColorModePreference> = computed(() =>
    fromVueUseValue(colorMode.store.value),
  )
  const resolved: ComputedRef<'light' | 'dark'> = computed(() => colorMode.state.value)

  function setPreference(value: ColorModePreference): void {
    colorMode.value = toVueUseValue(value)
  }

  return { preference, resolved, setPreference }
}
