<script setup lang="ts">
/**
 * `/administracion/sso` (REQ-AUTH-004, 1.4b, funcional.md §F.9;
 * ampliada por `1.4c`, `funcional.md §G.9`, `api.md §G.2`). Lista del
 * catálogo del centro: protocolo, estado, dominios admitidos, modo de
 * aprovisionamiento y el aviso de caducidad — de la credencial
 * (`secret_status.expiring_soon`) en un proveedor OIDC, del certificado
 * de firma (`certificate_status`) en uno SAML, hermanos exactos.
 * Autoservicio del administrador de centro (`ADR-043 §8.3`): sin
 * `AppLayout` ni guard de router — la SPA no es control de acceso
 * (`INV-002`), el servidor responde `403` si falta el permiso
 * `proveedor_identidad.leer` y esta pantalla lo muestra tal cual.
 *
 * `REQ-CORE` 1.9f (`docs/modulos/REQ-CORE/funcional.md §14.13.4`,
 * `RN-CORE-84`/`-64`/`-95`/`-96`): el catálogo pasa por el componente de
 * tabla de `src/data-table`, ahora paginado en servidor (25 por página);
 * la confirmación de borrado usa el diálogo común en vez de
 * `window.confirm`.
 */
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useT } from '@/i18n'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useConfirm } from '@/components/useConfirm'
import { Button } from '@/components/ui/button'
import {
  DataTable,
  DataTableEmptyValue,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFetcher,
} from '@/data-table'
import { deleteIdentityProvider, getIdentityProvidersCatalog } from '../api'
import { apiErrorStatus } from '../composables/formErrors'
import type { IdentityProviderSummary } from '../types'

const t = useT()
const router = useRouter()
const { formatDate } = useDataTableFormatters()
const confirmation = useConfirm()

const table = ref<{ refresh: () => Promise<void> } | null>(null)
const actionError = ref<string | null>(null)
const deletingId = ref<string | null>(null)

const columns: DataTableColumn<IdentityProviderSummary>[] = [
  {
    id: 'display_name',
    headerKey: 'auth.ssoAdmin.columns.displayName',
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'protocol',
    headerKey: 'auth.ssoAdmin.columns.protocol',
    value: (provider) => t(`auth.ssoAdmin.protocolLabel.${provider.protocol}`),
    card: 'subtitle',
  },
  {
    id: 'issuer',
    headerKey: 'auth.ssoAdmin.columns.issuer',
    value: (provider) => provider.issuer,
    card: 'field',
  },
  {
    id: 'status',
    headerKey: 'auth.ssoAdmin.columns.status',
    value: (provider) =>
      provider.is_enabled ? t('auth.ssoAdmin.status.enabled') : t('auth.ssoAdmin.status.disabled'),
    card: 'field',
  },
  {
    id: 'provisioning_mode',
    headerKey: 'auth.ssoAdmin.columns.provisioningMode',
    value: (provider) => t(`auth.ssoAdmin.provisioningMode.${provider.provisioning_mode}`),
    card: 'field',
  },
  { id: 'secret', headerKey: 'auth.ssoAdmin.columns.secret', card: 'field' },
  {
    id: 'actions',
    headerKey: 'auth.ssoAdmin.columns.actions',
    hideable: false,
    card: 'actions',
  },
]

/**
 * `GET /identity-providers` con `page` y `per_page` del componente. Un `401`
 * navega a `login` (paridad) y relanza el error; los demás errores de carga
 * los trata el componente (`RN-CORE-95`).
 */
const fetchProviders: DataTableFetcher<IdentityProviderSummary> = async (query) => {
  try {
    return await getIdentityProvidersCatalog({ page: query.page, per_page: query.per_page })
  } catch (err) {
    if (apiErrorStatus(err) === 401) {
      await router.push({ name: 'login' })
    }

    throw err
  }
}

async function remove(provider: IdentityProviderSummary) {
  // funcional.md §G.9: al borrar un proveedor SAML, avisar de que la ACS
  // URL cambiará si vuelve a crearse (va por proveedor, api.md §G.7) y
  // habrá que reconfigurar el IdP — advertencia que un proveedor OIDC no
  // necesita.
  const confirmMessage =
    provider.protocol === 'saml'
      ? `${t('auth.ssoAdmin.confirmDelete')} ${t('auth.ssoAdmin.confirmDeleteSaml')}`
      : t('auth.ssoAdmin.confirmDelete')
  const label = t('auth.ssoAdmin.deleteFor', { name: provider.display_name })

  const confirmed = await confirmation.ask({
    title: label,
    description: confirmMessage,
    confirmLabel: label,
    destructive: true,
  })

  if (!confirmed) {
    return
  }

  deletingId.value = provider.public_id
  actionError.value = null

  try {
    await deleteIdentityProvider(provider.public_id)
    // `RN-CORE-96`: se vuelve a pedir la página; nunca se mutan las filas del componente.
    await table.value?.refresh()
  } catch {
    actionError.value = t('auth.ssoAdmin.deleteError')
  } finally {
    deletingId.value = null
  }
}
</script>

