<script setup lang="ts">
/**
 * `docs/modulos/REQ-PERM/funcional.md §20.8.1`, `RN-PERM-32`-`-36`,
 * `OPEN-PERM-08` = A, `RN-CORE-53` modificada (§20.12): **rejilla de edición**
 * de las concesiones de un rol — la única entrada de la lista cerrada de
 * rejillas de edición del test de arquitectura (`CA-PERM-129`). Construida a
 * medida sobre `@/components/ui/table`; no usa `src/data-table` ni TanStack
 * (`RN-CORE-37` no tiene excepciones) ni una tabla HTML cruda.
 *
 * Es **presentación pura**: recibe el modelo ya resuelto por la vista (etiquetas
 * traducidas, estado de cada celda, avisos) y emite `edit` con el código del
 * permiso. Nada se envía al servidor desde aquí (`RN-PERM-36`).
 *
 * Accesibilidad propia, que la rejilla no hereda de ningún componente:
 * - una matriz por módulo con `caption`, `th scope="col"` por acción y
 *   `th scope="row"` por recurso; **sin `role="grid"` ni navegación con flechas**
 *   (se recorre con `Tab`, celda por celda);
 * - una celda con permiso es **un botón** cuyo nombre accesible incluye recurso,
 *   acción y estado, y cuyo texto visible lleva el estado y las marcas
 *   (Modificado, Inerte, Solo retirar o denegar, Error) — nunca solo color;
 * - una celda sin permiso lleva el valor vacío común (`DataTableEmptyValue`);
 * - el contenedor de cada matriz es enfocable, con nombre accesible, y se
 *   desplaza con el teclado; la columna de recurso queda fija (`sticky`);
 * - botones ≥ 44 × 44 px con puntero grueso (`RUX-RESP-007`).
 * En un rol del sistema (`readonly`) las celdas son texto, no botones.
 */
import { useT } from '@/i18n'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCaption,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { DataTableEmptyValue } from '@/data-table'

export interface MatrixCellView {
  /** Código técnico del permiso: identidad de la celda y clave del estado de edición. */
  code: string
  /** Nombre accesible completo: «{recurso} · {acción}: {estado}…. Modificar». */
  name: string
  stateText: string
  /** Marcas breves en texto (`RN-PERM-33`/`-35`/`-36`). */
  marks: string[]
  /** Mensaje de error del servidor para esta celda (`RN-PERM-36`). */
  error: string | null
}

export interface MatrixRowView {
  resource: string
  label: string
  special: boolean
  cells: Record<string, MatrixCellView | undefined>
}

export interface MatrixModuleView {
  code: string
  title: string
  caption: string
  scrollLabel: string
  /** «Módulo no contratado: …» (`RN-PERM-35`). */
  notice: string | null
  actions: { id: string; label: string }[]
  rows: MatrixRowView[]
}

defineProps<{
  modules: readonly MatrixModuleView[]
  /** Rol del sistema: texto, sin botones ni panel. */
  readonly?: boolean
}>()

const emit = defineEmits<{ edit: [code: string] }>()

const t = useT()

const stickyHead = 'bg-background sticky left-0 z-10 border-r'
</script>

<template>
  <div class="flex flex-col gap-6" data-slot="role-permission-matrix">
    <section
      v-for="module in modules"
      :key="module.code"
      class="flex flex-col gap-2"
      :aria-labelledby="`matrix-title-${module.code}`"
      data-slot="role-permission-matrix-module"
    >
      <h3 :id="`matrix-title-${module.code}`" class="text-base font-semibold">
        {{ module.title }}
      </h3>

      <p
        v-if="module.notice"
        class="border-border bg-muted rounded-lg border px-3 py-2 text-sm"
        data-slot="role-permission-matrix-notice"
      >
        {{ module.notice }}
      </p>

      <div
        class="[&>[data-slot=table-container]]:rounded-lg [&>[data-slot=table-container]]:border [&>[data-slot=table-container]]:outline-none [&>[data-slot=table-container]:focus-visible]:border-ring [&>[data-slot=table-container]:focus-visible]:ring-ring/50 [&>[data-slot=table-container]:focus-visible]:ring-3"
      >
        <!--
          `role`, `tabindex` y `aria-label` caen en el contenedor de `Table` (su raíz):
          es el elemento que se desplaza, así que es el que recibe el foco y el nombre.
        -->
        <Table role="region" tabindex="0" :aria-label="module.scrollLabel" class="min-w-max">
          <TableCaption class="sr-only">{{ module.caption }}</TableCaption>
          <TableHeader>
            <TableRow>
              <TableHead scope="col" :class="stickyHead">
                {{ t('core.roles.editor.matrix.resource') }}
              </TableHead>
              <TableHead v-for="action in module.actions" :key="action.id" scope="col">
                {{ action.label }}
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="row in module.rows" :key="row.resource">
              <TableHead scope="row" :class="[stickyHead, 'h-auto py-2 whitespace-normal']">
                <span class="block">{{ row.label }}</span>
                <Badge v-if="row.special" variant="outline" class="mt-1">
                  {{ t('core.roles.editor.matrix.special') }}
                </Badge>
              </TableHead>

              <TableCell v-for="action in module.actions" :key="action.id" class="align-top">
                <template v-if="row.cells[action.id]">
                  <Button
                    v-if="!readonly"
                    type="button"
                    variant="outline"
                    class="h-auto min-h-8 flex-col items-start gap-0.5 py-1 text-left whitespace-normal [@media(any-pointer:coarse)]:min-w-11"
                    :aria-label="row.cells[action.id]!.name"
                    :aria-describedby="
                      row.cells[action.id]!.error
                        ? `matrix-cell-error-${row.cells[action.id]!.code}`
                        : undefined
                    "
                    :data-code="row.cells[action.id]!.code"
                    @click="emit('edit', row.cells[action.id]!.code)"
                  >
                    <span>{{ row.cells[action.id]!.stateText }}</span>
                    <span
                      v-for="mark in row.cells[action.id]!.marks"
                      :key="mark"
                      class="text-muted-foreground text-xs font-normal"
                    >
                      {{ mark }}
                    </span>
                  </Button>
                  <div v-else class="flex flex-col gap-0.5" :data-code="row.cells[action.id]!.code">
                    <span>{{ row.cells[action.id]!.stateText }}</span>
                    <span
                      v-for="mark in row.cells[action.id]!.marks"
                      :key="mark"
                      class="text-muted-foreground text-xs"
                    >
                      {{ mark }}
                    </span>
                  </div>
                  <p
                    v-if="row.cells[action.id]!.error"
                    :id="`matrix-cell-error-${row.cells[action.id]!.code}`"
                    class="text-destructive mt-1 max-w-48 text-xs whitespace-normal"
                  >
                    {{ row.cells[action.id]!.error }}
                  </p>
                </template>
                <DataTableEmptyValue v-else />
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>
    </section>
  </div>
</template>
