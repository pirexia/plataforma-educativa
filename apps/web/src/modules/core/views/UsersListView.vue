<script setup lang="ts">
/**
 * `/administracion/usuarios` (`docs/modulos/REQ-CORE/funcional.md §14.4.1`,
 * `RN-CORE-67`/`-68`/`-69`, 1.9b). Listado de usuarios con el componente de
 * tablas de 1.9: modo `page`, estado en la URL (`RN-CORE-54`), búsqueda `q`
 * (nunca a la URL ni a la exportación), tarjetas por debajo de 768 px.
 *
 * Permiso, nunca rol (`RN-CORE-61`): la ruta exige `usuario.leer`; cada
 * acción y cada filtro se ofrece solo con el permiso de su *endpoint*, y
 * ninguna petición auxiliar sale sin él (`RN-CORE-62`: sin `rol.leer` no se
 * pide `GET /roles` ni existe el filtro de rol).
 *
 * `RN-CORE-67`: el listado es para localizar a una persona, no para leer sus
 * datos identificativos en bloque — `document_number`, `birth_date`,
 * `contact_email` y `contact_phone` no se pintan aunque el *endpoint* los
 * devuelva.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { i18n, localeFromDomain, useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFetcher,
  type DataTableFilter,
} from '@/data-table'
import { useTenantBranding } from '@/tenant/useTenantBranding'
import { exportUsers, getDataExport, listRoles, listUsers } from '../api'
import { usePermissions } from '../composables/usePermissions'
import type { Locale, Role, User, UserStatus } from '../types'

const t = useT()
const router = useRouter()
const { can } = usePermissions()
const { branding } = useTenantBranding()
const { formatDate } = useDataTableFormatters()

const table = ref<{ refresh: () => Promise<void> } | null>(null)
const roles = ref<Role[]>([])

const STATUSES: UserStatus[] = ['pendiente', 'activo', 'inactivo']
const ALL_LOCALES: Locale[] = ['es-ES', 'en', 'de', 'fr']

/** `ADR-038 §7.3`: rama por defecto — un valor no anticipado muestra su código, no rompe. */
function translated(key: string, fallback: string): string {
  const label = t(key)

  return label === key ? fallback : label
}

function localeName(locale: string): string {
  return translated(`core.locale.name.${localeFromDomain(locale as Locale)}`, locale)
}

function statusLabel(status: string): string {
  return translated(`core.user.status.${status}`, status)
}

function fullName(user: User): string {
  const surnames = [user.person.family_name_1, user.person.family_name_2].filter(Boolean).join(' ')

  return `${surnames}, ${user.person.given_name}`
}

const canReadRoles = computed(() => can('rol.leer'))
const canExport = computed(() => can('usuario.exportar'))

const activeLocales = computed<Locale[]>(() => branding.value?.active_locales ?? ALL_LOCALES)

const filters = computed<DataTableFilter[]>(() => {
  const list: DataTableFilter[] = [
    {
      type: 'enum',
      id: 'status',
      labelKey: 'core.users.filters.status',
      options: STATUSES.map((value) => ({ value, labelKey: `core.user.status.${value}` })),
    },
  ]

  // RN-CORE-62: sin `rol.leer` no hay filtro de rol ni se pide `GET /roles`.
  if (canReadRoles.value) {
    list.push({
      type: 'enum',
      id: 'role',
      labelKey: 'core.users.filters.role',
      options: roles.value.map((role) => ({ value: role.public_id, label: role.name })),
    })
  }

  list.push({
    type: 'enum',
    id: 'locale',
    labelKey: 'core.users.filters.locale',
    options: activeLocales.value.map((value) => ({
      value,
      labelKey: `core.locale.name.${localeFromDomain(value)}`,
    })),
  })

  // RN-CORE-68: «dados de baja» solo con `usuario.eliminar`; casilla de dos estados.
  if (can('usuario.eliminar')) {
    list.push({
      type: 'boolean',
      id: 'include_deleted',
      labelKey: 'core.users.filters.includeDeleted',
      twoState: true,
    })
  }

  return list
})

