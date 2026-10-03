<script setup lang="ts">
/**
 * `/administracion/modulos` (`docs/modulos/REQ-CORE/funcional.md §14.10b`,
 * `RN-CORE-87`, `OPEN-CORE-31` = B, `OPEN-CORE-45` = A, 1.9e). Módulos
 * contratados **de solo lectura** con el componente de tablas.
 *
 * - `GET /modules` no está paginado (`{"data": [...]}` sin `meta`) y devuelve
 *   todo el catálogo, también los no contratados (`enabled: false`): la función
 *   de petición lo envuelve en una única página (misma técnica que `RN-CORE-74`)
 *   y **filtra en el cliente** las filas contratadas (`OPEN-CORE-45` = A; no es
 *   filtrado de seguridad). Sin búsqueda, filtros, orden ni exportación.
 * - **La fecha de alta** (`enabled_at`) es el «aviso de las nuevas altas» de
 *   `REQ-CORE-002` en este paso (`ADR-045`): informativo, sin acción requerida.
 * - **Ninguna acción de escritura**: nunca se llama a
 *   `PATCH /module-subscriptions/{id}`, aunque el usuario tenga
 *   `modulo.actualizar`, ni se muestran `settings` ni `phase`.
 * - `name` llega traducido por el servidor (`RN-CORE-63`): al cambiar de idioma
 *   se vuelve a pedir la tabla.
 */
import { ref, watch } from 'vue'
import { i18n, useT } from '@/i18n'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFetcher,
} from '@/data-table'
import { listModules } from '../api'
import type { ModuleSubscription } from '../types'

const t = useT()
const { formatDate } = useDataTableFormatters()

const table = ref<{ refresh: () => Promise<void> } | null>(null)

const columns: DataTableColumn<ModuleSubscription>[] = [
  {
    id: 'name',
    headerKey: 'core.modules.columns.name',
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'enabled',
    headerKey: 'core.modules.columns.status',
    value: (row) =>
      row.enabled ? t('core.modules.status.contracted') : t('core.modules.status.notContracted'),
    card: 'subtitle',
  },
  {
    id: 'enabled_at',
    headerKey: 'core.modules.columns.enabledAt',
    value: (row) => formatDate(row.enabled_at),
    card: 'field',
  },
]

const fetchModules: DataTableFetcher<ModuleSubscription> = async () => {
  const { data } = await listModules()
  const rows = data.filter((module) => module.enabled)

  return {
    data: rows,
    meta: {
      current_page: 1,
      per_page: Math.max(rows.length, 1),
      total: rows.length,
      last_page: 1,
    },
  }
}

// RN-CORE-63: `name` llega traducido por el servidor — al cambiar de idioma se vuelve a pedir.
watch(
  () => i18n.global.locale.value,
  () => {
    void table.value?.refresh()
  },
)
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-6">
    <div>
      <h1 class="text-lg font-semibold">{{ t('core.modules.title') }}</h1>
      <p class="text-muted-foreground text-sm">{{ t('core.modules.intro') }}</p>
    </div>

    <DataTable
      ref="table"
      table-id="core.modules"
      :caption="t('core.modules.caption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchModules"
      :row-key="(row: ModuleSubscription) => row.module_code"
      :empty-title="t('core.modules.empty.title')"
      :empty-text="t('core.modules.empty.text')"
      :card-heading-level="2"
    />
  </div>
</template>
