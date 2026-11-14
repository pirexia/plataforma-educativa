<script setup lang="ts">
/**
 * `docs/modulos/REQ-CORE/funcional.md §13.12`, `OPEN-CORE-27`, `CA-CORE-201`,
 * issue #90. Valor vacío común: la marca visual va oculta al lector de
 * pantalla y se sustituye por un texto solo para lector de pantalla. Ni el
 * componente ni ninguna vista escriben la marca como literal (`INV-009`).
 */
import { useT } from '@/i18n'

const t = useT()
</script>

<template>
  <span data-slot="data-table-empty-value">
    <span aria-hidden="true">{{ t('dataTable.emptyMark') }}</span>
    <span class="sr-only">{{ t('dataTable.emptyValue') }}</span>
  </span>
</template>
