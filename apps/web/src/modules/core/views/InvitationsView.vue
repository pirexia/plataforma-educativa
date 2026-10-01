<script setup lang="ts">
/**
 * `/administracion/invitaciones` (`docs/modulos/REQ-CORE/funcional.md §14.5`,
 * `RN-CORE-70`/`-64`, 1.9b). Listado de invitaciones con el componente de
 * tablas: modo `page`, estado en la URL, sin búsqueda (el *endpoint* no
 * acepta `q`) ni columnas ordenables (no acepta `sort`; el servidor entrega
 * la más reciente primero). Filtro de estado `enum` múltiple.
 *
 * - «Revocar» (`invitacion.eliminar`) solo en las `vigente`. «Reenviar»
 *   (`invitacion.crear`, `POST /users/{user}/invitations`) en las `caducada`
 *   y `revocada`. Las dos piden confirmación (`RN-CORE-64`).
 * - Si el usuario ya no está `pendiente`, el servidor responde `409`
 *   (`RN-CORE-12`): se muestra su `detail` y se refresca la fila. `429`
 *   (límite de reenvíos) muestra los segundos de `Retry-After`.
 * - El enlace a la ficha del usuario solo existe con `usuario.leer`
 *   (`RN-CORE-62`).
 */
import { computed, ref } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useConfirm } from '@/components/useConfirm'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFetcher,
  type DataTableFilter,
} from '@/data-table'
import { issueInvitation, listInvitations, revokeInvitation } from '../api'
import { problemDetail, problemRetryAfter, problemStatus } from '../composables/problem'
import { usePermissions } from '../composables/usePermissions'
import type { Invitation, InvitationStatus } from '../types'

const t = useT()
const { can } = usePermissions()
const { formatDateTime } = useDataTableFormatters()
const confirmation = useConfirm()

const table = ref<{ refresh: () => Promise<void> } | null>(null)
const busy = ref(false)
const message = ref<string | null>(null)
const errorMessage = ref<string | null>(null)

const STATUSES: InvitationStatus[] = ['vigente', 'caducada', 'revocada', 'aceptada']

function statusLabel(status: string): string {
  const key = `core.invitation.status.${status}`
  const label = t(key)

  // ADR-038 §7.3: un valor no anticipado muestra su código, no rompe.
  return label === key ? status : label
}

const filters: DataTableFilter[] = [
  {
    type: 'enum',
    id: 'status',
    labelKey: 'core.invitations.filters.status',
    options: STATUSES.map((value) => ({ value, labelKey: `core.invitation.status.${value}` })),
  },
]

const columns: DataTableColumn<Invitation>[] = [
  {
    id: 'user',
    headerKey: 'core.invitations.columns.user',
    value: (invitation) => invitation.user.email,
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'status',
    headerKey: 'core.invitations.columns.status',
    value: (invitation) => statusLabel(invitation.status),
    card: 'subtitle',
  },
  {
    id: 'expires_at',
    headerKey: 'core.invitations.columns.expiresAt',
    value: (invitation) => formatDateTime(invitation.expires_at),
    card: 'field',
  },
  {
    id: 'created_at',
    headerKey: 'core.invitations.columns.createdAt',
    value: (invitation) => formatDateTime(invitation.created_at),
    card: 'field',
  },
  {
    id: 'resolved_at',
    headerKey: 'core.invitations.columns.resolvedAt',
    value: (invitation) => {
      const at = invitation.accepted_at ?? invitation.revoked_at

      return at ? formatDateTime(at) : null
    },
    card: 'field',
  },
  {
    id: 'actions',
    headerKey: 'core.invitations.columns.actions',
    hideable: false,
    card: 'actions',
  },
]

const fetchInvitations: DataTableFetcher<Invitation> = (query) =>
  listInvitations({
    status: query.filters.status
      ? (query.filters.status.split(',') as InvitationStatus[])
      : undefined,
    page: query.page,
    per_page: query.per_page,
  })

