<script setup lang="ts">
/**
 * `/administracion/usuarios/:publicId/permisos`
 * (`docs/modulos/REQ-PERM/funcional.md §20.10`, `RN-PERM-41`/`-42`/`-45`,
 * `RPERM-009`, `RPERM-007`). Qué puede hacer ahora mismo una persona, calculado
 * por el servidor con las mismas reglas que autorizan cada petición (`RN-PERM-22`),
 * **con la procedencia de cada decisión**.
 *
 * - **Una sola petición** `GET /users/{id}/effective-permissions`, sin parámetros de
 *   paginación: la respuesta no se pagina (`api.md §7.3`) y se pinta con el modo
 *   `local` de `src/data-table` (`RN-PERM-43`), ordenando, filtrando y buscando en
 *   cliente. «Recalcular» y el cambio de idioma (`RN-CORE-63`: los nombres de rol
 *   vienen traducidos) repiten la petición; cambiar un filtro, no.
 * - **Inerte ≠ ausente** (`RN-PERM-42`): un permiso denegado por un `deny` lo dice
 *   («Denegado por «{rol}»…»); uno concedido pero inerte dice «Concedido, pero sin
 *   efecto» con el motivo. Los motivos desconocidos se pintan en crudo.
 * - **Sin salida del dato** (`RN-PERM-45`): ni exportación, ni impresión, ni copia
 *   al almacenamiento del navegador (`RN-CORE-50`); en la URL solo el `public_id`
 *   del sujeto — la tabla no declara estado en la URL.
 * - Un `404` (usuario inexistente, de otro centro o dado de baja) pinta «no
 *   encontrado».
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { CircleCheck, CircleX } from '@lucide/vue'
import { i18n, useT } from '@/i18n'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  DataTable,
  DataTableEmptyValue,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFilter,
  type DataTableLocalFetcher,
} from '@/data-table'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import { resolveErrorState, type ShellErrorState } from '@/layouts/errorState'
import { getUserEffectivePermissions } from '../api'
import { usePermissions } from '../composables/usePermissions'
import { compareActions, usePermissionVocabulary } from '../permissionVocabulary'
import type { EffectivePermission, EffectivePermissionsResponse, PublicId } from '../types'

const t = useT()
const route = useRoute()
const { can } = usePermissions()
const vocabulary = usePermissionVocabulary()
const { formatDateTime } = useDataTableFormatters()

const publicId = computed<PublicId>(() => String(route.params.publicId))

const loading = ref(true)
const loadError = ref<ShellErrorState | null>(null)
const response = ref<EffectivePermissionsResponse | null>(null)
const table = ref<{ refresh: () => Promise<void> } | null>(null)

const rows = computed<EffectivePermission[]>(() => response.value?.data ?? [])
const subjectName = computed(() => response.value?.meta.subject.display_name ?? '')
const canReadRoles = computed(() => can('rol.leer'))

async function fetchOnce(): Promise<void> {
  response.value = await getUserEffectivePermissions(publicId.value)
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null

  try {
    await fetchOnce()
  } catch (err) {
    response.value = null
    loadError.value = resolveErrorState(err)
  } finally {
    loading.value = false
  }
}

/** «Recalcular» (`RN-PERM-41`): sin caché, vuelve a pedir y repinta la tabla. */
async function recalculate(): Promise<void> {
  try {
    await fetchOnce()
    loadError.value = null
    await table.value?.refresh()
  } catch (err) {
    response.value = null
    loadError.value = resolveErrorState(err)
  }
}

onMounted(() => {
  void load()
})

// `RN-CORE-63`: los nombres de rol llegan traducidos por el servidor.
watch(
  () => i18n.global.locale.value,
  () => {
    if (response.value !== null) {
      void recalculate()
    }
  },
)

// --- Tabla (modo `local`, RN-PERM-43/44) --------------------------------------------

function resourceLabel(row: EffectivePermission): string {
  return vocabulary.resource(row.resource, row.resource_label)
}

function hasDeny(row: EffectivePermission): boolean {
  return row.sources.some((source) => source.effect === 'deny')
}

/** `RN-PERM-42`: denegado y todas sus fuentes `allow` son inertes (y no hay `deny`). */
function grantedButInert(row: EffectivePermission): boolean {
  const grants = row.sources.filter((source) => source.effect === 'allow')

  return (
    row.decision === 'denegado' &&
    !hasDeny(row) &&
    grants.length > 0 &&
    grants.every((source) => source.inert)
  )
}

