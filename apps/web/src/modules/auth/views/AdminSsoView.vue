<script setup lang="ts">
/**
 * `/administracion/sso` (REQ-AUTH-004, 1.4b, funcional.md §F.9,
 * api.md §F.2). Lista del catálogo del centro: estado, dominios
 * admitidos, modo de aprovisionamiento y el aviso de caducidad de la
 * credencial (`secret_status.expiring_soon`). Autoservicio del
 * administrador de centro (`ADR-043 §8.3`): sin `AppLayout` ni guard de
 * router — la SPA no es control de acceso (`INV-002`), el servidor
 * responde `403` si falta el permiso `proveedor_identidad.leer` y esta
 * pantalla lo muestra tal cual.
 */
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { deleteIdentityProvider, getIdentityProvidersCatalog } from '../api'
import { apiErrorStatus } from '../composables/formErrors'
import type { IdentityProviderSummary } from '../types'

const t = useT()
const router = useRouter()

const loading = ref(true)
const loadError = ref<string | null>(null)
const providers = ref<IdentityProviderSummary[]>([])
const deletingId = ref<string | null>(null)

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const page = await getIdentityProvidersCatalog({ per_page: 100 })
    providers.value = page.data
  } catch (err) {
    if (apiErrorStatus(err) === 401) {
      await router.push({ name: 'login' })
      return
    }

    loadError.value = t('auth.ssoAdmin.loadError')
  } finally {
    loading.value = false
  }
}

onMounted(load)

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleDateString() : ''
}

async function remove(provider: IdentityProviderSummary) {
  if (!window.confirm(t('auth.ssoAdmin.confirmDelete'))) {
    return
  }

  deletingId.value = provider.public_id

  try {
    await deleteIdentityProvider(provider.public_id)
    providers.value = providers.value.filter((p) => p.public_id !== provider.public_id)
  } catch {
    loadError.value = t('auth.ssoAdmin.loadError')
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

    <p v-if="loadError" role="alert" class="text-destructive text-sm">{{ loadError }}</p>
    <p v-if="loading" class="text-muted-foreground text-sm">{{ t('auth.ssoAdmin.loading') }}</p>

    <p v-else-if="providers.length === 0" class="text-muted-foreground text-sm">
      {{ t('auth.ssoAdmin.empty') }}
    </p>

    <Table v-else>
      <TableHeader>
        <TableRow>
          <TableHead>{{ t('auth.ssoAdmin.columns.displayName') }}</TableHead>
          <TableHead>{{ t('auth.ssoAdmin.columns.issuer') }}</TableHead>
          <TableHead>{{ t('auth.ssoAdmin.columns.status') }}</TableHead>
          <TableHead>{{ t('auth.ssoAdmin.columns.provisioningMode') }}</TableHead>
          <TableHead>{{ t('auth.ssoAdmin.columns.secret') }}</TableHead>
          <TableHead />
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableRow v-for="provider in providers" :key="provider.public_id">
          <TableCell class="font-medium">{{ provider.display_name }}</TableCell>
          <TableCell class="max-w-64 truncate text-sm">{{ provider.issuer }}</TableCell>
          <TableCell>
            {{
              provider.is_enabled
                ? t('auth.ssoAdmin.status.enabled')
                : t('auth.ssoAdmin.status.disabled')
            }}
          </TableCell>
          <TableCell>{{
            t(`auth.ssoAdmin.provisioningMode.${provider.provisioning_mode}`)
          }}</TableCell>
          <TableCell>
            <span v-if="!provider.secret_status.has_active" class="text-destructive">
              {{ t('auth.ssoAdmin.secretStatus.none') }}
            </span>
            <span v-else-if="provider.secret_status.expiring_soon" class="text-amber-600">
              {{ t('auth.ssoAdmin.secretStatus.expiringSoon') }}
              <template v-if="provider.secret_status.active_expires_at">
                ({{ formatDate(provider.secret_status.active_expires_at) }})
              </template>
            </span>
            <span v-else>{{ t('auth.ssoAdmin.secretStatus.active') }}</span>
          </TableCell>
          <TableCell class="flex justify-end gap-2">
            <Button variant="outline" size="sm" as-child>
              <RouterLink
                :to="{ name: 'sso-administration-edit', params: { publicId: provider.public_id } }"
              >
                {{ t('auth.ssoAdmin.edit') }}
              </RouterLink>
            </Button>
            <Button
              variant="outline"
              size="sm"
              :disabled="deletingId === provider.public_id"
              @click="remove(provider)"
            >
              {{ t('auth.ssoAdmin.delete') }}
            </Button>
          </TableCell>
        </TableRow>
      </TableBody>
    </Table>
  </div>
</template>