<template>
  <div class="mx-auto flex max-w-5xl flex-col gap-6 px-4 py-10">
    <div class="flex items-center justify-between gap-4">
      <div>
        <h1 class="text-lg font-semibold">{{ t('auth.ssoAdmin.title') }}</h1>
        <p class="text-muted-foreground text-sm">{{ t('auth.ssoAdmin.intro') }}</p>
      </div>
      <Button as-child>
        <RouterLink :to="{ name: 'sso-administration-new' }">{{
          t('auth.ssoAdmin.create')
        }}</RouterLink>
      </Button>
    </div>

    <p v-if="actionError" role="alert" class="text-destructive text-sm">{{ actionError }}</p>

    <DataTable
      ref="table"
      table-id="auth.identity_providers"
      :caption="t('auth.ssoAdmin.tableCaption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchProviders"
      :empty-title="t('auth.ssoAdmin.empty')"
      :card-heading-level="2"
    >
      <template #cell-issuer="{ row }">
        <span v-if="row.issuer" class="block max-w-64 truncate text-sm" :title="row.issuer">{{
          row.issuer
        }}</span>
        <DataTableEmptyValue v-else />
      </template>
      <template #cell-secret="{ row }">
        <template v-if="row.protocol === 'saml'">
          <!--
            api.md §G.2: certificate_status es {vigentes, proximo_vencimiento},
            no un booleano "expiring_soon" precalculado como secret_status —
            no se inventa aquí un umbral de aviso propio de la SPA
            (AUTH_SSO_SECRET_EXPIRY_WARNING_DAYS es una decisión de servidor,
            operacion.md §G.5); el aviso de caducidad lo emite y lo dirige
            el comando diario (CA-AUTH-335).
          -->
          <span
            v-if="!row.certificate_status || row.certificate_status.vigentes === 0"
            class="text-destructive"
          >
            {{ t('auth.ssoAdmin.certificateStatus.none') }}
          </span>
          <span v-else>
            {{
              t('auth.ssoAdmin.certificateStatus.active', {
                count: row.certificate_status.vigentes,
              })
            }}
            <template v-if="row.certificate_status.proximo_vencimiento">
              ({{
                t('auth.ssoAdmin.certificateStatus.nextExpiry', {
                  date: formatDate(row.certificate_status.proximo_vencimiento),
                })
              }})
            </template>
          </span>
        </template>
        <template v-else-if="row.secret_status">
          <span v-if="!row.secret_status.has_active" class="text-destructive">
            {{ t('auth.ssoAdmin.secretStatus.none') }}
          </span>
          <span v-else-if="row.secret_status.expiring_soon" class="text-warning">
            {{ t('auth.ssoAdmin.secretStatus.expiringSoon') }}
            <template v-if="row.secret_status.active_expires_at">
              ({{ formatDate(row.secret_status.active_expires_at) }})
            </template>
          </span>
          <span v-else>{{ t('auth.ssoAdmin.secretStatus.active') }}</span>
        </template>
        <DataTableEmptyValue v-else />
      </template>
      <template #cell-actions="{ row }">
        <div class="flex flex-wrap justify-end gap-2">
          <Button
            variant="outline"
            size="sm"
            as-child
            :aria-label="t('auth.ssoAdmin.editFor', { name: row.display_name })"
          >
            <RouterLink
              :to="{ name: 'sso-administration-edit', params: { publicId: row.public_id } }"
            >
              {{ t('auth.ssoAdmin.edit') }}
            </RouterLink>
          </Button>
          <Button
            type="button"
            variant="outline"
            size="sm"
            :disabled="deletingId === row.public_id"
            :aria-label="t('auth.ssoAdmin.deleteFor', { name: row.display_name })"
            @click="remove(row)"
          >
            {{ t('auth.ssoAdmin.delete') }}
          </Button>
        </div>
      </template>
    </DataTable>

    <ConfirmDialog
      :open="confirmation.open.value"
      :request="confirmation.request.value"
      @confirm="confirmation.confirm"
      @cancel="confirmation.cancel"
    />
  </div>
</template>
