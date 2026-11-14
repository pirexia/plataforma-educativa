<script setup lang="ts">
/**
 * `/cuenta/sesiones` (funcional.md §B.11, api.md §B.2-§B.4). Con sesión,
 * sin `AppLayout` — misma categoría que `/cuenta/contrasena`
 * (`PasswordChangeView.vue`): formulario/panel aislado, sin depender del
 * *layout* de 1.8 ni del *design system* de 1.7. Sin sesión, redirige a
 * `/entrar`.
 *
 * REQ-AUTH-005 puntos 2-3: listado de sesiones activas y cierre remoto,
 * individual y masivo (`scope=others`). Sin *branding* de tenant
 * (funcional.md §B.11: "el branding de las pantallas con sesión es
 * asunto de 1.7/1.8, no de este paso").
 *
 * RN-AUTH-28: ningún dato de sesión en `localStorage`/`sessionStorage` —
 * todo el estado vive en variables reactivas de este componente.
 *
 * `REQ-CORE` 1.9f (`docs/modulos/REQ-CORE/funcional.md §14.13.5`,
 * `RN-CORE-84`/`-64`/`-95`/`-96`): el listado pasa por el componente de
 * tabla de `src/data-table`, ahora paginado en servidor; las
 * confirmaciones usan el diálogo común, que asume la gestión de foco que
 * esta vista hacía a mano.
 */
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useT } from '@/i18n'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useConfirm } from '@/components/useConfirm'
import { Button } from '@/components/ui/button'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFetcher,
} from '@/data-table'
import { listSessions, revokeOtherSessions, revokeSession, type UserSessionSummary } from '../api'
import { apiErrorStatus, retryAfterSeconds } from '../composables/formErrors'

const t = useT()
const router = useRouter()
const { formatDateTime } = useDataTableFormatters()
const confirmation = useConfirm()

const table = ref<{ refresh: () => Promise<void> } | null>(null)
const errorMessage = ref<string | null>(null)
const statusMessage = ref<string | null>(null)

/** public_id de la fila cuya revocación está en curso, o null. */
const revokingId = ref<string | null>(null)
const revokingOthers = ref(false)

/**
 * `meta.total` de la última respuesta del listado: exactamente una fila es
 * `current` (CA-AUTH-082), así que hay otras sesiones si y solo si `total > 1`.
 * Sin respuesta todavía (`null`), «Cerrar las demás sesiones» está deshabilitado.
 */
const totalSessions = ref<number | null>(null)

function hasOtherSessions(): boolean {
  return totalSessions.value !== null && totalSessions.value > 1
}

function deviceLabel(session: UserSessionSummary): string {
  const { browser, platform } = session.client
  return `${browser} · ${platform}`
}

function deviceTypeLabel(type: UserSessionSummary['client']['device_type']): string {
  return t(`auth.sessions.deviceType.${type}`)
}

/**
 * Un único nodo de texto para las etiquetas de la fila (tipo de
 * dispositivo + insignias), en vez de `<span>` contiguos: el formateador
 * del proyecto colapsa el espacio en blanco entre elementos en línea
 * consecutivos, y dos insignias a la vez quedaban pegadas sin separador.
 */
function deviceSummary(session: UserSessionSummary): string {
  const parts = [deviceTypeLabel(session.client.device_type)]

  if (session.current) {
    parts.push(t('auth.sessions.current'))
  }

  if (!session.device_known) {
    parts.push(t('auth.sessions.deviceUnknownBadge'))
  }

  return parts.join(' · ')
}

/** Nombre único de la fila (WCAG 2.4.6): dispositivo y fecha de inicio, porque el dispositivo solo puede repetirse. */
function revokeLabel(session: UserSessionSummary): string {
  return t('auth.sessions.revokeFor', {
    device: deviceLabel(session),
    date: formatDateTime(session.started_at) ?? '',
  })
}

const columns: DataTableColumn<UserSessionSummary>[] = [
  {
    id: 'device',
    headerKey: 'auth.sessions.columnDevice',
    value: (session) => deviceLabel(session),
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'started_at',
    headerKey: 'auth.sessions.columnStarted',
    value: (session) => formatDateTime(session.started_at),
    card: 'field',
  },
  {
    id: 'last_activity_at',
    headerKey: 'auth.sessions.columnLastActivity',
    value: (session) => formatDateTime(session.last_activity_at),
    card: 'field',
  },
  {
    id: 'ip_address',
    headerKey: 'auth.sessions.columnIp',
    value: (session) => session.ip_address,
    card: 'field',
  },
  {
    id: 'actions',
    headerKey: 'auth.sessions.columnActions',
    hideable: false,
    card: 'actions',
  },
]

// RN-CORE-26: la comprobación de sesión la hace el guard del router
// (`src/router/guard.ts`), una sola vez, antes de montar esta vista —
// ya no se pide `GET /me` aquí para saber si hay sesión.

