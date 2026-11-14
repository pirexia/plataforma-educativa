<script setup lang="ts">
/**
 * Área 4 de `/administracion/mfa` (funcional.md §D.9.1): listado con
 * `state`, motivo, caducidad y quién la concedió; formulario de
 * concesión; revocación con confirmación. Los tres endpoints de
 * `/mfa-exemptions` (api.md §D.4), permisos `exencion_mfa.crear/leer/
 * eliminar`.
 *
 * `MAX_EXEMPTION_DAYS`: `AUTH_MFA_MAX_EXEMPTION_DAYS` es configuración de
 * aplicación, no de tenant (`funcional.md §D.0`, a diferencia de
 * `mfa_grace_period_days`), y no hay endpoint que la exponga — añadir uno
 * solo para esto adelantaría alcance que `api.md §D.5.1` cierra a
 * propósito. Se muestra el valor de fábrica como aviso informativo en el
 * formulario (funcional.md §D.9.1: "el tope de 90 días visible"); la
 * validación real, como siempre, la hace el servidor (`INV-010`) — si el
 * valor de entorno cambia algún día, este número hay que actualizarlo a
 * mano aquí.
 *
 * `REQ-CORE` 1.9f (`docs/modulos/REQ-CORE/funcional.md §14.13.3`,
 * `RN-CORE-84`/`-94`/`-95`/`-96`): el listado pasa por el componente de
 * tabla de `src/data-table` con paridad de peticiones (salvo `per_page`,
 * ahora explícito), de acciones y de mensajes de las acciones. El
 * formulario de concesión queda fuera de la tabla, sin cambios.
 */
import { ref } from 'vue'
import { useT } from '@/i18n'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useConfirm } from '@/components/useConfirm'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import {
  DataTable,
  DataTableEmptyValue,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableFetcher,
  type DataTableFilter,
} from '@/data-table'
import { createMfaExemption, listMfaExemptions, revokeMfaExemption } from '../../api'
import { apiErrorDetail, apiErrorStatus, fieldErrors } from '../../composables/formErrors'
import MfaUserPicker from './MfaUserPicker.vue'
import type { MfaComplianceUserSummary, MfaExemption, MfaExemptionState } from '../../types'

const MAX_EXEMPTION_DAYS = 90

const t = useT()
const { formatDateTime } = useDataTableFormatters()
const confirmation = useConfirm()

function fullName(person: { given_name: string; family_name_1: string }): string {
  return `${person.given_name} ${person.family_name_1}`
}

// -- Listado ----------------------------------------------------------------

const STATE_FILTERS: MfaExemptionState[] = ['live', 'expired', 'revoked']

/** `OPEN-CORE-54` = A: «live» es el estado de reposo del filtro; «Todos» no envía `state`. */
const filters: DataTableFilter[] = [
  {
    type: 'enum',
    id: 'state',
    labelKey: 'auth.mfaAdmin.exemptions.filterLegend',
    multiple: false,
    initial: 'live',
    options: STATE_FILTERS.map((value) => ({
      value,
      labelKey: `auth.mfaAdmin.exemptions.state.${value}`,
    })),
  },
]

/** Cambiar la `key` vuelve a montar la tabla en su estado inicial: página 1, `state=live` (`OPEN-CORE-56` = A). */
const tableKey = ref(0)
const table = ref<{ refresh: () => Promise<void> } | null>(null)

const columns: DataTableColumn<MfaExemption>[] = [
  {
    id: 'user',
    headerKey: 'auth.mfaAdmin.exemptions.columnUser',
    value: (row) => `${fullName(row.user)} · ${row.user.email}`,
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'state',
    headerKey: 'auth.mfaAdmin.exemptions.columnState',
    value: (row) => row.state,
    card: 'subtitle',
  },
  {
    id: 'reason',
    headerKey: 'auth.mfaAdmin.exemptions.columnReason',
    value: (row) => row.reason,
    card: 'field',
  },
  {
    id: 'expires_at',
    headerKey: 'auth.mfaAdmin.exemptions.columnExpiresAt',
    value: (row) => formatDateTime(row.expires_at),
    card: 'field',
  },
  {
    id: 'granted_by',
    headerKey: 'auth.mfaAdmin.exemptions.columnGrantedBy',
    value: (row) => fullName(row.granted_by),
    card: 'field',
  },
  {
    id: 'actions',
    headerKey: 'auth.mfaAdmin.exemptions.columnActions',
    hideable: false,
    card: 'actions',
  },
]