function vetoingRoles(row: EffectivePermission): string {
  return row.sources
    .filter((source) => source.effect === 'deny')
    .map((source) => source.role.name)
    .join(', ')
}

function inertReasons(row: EffectivePermission): string {
  return [
    ...new Set(
      row.sources
        .filter((source) => source.effect === 'allow' && source.inert && source.inert_reason)
        .map((source) => vocabulary.inertReason(source.inert_reason!)),
    ),
  ].join(', ')
}

function scopesText(row: EffectivePermission): string | null {
  if (row.decision !== 'permitido') {
    return null
  }

  if (row.unrestricted) {
    return t('core.effectivePermissions.unrestricted')
  }

  return row.scopes.length > 0
    ? row.scopes.map((scope) => vocabulary.scope(scope)).join(', ')
    : null
}

function sourcesText(row: EffectivePermission): string | null {
  return row.sources.length > 0 ? row.sources.map((source) => source.role.name).join(', ') : null
}

const columns = computed<DataTableColumn<EffectivePermission>[]>(() => [
  {
    id: 'resource',
    headerKey: 'core.effectivePermissions.columns.resource',
    value: resourceLabel,
    sortable: true,
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'action',
    headerKey: 'core.effectivePermissions.columns.action',
    value: (row) => vocabulary.action(row.action),
    compare: (a, b) => compareActions(a.action, b.action),
    sortable: true,
    card: 'subtitle',
  },
  {
    id: 'decision',
    headerKey: 'core.effectivePermissions.columns.decision',
    value: (row) => vocabulary.decision(row.decision),
    sortable: true,
    card: 'field',
  },
  {
    id: 'scopes',
    headerKey: 'core.effectivePermissions.columns.scopes',
    value: scopesText,
    card: 'field',
  },
  {
    id: 'sources',
    headerKey: 'core.effectivePermissions.columns.sources',
    value: sourcesText,
    card: 'field',
  },
  {
    id: 'module_code',
    headerKey: 'core.effectivePermissions.columns.module',
    value: (row) => row.module_code,
    sortable: true,
    defaultHidden: true,
    card: 'field',
  },
  {
    id: 'code',
    headerKey: 'core.effectivePermissions.columns.code',
    value: (row) => row.code,
    sortable: true,
    defaultHidden: true,
    card: 'field',
  },
])

const filters = computed<DataTableFilter<EffectivePermission>[]>(() => [
  {
    type: 'enum',
    id: 'decision',
    labelKey: 'core.effectivePermissions.filters.decision',
    options: [
      { value: 'permitido', label: vocabulary.decision('permitido') },
      { value: 'denegado', label: vocabulary.decision('denegado') },
    ],
    rowValue: (row) => row.decision,
  },
  {
    type: 'enum',
    id: 'module',
    labelKey: 'core.effectivePermissions.filters.module',
    options: [...new Set(rows.value.map((row) => row.module_code))].map((code) => ({
      value: code,
      label: code,
    })),
    rowValue: (row) => row.module_code,
  },
  {
    // `RN-PERM-42`/§20.10: la respuesta trae una fila por cada código del catálogo y casi todas son
    // «Denegado» sin procedencia; desmarcado (por defecto) se muestran solo las que tienen alguna fuente.
    type: 'boolean',
    id: 'include_empty',
    labelKey: 'core.effectivePermissions.filters.includeEmpty',
    twoState: true,
    inclusion: true,
    rowValue: (row) => row.sources.length > 0,
  },
])

function searchText(row: EffectivePermission): string {
  return [
    resourceLabel(row),
    vocabulary.action(row.action),
    row.code,
    ...row.sources.map((source) => source.role.name),
  ].join(' ')
}