/**
 * `GET /auth/sessions` con `page` y `per_page` del componente. Un `401` navega
 * a `login` (paridad) y relanza el error; los demás errores de carga los trata
 * el componente (`RN-CORE-95`).
 */
const fetchSessions: DataTableFetcher<UserSessionSummary> = async (query) => {
  try {
    const result = await listSessions({ page: query.page, per_page: query.per_page })

    totalSessions.value = result.meta.total

    return result
  } catch (err) {
    if (apiErrorStatus(err) === 401) {
      await router.push({ name: 'login' })
    }

    throw err
  }
}

async function revoke(session: UserSessionSummary): Promise<void> {
  const label = revokeLabel(session)
  const confirmed = await confirmation.ask({
    title: label,
    description: session.current
      ? t('auth.sessions.confirmRevokeCurrent')
      : t('auth.sessions.confirmRevoke'),
    confirmLabel: label,
    destructive: true,
  })

  if (!confirmed) {
    return
  }

  revokingId.value = session.public_id
  errorMessage.value = null
  statusMessage.value = null

  try {
    await revokeSession(session.public_id)

    if (session.current) {
      await router.push({ name: 'login' })
      return
    }

    statusMessage.value = t('auth.sessions.revokedSuccess')
    await table.value?.refresh()
  } catch (err) {
    const status = apiErrorStatus(err)

    if (status === 401) {
      await router.push({ name: 'login' })
      return
    }

    if (status === 404 || status === 409) {
      // Ya no existe o ya estaba cerrada: no es un error del usuario; el
      // listado vuelve a pedirse sin mensaje.
      await table.value?.refresh()
      return
    }

    errorMessage.value = failureMessage(err, status)
  } finally {
    revokingId.value = null
  }
}

function failureMessage(err: unknown, status: number | null): string {
  if (status === 429) {
    const seconds = retryAfterSeconds(err)

    return seconds !== null
      ? t('auth.common.tooManyRequestsWithSeconds', { seconds })
      : t('auth.common.tooManyRequests')
  }

  return t('auth.common.unexpectedError')
}

async function revokeOthers(): Promise<void> {
  const confirmed = await confirmation.ask({
    title: t('auth.sessions.revokeOthers'),
    description: t('auth.sessions.confirmRevokeOthers'),
    confirmLabel: t('auth.sessions.revokeOthers'),
    destructive: true,
  })

  if (!confirmed) {
    return
  }

  revokingOthers.value = true
  errorMessage.value = null
  statusMessage.value = null

  try {
    await revokeOtherSessions()
    statusMessage.value = t('auth.sessions.revokeOthersSuccess')
    await table.value?.refresh()
  } catch (err) {
    const status = apiErrorStatus(err)

    if (status === 401) {
      await router.push({ name: 'login' })
      return
    }

    errorMessage.value = failureMessage(err, status)
  } finally {
    revokingOthers.value = false
  }
}
</script>

<template>
  <div class="mx-auto flex max-w-4xl flex-col px-4 py-10">
    <div class="border-border bg-background w-full rounded-xl border p-6 shadow-sm">
      <h1 class="mb-1 text-lg font-semibold">{{ t('auth.sessions.title') }}</h1>
      <p class="text-muted-foreground mb-4 text-sm">{{ t('auth.sessions.intro') }}</p>

      <p
        v-if="statusMessage"
        role="status"
        class="border-border bg-muted mb-4 rounded-lg border px-3 py-2 text-sm"
      >
        {{ statusMessage }}
      </p>
      <p v-if="errorMessage" role="alert" class="text-destructive mb-4 text-sm">
        {{ errorMessage }}
      </p>

      <div class="mb-4 flex justify-end">
        <Button
          type="button"
          variant="outline"
          :disabled="!hasOtherSessions() || revokingOthers"
          @click="revokeOthers"
        >
          {{ revokingOthers ? t('auth.sessions.revoking') : t('auth.sessions.revokeOthers') }}
        </Button>
      </div>

      <DataTable
        ref="table"
        table-id="auth.sessions"
        :caption="t('auth.sessions.title')"
        :columns="columns"
        mode="page"
        :fetcher="fetchSessions"
        :empty-title="t('auth.sessions.empty')"
        :card-heading-level="2"
      >
        <template #cell-device="{ row }">
          <span class="block">{{ deviceLabel(row) }}</span>
          <span class="text-muted-foreground block text-xs">{{ deviceSummary(row) }}</span>
        </template>
        <template #cell-actions="{ row }">
          <Button
            type="button"
            variant="outline"
            size="sm"
            :disabled="revokingId === row.public_id"
            :aria-label="revokeLabel(row)"
            @click="revoke(row)"
          >
            {{ t('auth.sessions.revoke') }}
          </Button>
        </template>
      </DataTable>
    </div>

    <ConfirmDialog
      :open="confirmation.open.value"
      :request="confirmation.request.value"
      @confirm="confirmation.confirm"
      @cancel="confirmation.cancel"
    />
  </div>
</template>