const canRevoke = computed(() => can('invitacion.eliminar'))
const canReissue = computed(() => can('invitacion.crear'))

function fail(err: unknown): void {
  const status = problemStatus(err)

  if (status === 429) {
    const seconds = problemRetryAfter(err)

    errorMessage.value =
      seconds !== null
        ? t('core.users.errors.tooManyRequestsWithSeconds', { seconds })
        : t('core.users.errors.tooManyRequests')

    return
  }

  errorMessage.value = problemDetail(err) ?? t('core.users.errors.unexpected')
}

async function run(action: () => Promise<void>): Promise<void> {
  busy.value = true
  message.value = null
  errorMessage.value = null

  try {
    await action()
  } catch (err) {
    fail(err)
  } finally {
    busy.value = false
    // 409 incluido: la fila refleja el estado real (RN-CORE-70).
    await table.value?.refresh()
  }
}

async function revoke(invitation: Invitation): Promise<void> {
  const confirmed = await confirmation.ask({
    title: t('core.invitations.confirm.revoke.title', { email: invitation.user.email }),
    description: t('core.invitations.confirm.revoke.description'),
    confirmLabel: t('core.invitations.confirm.revoke.confirm', { email: invitation.user.email }),
    destructive: true,
  })

  if (!confirmed) {
    return
  }

  await run(async () => {
    await revokeInvitation(invitation.public_id)
    message.value = t('core.invitations.flash.revoked', { email: invitation.user.email })
  })
}

async function reissue(invitation: Invitation): Promise<void> {
  const confirmed = await confirmation.ask({
    title: t('core.invitations.confirm.reissue.title', { email: invitation.user.email }),
    description: t('core.invitations.confirm.reissue.description'),
    confirmLabel: t('core.invitations.confirm.reissue.confirm', { email: invitation.user.email }),
  })

  if (!confirmed) {
    return
  }

  await run(async () => {
    const issued = await issueInvitation(invitation.user.public_id)

    message.value = t('core.invitations.flash.reissued', {
      email: invitation.user.email,
      date: formatDateTime(issued.expires_at),
    })
  })
}
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-6">
    <div>
      <h1 class="text-lg font-semibold">{{ t('core.invitations.title') }}</h1>
      <p class="text-muted-foreground text-sm">{{ t('core.invitations.intro') }}</p>
    </div>

    <p
      v-if="message"
      role="status"
      class="border-border bg-muted rounded-lg border px-3 py-2 text-sm"
    >
      {{ message }}
    </p>
    <p v-if="errorMessage" role="alert" class="text-destructive text-sm">{{ errorMessage }}</p>

    <DataTable
      ref="table"
      table-id="core.invitations"
      :caption="t('core.invitations.caption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchInvitations"
      :filters="filters"
      url-state
      :empty-title="t('core.invitations.empty.title')"
      :empty-text="t('core.invitations.empty.text')"
      :card-heading-level="2"
    >
      <template #cell-user="{ row }">
        <RouterLink
          v-if="can('usuario.leer')"
          :to="{ name: 'core-user-detail', params: { publicId: row.user.public_id } }"
          class="text-primary-on-background focus-visible:ring-ring/50 rounded-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-3"
        >
          {{ row.user.email }}
        </RouterLink>
        <template v-else>{{ row.user.email }}</template>
      </template>

      <template #cell-actions="{ row }">
        <Button
          v-if="canRevoke && row.status === 'vigente'"
          type="button"
          variant="outline"
          size="sm"
          :disabled="busy"
          :aria-label="t('core.invitations.revokeFor', { email: row.user.email })"
          @click="revoke(row)"
        >
          {{ t('core.invitations.revoke') }}
        </Button>
        <Button
          v-if="canReissue && (row.status === 'caducada' || row.status === 'revocada')"
          type="button"
          variant="outline"
          size="sm"
          :disabled="busy"
          :aria-label="t('core.invitations.reissueFor', { email: row.user.email })"
          @click="reissue(row)"
        >
          {{ t('core.invitations.reissue') }}
        </Button>
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
