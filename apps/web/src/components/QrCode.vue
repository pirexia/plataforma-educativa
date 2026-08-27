<script setup lang="ts">
/**
 * Componente genérico de código QR. Fuera de `components/ui/` (reservado
 * a shadcn-vue) y fuera de `src/modules/` (ningún módulo lo posee;
 * `INV-007`, `ADR-041`). Hoy solo lo usa `modules/auth` (alta de TOTP,
 * `funcional.md §C.4.1`/`§C.11`), pero no le pertenece.
 *
 * `ADR-041`: usa **solo** `encode()` de `uqr`, nunca `renderSVG()` — tiene
 * un historial de fallo de escapado, evitado del todo no usándola.
 * `encode()` devuelve una matriz booleana; el `<svg>` se construye aquí,
 * en la propia plantilla, sin `v-html`.
 *
 * El `alt`/texto accesible **no** contiene el valor codificado (que en el
 * caso de uso de MFA es la URI `otpauth://` con el secreto): un lector de
 * pantalla o una captura de este componente no debe poder extraerlo por
 * ahí. Quien necesite el valor en texto lo tiene aparte, seleccionable
 * (`funcional.md §C.11`).
 *
 * `ADR-041` §2.4/§2.3.1: los módulos se pintan con `fill="currentColor"`,
 * nunca un color fijo, para heredar el color de texto del tema del centro
 * (claro/oscuro). El fondo es transparente (sin `<rect>` de fondo): el
 * contraste lo aporta el contenedor que use este componente.
 */
import { computed } from 'vue'
import { encode } from 'uqr'

const props = withDefaults(
  defineProps<{
    /** El contenido a codificar (p.ej. una URI `otpauth://`). Nunca se expone como texto por este componente. */
    value: string
    /** Descripción accesible del código, sin el valor codificado. */
    label: string
    /** Tamaño en píxeles CSS de cada módulo del QR. */
    moduleSize?: number
  }>(),
  { moduleSize: 6 },
)

const qr = computed(() => encode(props.value, { ecc: 'M' }))

const dimension = computed(() => qr.value.size * props.moduleSize)

/** Una celda por módulo negro, como rectángulos de 1x1 en el sistema de coordenadas del módulo — el `viewBox` hace la escala. */
const darkModules = computed(() => {
  const cells: { x: number; y: number }[] = []
  const { data, size } = qr.value

  for (let y = 0; y < size; y += 1) {
    for (let x = 0; x < size; x += 1) {
      if (data[y]?.[x]) {
        cells.push({ x, y })
      }
    }
  }

  return cells
})
</script>

<template>
  <svg
    role="img"
    :aria-label="label"
    xmlns="http://www.w3.org/2000/svg"
    :width="dimension"
    :height="dimension"
    :viewBox="`0 0 ${qr.size} ${qr.size}`"
    shape-rendering="crispEdges"
  >
    <rect
      v-for="cell in darkModules"
      :key="`${cell.x}-${cell.y}`"
      :x="cell.x"
      :y="cell.y"
      width="1"
      height="1"
      fill="currentColor"
    />
  </svg>
</template>
