<script setup lang="ts">
/**
 * Contenido por defecto de una celda o de un campo de tarjeta: el valor
 * como texto, o el valor vacío común (`OPEN-CORE-27`, `CA-CORE-201`) si no
 * hay valor.
 */
import { computed } from 'vue'
import type { DataTableCellValue } from '../types'
import DataTableEmptyValue from './DataTableEmptyValue.vue'

const props = defineProps<{ value: DataTableCellValue }>()

const isEmpty = computed(
  () => props.value === null || props.value === undefined || props.value === '',
)
</script>

<template>
  <DataTableEmptyValue v-if="isEmpty" />
  <template v-else>{{ props.value }}</template>
</template>