const fetchRows: DataTableLocalFetcher<EffectivePermission> = () =>
  Promise.resolve({ data: rows.value })
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-6">
    <LoadingState v-if="loading" />

    <ErrorState v-else-if="loadError" :state="loadError" @retry="load" />

    <template v-else-if="response">
      <header class="flex flex-col gap-1">
        <h1 class="text-lg font-semibold">
          {{ t('core.effectivePermissions.heading', { name: subjectName }) }}
        </h1>
        <p class="text-muted-foreground text-sm">{{ t('core.effectivePermissions.intro') }}</p>
      </header>

      <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-muted-foreground text-xs">{{
            t('core.effectivePermissions.roles')
          }}</span>
          <ul v-if="response.meta.roles.length > 0" class="flex flex-wrap gap-2">
            <li
              v-for="role in response.meta.roles"
              :key="role.public_id"
              class="bg-secondary text-secondary-foreground rounded-md px-2 py-0.5"
            >
              <RouterLink
                v-if="canReadRoles"
                :to="{ name: 'core-role-detail', params: { publicId: role.public_id } }"
                class="focus-visible:ring-ring/50 rounded-sm underline-offset-4 outline-none hover:underline focus-visible:ring-3"
              >
                {{ role.name }}
              </RouterLink>
              <template v-else>{{ role.name }}</template>
            </li>
          </ul>
          <span v-else>{{ t('core.effectivePermissions.noRoles') }}</span>
        </div>

        <p data-slot="effective-computed-at">
          {{
            t('core.effectivePermissions.computedAt', {
              date: formatDateTime(response.meta.computed_at) ?? response.meta.computed_at,
            })
          }}
        </p>

        <Button type="button" variant="outline" @click="recalculate">
          {{ t('core.effectivePermissions.recalculate') }}
        </Button>
      </div>

      <DataTable
        ref="table"
        table-id="core.effective_permissions"
        :caption="t('core.effectivePermissions.caption', { name: subjectName })"
        :columns="columns"
        mode="local"
        :fetcher="fetchRows"
        :row-key="(row: EffectivePermission) => row.code"
        :filters="filters"
        searchable
        :search-text="searchText"
        hide-single-page-footer
        :empty-title="t('core.effectivePermissions.empty.title')"
        :empty-text="t('core.effectivePermissions.empty.text')"
        :card-heading-level="2"
      >
        <template #cell-resource="{ row }">
          <span class="block">{{ resourceLabel(row) }}</span>
          <Badge v-if="row.is_special_category" variant="outline" class="mt-1">
            {{ t('core.effectivePermissions.special') }}
          </Badge>
        </template>

        <template #cell-decision="{ row }">
          <span class="inline-flex items-center gap-1">
            <CircleCheck
              v-if="row.decision === 'permitido'"
              class="size-4 shrink-0"
              aria-hidden="true"
            />
            <CircleX v-else class="text-destructive size-4 shrink-0" aria-hidden="true" />
            {{ vocabulary.decision(row.decision) }}
          </span>
          <span
            v-if="row.decision === 'denegado' && hasDeny(row)"
            class="text-muted-foreground mt-1 block text-xs"
          >
            {{ t('core.effectivePermissions.vetoedBy', { role: vetoingRoles(row) }) }}
          </span>
          <span v-else-if="grantedButInert(row)" class="text-muted-foreground mt-1 block text-xs">
            {{ t('core.effectivePermissions.grantedInert', { reason: inertReasons(row) }) }}
          </span>
        </template>

        <template #cell-sources="{ row }">
          <ul v-if="row.sources.length > 0" class="flex flex-col gap-1">
            <li v-for="(source, index) in row.sources" :key="`${source.role.public_id}-${index}`">
              <RouterLink
                v-if="canReadRoles"
                :to="{ name: 'core-role-detail', params: { publicId: source.role.public_id } }"
                class="text-primary-on-background focus-visible:ring-ring/50 rounded-sm underline-offset-4 outline-none hover:underline focus-visible:ring-3"
              >
                {{ source.role.name }}
              </RouterLink>
              <template v-else>{{ source.role.name }}</template>
              ·
              {{
                source.effect === 'deny'
                  ? t('core.effectivePermissions.sources.denies')
                  : t('core.effectivePermissions.sources.allows')
              }}
              ·
              {{
                source.effect === 'deny'
                  ? t('core.effectivePermissions.anyScope')
                  : vocabulary.scope(source.scope)
              }}
              <span v-if="source.inert" class="text-muted-foreground block text-xs">
                {{
                  t('core.effectivePermissions.sources.inert', {
                    reason: vocabulary.inertReason(source.inert_reason ?? ''),
                  })
                }}
              </span>
            </li>
          </ul>
          <DataTableEmptyValue v-else />
        </template>
      </DataTable>
    </template>
  </div>
</template>