/** `GET /mfa-exemptions` (api.md §D.4): `state`, `page` y `per_page`. Los errores los trata el componente (`RN-CORE-95`). */
const fetchExemptions: DataTableFetcher<MfaExemption> = (query) =>
  listMfaExemptions({
    state: query.filters.state ? [query.filters.state as MfaExemptionState] : undefined,
    page: query.page,
    per_page: query.per_page,
  })

// -- Conceder -----------------------------------------------------------

const grantOpen = ref(false)
const grantUser = ref<MfaComplianceUserSummary | null>(null)
const grantReason = ref('')
const grantExpiresAt = ref('')
const grantSubmitting = ref(false)
const grantErrors = ref<string[]>([])
const grantConflict = ref<string | null>(null)

function openGrant(): void {
  grantOpen.value = true
  grantUser.value = null
  grantReason.value = ''
  grantExpiresAt.value = ''
  grantErrors.value = []
  grantConflict.value = null
}

function cancelGrant(): void {
  grantOpen.value = false
}

async function submitGrant(): Promise<void> {
  if (!grantUser.value || !grantExpiresAt.value) {
    return
  }

  grantSubmitting.value = true
  grantErrors.value = []
  grantConflict.value = null

  try {
    // El input `date` entrega solo el día: se interpreta a las 00:00 del
    // huso del centro (funcional.md §D.4: "caduca a las 00:00 de ese
    // día", sin redondear a las 23:59).
    const expiresAtIso = new Date(`${grantExpiresAt.value}T00:00:00`).toISOString()

    await createMfaExemption({
      user: grantUser.value.public_id,
      reason: grantReason.value,
      expires_at: expiresAtIso,
    })

    grantOpen.value = false
    tableKey.value += 1
  } catch (err) {
    const status = apiErrorStatus(err)

    if (status === 403) {
      // api.md §D.4: el 403 de autoexención (RN-AUTH-81) trae `detail`
      // distinguido; el 403 llano de `permission:` no, y entonces es "no
      // tienes permiso" (D.9 regla 1).
      grantConflict.value = apiErrorDetail(err) ?? t('auth.mfaAdmin.forbidden')
    } else if (status === 409) {
      grantConflict.value = t('auth.mfaAdmin.exemptions.alreadyLive')
    } else if (status === 422) {
      grantErrors.value = [...fieldErrors(err, 'reason'), ...fieldErrors(err, 'expires_at')]
      if (grantErrors.value.length === 0) {
        grantErrors.value = [t('auth.common.unexpectedError')]
      }
    } else if (status === 404) {
      grantConflict.value = t('auth.mfaAdmin.reset.userNotFound')
    } else {
      grantConflict.value = t('auth.common.unexpectedError')
    }
  } finally {
    grantSubmitting.value = false
  }
}

// -- Revocar --------------------------------------------------------------

const revokingId = ref<string | null>(null)
const revokeError = ref<string | null>(null)

async function revoke(exemption: MfaExemption): Promise<void> {
  const name = fullName(exemption.user)
  const confirmed = await confirmation.ask({
    title: t('auth.mfaAdmin.exemptions.revokeActionFor', { name }),
    description: t('auth.mfaAdmin.exemptions.confirmRevoke'),
    confirmLabel: t('auth.mfaAdmin.exemptions.revokeActionFor', { name }),
    destructive: true,
  })

  if (!confirmed) {
    return
  }

  revokingId.value = exemption.public_id
  revokeError.value = null

  try {
    await revokeMfaExemption(exemption.public_id)
    // `RN-CORE-96`: la misma consulta (mismo filtro y página), nunca mutar filas.
    await table.value?.refresh()
  } catch {
    revokeError.value = t('auth.common.unexpectedError')
  } finally {
    revokingId.value = null
  }
}
</script>

