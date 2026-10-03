<script setup lang="ts">
/**
 * `/administracion/roles` (`docs/modulos/REQ-CORE/funcional.md §14.8`,
 * `RN-CORE-75`/`-63`, `OPEN-CORE-36` = A, 1.9d). Listado de roles **de solo
 * lectura** con el componente de tablas: modo `page`, sin filtros, sin
 * búsqueda y sin orden (el *endpoint* no los acepta; orden por `code`).
 *
 * - **Solo lectura** (`RN-CORE-75`): ninguna acción de escritura sobre roles,
 *   aunque la API de 1.5 las admita y el usuario tenga `rol.crear`,
 *   `rol.actualizar` o `rol.eliminar`; el editor es `1.5b`. La edición de
 *   `mfa_required` ya existe en `/administracion/mfa` y no se duplica.
 * - **Sin detalle** (`OPEN-CORE-36` = A): no hay ruta de detalle de un rol ni
 *   se llama a `GET /roles/{id}`; las concesiones llegan con la matriz de `1.5b`.
 * - **Texto traducido por el servidor** (`RN-CORE-63`): el `name` de los roles
 *   del sistema llega en el idioma de la petición; al cambiar de idioma se
 *   vuelve a pedir la página actual.
 */
import { ref, watch } from 'vue'
import { i18n, useT } from '@/i18n'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFetcher,
} from '@/data-table'
import { listRoles } from '../api'
import type { Role } from '../types'

const t = useT()
const { formatNumber } = useDataTableFormatters()

const table = ref<{ refresh: () => Promise<void> } | null>(null)

const yesNo = (value: boolean): string => (value ? t('core.roles.yes') : t('core.roles.no'))

const columns: DataTableColumn<Role>[] = [
  {
    id: 'name',
    headerKey: 'core.roles.columns.name',
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'is_system',
    headerKey: 'core.roles.columns.type',
    value: (role) => (role.is_system ? t('core.roles.type.system') : t('core.roles.type.custom')),
    card: 'subtitle',
  },
  {
    id: 'mfa_required',
    headerKey: 'core.roles.columns.mfaRequired',
    value: (role) => yesNo(role.mfa_required),
    card: 'field',
  },
  {
    id: 'special_data_access',
    headerKey: 'core.roles.columns.specialData',
    value: (role) => yesNo(role.special_data_access),
    card: 'field',
  },
  {
    id: 'users_count',
    headerKey: 'core.roles.columns.users',
    value: (role) => (role.users_count === undefined ? null : formatNumber(role.users_count)),
    align: 'end',
    card: 'field',
  },
]

const fetchRoles: DataTableFetcher<Role> = (query) =>
  listRoles({ page: query.page, per_page: query.per_page })

// RN-CORE-63: los nombres de los roles del sistema llegan traducidos por el
// servidor — al cambiar de idioma se vuelve a pedir la página actual.
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
      <h1 class="text-lg font-semibold">{{ t('core.roles.title') }}</h1>
      <p class="text-muted-foreground text-sm">{{ t('core.roles.intro') }}</p>
    </div>

    <DataTable
      ref="table"
      table-id="core.roles"
      :caption="t('core.roles.caption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchRoles"
      url-state
      :empty-title="t('core.roles.empty.title')"
      :empty-text="t('core.roles.empty.text')"
      :card-heading-level="2"
    />
  </div>
</template>