const columns: DataTableColumn<User>[] = [
  {
    id: 'family_name_1',
    headerKey: 'core.users.columns.name',
    value: fullName,
    sortable: true,
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'email',
    headerKey: 'core.users.columns.email',
    sortable: true,
    card: 'subtitle',
  },
  {
    id: 'status',
    headerKey: 'core.users.columns.status',
    value: (user) => (user.deleted_at ? t('core.users.status.deleted') : statusLabel(user.status)),
    card: 'field',
  },
  {
    id: 'role',
    headerKey: 'core.users.columns.roles',
    value: (user) => user.roles.map((role) => role.name).join(', ') || null,
    card: 'field',
  },
  {
    id: 'locale',
    headerKey: 'core.users.columns.locale',
    value: (user) => localeName(user.person.locale),
    defaultHidden: true,
    card: 'field',
  },
  {
    id: 'created_at',
    headerKey: 'core.users.columns.createdAt',
    value: (user) => formatDate(user.created_at),
    sortable: true,
    defaultHidden: true,
    card: 'field',
  },
]

function split(value: string | undefined): string[] | undefined {
  return value ? value.split(',').filter((item) => item !== '') : undefined
}

const fetchUsers: DataTableFetcher<User> = (query) =>
  listUsers({
    q: query.q,
    status: split(query.filters.status) as UserStatus[] | undefined,
    role: split(query.filters.role),
    locale: split(query.filters.locale),
    include_deleted: query.filters.include_deleted === 'true' ? true : undefined,
    sort: query.sort,
    page: query.page,
    per_page: query.per_page,
  })

async function loadRoles(): Promise<void> {
  if (!canReadRoles.value) {
    return
  }

  try {
    roles.value = (await listRoles({ per_page: 100 })).data
  } catch {
    // El filtro de rol queda sin opciones: la tabla sigue siendo usable.
    roles.value = []
  }
}

onMounted(loadRoles)

// RN-CORE-63: los nombres de rol llegan traducidos por el servidor — al cambiar
// de idioma se vuelve a pedir la página actual y las opciones del filtro.
watch(
  () => i18n.global.locale.value,
  () => {
    void table.value?.refresh()
    void loadRoles()
  },
)

const exportConfig = computed(() => ({
  canExport: canExport.value,
  request: exportUsers,
  status: getDataExport,
}))

// «Importar» enlaza con `core-user-imports`, que existe desde 1.9c: mientras la
// ruta no esté registrada no se ofrece un enlace roto.
const importRoute = computed(() => router.hasRoute('core-user-imports'))

function goToNew(): void {
  void router.push({ name: 'core-user-new' })
}
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-lg font-semibold">{{ t('core.users.title') }}</h1>
        <p class="text-muted-foreground text-sm">{{ t('core.users.intro') }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <Button v-if="can('usuario.crear')" as-child>
          <RouterLink :to="{ name: 'core-user-new' }">{{ t('core.users.new') }}</RouterLink>
        </Button>
        <Button v-if="can('usuario.importar') && importRoute" variant="outline" as-child>
          <RouterLink :to="{ name: 'core-user-imports' }">{{ t('core.users.import') }}</RouterLink>
        </Button>
      </div>
    </div>

    <DataTable
      ref="table"
      table-id="core.users"
      :caption="t('core.users.caption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchUsers"
      :filters="filters"
      searchable
      url-state
      :export-config="exportConfig"
      :empty-title="t('core.users.empty.title')"
      :empty-text="can('usuario.crear') ? t('core.users.empty.text') : ''"
      :empty-action-label="can('usuario.crear') ? t('core.users.new') : undefined"
      :card-heading-level="2"
      @empty-action="goToNew"
    >
      <template #cell-family_name_1="{ row }">
        <RouterLink
          :to="{ name: 'core-user-detail', params: { publicId: row.public_id } }"
          class="text-primary-on-background focus-visible:ring-ring/50 rounded-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-3"
        >
          {{ fullName(row) }}
        </RouterLink>
      </template>
    </DataTable>
  </div>
</template>