<template>
  <section class="flex flex-col gap-3">
    <div class="flex items-center justify-between">
      <h2 class="text-sm font-semibold">{{ t('auth.mfaAdmin.exemptions.title') }}</h2>
      <Button v-if="!grantOpen" type="button" size="sm" @click="openGrant">
        {{ t('auth.mfaAdmin.exemptions.grantAction') }}
      </Button>
    </div>

    <form
      v-if="grantOpen"
      class="border-border flex flex-col gap-3 rounded-lg border px-3 py-3"
      novalidate
      @submit.prevent="submitGrant"
    >
      <MfaUserPicker
        id="mfa-exemption-user"
        :selected="grantUser"
        @select="(user) => (grantUser = user)"
        @clear="grantUser = null"
      />

      <div class="flex flex-col gap-1.5">
        <Label for="mfa-exemption-reason">{{ t('auth.mfaAdmin.exemptions.reasonLabel') }}</Label>
        <textarea
          id="mfa-exemption-reason"
          v-model="grantReason"
          rows="3"
          minlength="10"
          required
          class="border-input dark:bg-input/30 rounded-lg border px-3 py-2 text-sm"
        ></textarea>
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="mfa-exemption-expires">{{ t('auth.mfaAdmin.exemptions.expiresLabel') }}</Label>
        <input
          id="mfa-exemption-expires"
          v-model="grantExpiresAt"
          type="date"
          required
          class="border-input dark:bg-input/30 w-fit rounded-lg border px-3 py-2 text-sm"
        />
        <p class="text-muted-foreground text-xs">
          {{ t('auth.mfaAdmin.exemptions.maxDaysHint', { days: MAX_EXEMPTION_DAYS }) }}
        </p>
      </div>

      <p class="text-muted-foreground text-xs">{{ t('auth.mfaAdmin.exemptions.reasonWarning') }}</p>

      <p
        v-for="message in grantErrors"
        :key="message"
        role="alert"
        class="text-destructive text-sm"
      >
        {{ message }}
      </p>
      <p v-if="grantConflict" role="alert" class="text-destructive text-sm">{{ grantConflict }}</p>

      <div class="flex gap-2">
        <Button type="submit" size="sm" :disabled="grantSubmitting || !grantUser">
          {{
            grantSubmitting
              ? t('auth.mfaAdmin.exemptions.granting')
              : t('auth.mfaAdmin.exemptions.confirmGrant')
          }}
        </Button>
        <Button type="button" variant="outline" size="sm" @click="cancelGrant">
          {{ t('auth.mfaAdmin.exemptions.cancel') }}
        </Button>
      </div>
    </form>

    <DataTable
      :key="tableKey"
      ref="table"
      table-id="auth.mfa_exemptions"
      :caption="t('auth.mfaAdmin.exemptions.tableCaption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchExemptions"
      :filters="filters"
      :empty-title="t('auth.mfaAdmin.exemptions.empty')"
      :card-heading-level="3"
    >
      <template #cell-state="{ row }">
        <Badge :variant="row.state === 'live' ? 'default' : 'secondary'">
          {{ t(`auth.mfaAdmin.exemptions.state.${row.state}`) }}
        </Badge>
      </template>
      <template #cell-actions="{ row }">
        <Button
          v-if="row.state === 'live'"
          type="button"
          variant="outline"
          size="sm"
          :disabled="revokingId === row.public_id"
          :aria-label="t('auth.mfaAdmin.exemptions.revokeActionFor', { name: fullName(row.user) })"
          @click="revoke(row)"
        >
          {{ t('auth.mfaAdmin.exemptions.revokeAction') }}
        </Button>
        <DataTableEmptyValue v-else />
      </template>
    </DataTable>

    <p v-if="revokeError" role="alert" class="text-destructive text-sm">{{ revokeError }}</p>

    <ConfirmDialog
      :open="confirmation.open.value"
      :request="confirmation.request.value"
      @confirm="confirmation.confirm"
      @cancel="confirmation.cancel"
    />
  </section>
</template>
