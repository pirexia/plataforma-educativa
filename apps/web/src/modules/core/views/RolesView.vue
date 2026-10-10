<script setup lang="ts">
/**
 * `/administracion/roles` (`docs/modulos/REQ-CORE/funcional.md §14.8`,
 * `RN-CORE-63`, 1.9d; **ampliado en 1.5b**, `REQ-PERM/funcional.md §20.4`,
 * `RN-PERM-26`). Listado de roles con el componente de tablas: modo `page`, sin
 * filtros, sin búsqueda y sin orden (el *endpoint* no los acepta; orden por
 * `code`).
 *
 * - **Ya no es de solo lectura** (1.5b sustituye a `RN-CORE-75` y a
 *   `CA-CORE-240`, que fallaría en cuanto existe «Nuevo rol»; el sustituto es
 *   `CA-PERM-101`): el nombre de cada rol es un **enlace a su ficha**
 *   (`core-role-detail`) y la acción de la barra **«Nuevo rol»** solo se ofrece
 *   con `rol.crear` (`RN-CORE-61`: permiso, nunca rol).
 * - **Texto traducido por el servidor** (`RN-CORE-63`): el `name` de los roles
 *   del sistema llega en el idioma de la petición; al cambiar de idioma se
 *   vuelve a pedir la página actual.
 * - Recoge el mensaje de resultado que deja la baja de un rol (`RN-CORE-66`).
 */
import { onMounted, ref, watch } from 'vue'
import { i18n, useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFetcher,
} from '@/data-table'
import { listRoles } from '../api'
import { takeFlash } from '../composables/useFlash'
import { usePermissions } from '../composables/usePermissions'
import type { Role } from '../types'

const t = useT()
const { can } = usePermissions()
const { formatNumber } = useDataTableFormatters()

const table = ref<{ refresh: () => Promise<void> } | null>(null)
const message = ref<string | null>(null)

onMounted(() => {
  const flash = takeFlash()

  if (flash) {
    message.value = t(flash.key, flash.params ?? {})
  }
})

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

    <p
      v-if="message"
      role="status"
      class="border-border bg-muted rounded-lg border px-3 py-2 text-sm"
    >
      {{ message }}
    </p>

    <div v-if="can('rol.crear')" class="flex flex-wrap gap-2">
      <Button as-child>
        <RouterLink :to="{ name: 'core-role-new' }">{{ t('core.roles.actions.new') }}</RouterLink>
      </Button>
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
    >
      <!-- `CA-PERM-101`: el nombre del rol es un enlace a su ficha. -->
      <template #cell-name="{ row }">
        <RouterLink
          :to="{ name: 'core-role-detail', params: { publicId: row.public_id } }"
          class="text-primary-on-background focus-visible:ring-ring/50 rounded-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-3"
        >
          {{ row.name }}
        </RouterLink>
      </template>
    </DataTable>
  </div>
</template>
