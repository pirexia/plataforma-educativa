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
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { createMfaExemption, listMfaExemptions, revokeMfaExemption } from '../../api'
import { apiErrorDetail, apiErrorStatus, fieldErrors } from '../../composables/formErrors'
import MfaUserPicker from './MfaUserPicker.vue'
import type { MfaComplianceUserSummary, MfaExemption, MfaExemptionState } from '../../types'

const MAX_EXEMPTION_DAYS = 90

const t = useT()
const { locale } = useI18n()

const dateTimeFormatter = computed(
  () => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }),
)

function formatDateTime(value: string): string {
  return dateTimeFormatter.value.format(new Date(value))
}

function fullName(person: { given_name: string; family_name_1: string }): string {
  return `${person.given_name} ${person.family_name_1}`
}

// -- Listado ----------------------------------------------------------------

const STATE_FILTERS: MfaExemptionState[] = ['live', 'expired', 'revoked']
const stateFilter = ref<MfaExemptionState | null>('live')
const exemptions = ref<MfaExemption[]>([])
const page = ref(1)
const lastPage = ref(1)
const loadingList = ref(false)
const listError = ref<string | null>(null)

async function loadList(): Promise<void> {
  loadingList.value = true
  listError.value = null

  try {
    const result = await listMfaExemptions({
      state: stateFilter.value ? [stateFilter.value] : undefined,
      page: page.value,
    })
    exemptions.value = result.data
    lastPage.value = result.meta.last_page
  } catch (err) {
    if (apiErrorStatus(err) === 403) {
      listError.value = t('auth.mfaAdmin.forbidden')
      return
    }
    listError.value = t('auth.common.unexpectedError')
  } finally {
    loadingList.value = false
  }
}

function setFilter(value: MfaExemptionState | null): void {
  stateFilter.value = value
  page.value = 1
  void loadList()
}

function goToPage(next: number): void {
  if (next < 1 || next > lastPage.value) {
    return
  }
  page.value = next
  void loadList()
}

void loadList()

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
    page.value = 1
    stateFilter.value = 'live'
    await loadList()
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
const revokeSubmitting = ref(false)
const revokeError = ref<string | null>(null)

function askRevoke(publicId: string): void {
  revokingId.value = publicId
  revokeError.value = null
}

function cancelRevoke(): void {
  revokingId.value = null
}

async function confirmRevoke(): Promise<void> {
  if (!revokingId.value) {
    return
  }

  revokeSubmitting.value = true
  revokeError.value = null

  try {
    await revokeMfaExemption(revokingId.value)
    revokingId.value = null
    await loadList()
  } catch {
    revokeError.value = t('auth.common.unexpectedError')
  } finally {
    revokeSubmitting.value = false
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

    <fieldset class="flex flex-wrap gap-3">
      <legend class="mb-1 text-xs font-medium">
        {{ t('auth.mfaAdmin.exemptions.filterLegend') }}
      </legend>
      <label class="flex items-center gap-1.5 text-sm">
        <input
          type="radio"
          name="exemption-state"
          :checked="stateFilter === null"
          @change="setFilter(null)"
        />
        {{ t('auth.mfaAdmin.exemptions.filterAll') }}
      </label>
      <label v-for="value in STATE_FILTERS" :key="value" class="flex items-center gap-1.5 text-sm">
        <input
          type="radio"
          name="exemption-state"
          :checked="stateFilter === value"
          @change="setFilter(value)"
        />
        {{ t(`auth.mfaAdmin.exemptions.state.${value}`) }}
      </label>
    </fieldset>

    <p v-if="listError" role="alert" class="text-destructive text-sm">{{ listError }}</p>
    <p v-if="loadingList" class="text-muted-foreground text-sm">
      {{ t('auth.mfaAdmin.exemptions.loading') }}
    </p>

    <Table v-else>
      <TableHeader>
        <TableRow>
          <TableHead>{{ t('auth.mfaAdmin.exemptions.columnUser') }}</TableHead>
          <TableHead>{{ t('auth.mfaAdmin.exemptions.columnState') }}</TableHead>
          <TableHead>{{ t('auth.mfaAdmin.exemptions.columnReason') }}</TableHead>
          <TableHead>{{ t('auth.mfaAdmin.exemptions.columnExpiresAt') }}</TableHead>
          <TableHead>{{ t('auth.mfaAdmin.exemptions.columnGrantedBy') }}</TableHead>
          <TableHead>{{ t('auth.mfaAdmin.exemptions.columnActions') }}</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableRow v-if="exemptions.length === 0">
          <TableCell colspan="6" class="text-muted-foreground">
            {{ t('auth.mfaAdmin.exemptions.empty') }}
          </TableCell>
        </TableRow>
        <TableRow v-for="exemption in exemptions" :key="exemption.public_id">
          <TableCell>{{ fullName(exemption.user) }} · {{ exemption.user.email }}</TableCell>
          <TableCell>
            <Badge :variant="exemption.state === 'live' ? 'default' : 'secondary'">
              {{ t(`auth.mfaAdmin.exemptions.state.${exemption.state}`) }}
            </Badge>
          </TableCell>
          <TableCell class="max-w-xs whitespace-normal">{{ exemption.reason }}</TableCell>
          <TableCell>{{ formatDateTime(exemption.expires_at) }}</TableCell>
          <TableCell>{{ fullName(exemption.granted_by) }}</TableCell>
          <TableCell>
            <template v-if="exemption.state === 'live'">
              <Button
                v-if="revokingId !== exemption.public_id"
                type="button"
                variant="outline"
                size="sm"
                @click="askRevoke(exemption.public_id)"
              >
                {{ t('auth.mfaAdmin.exemptions.revokeAction') }}
              </Button>
              <div v-else class="flex flex-col gap-1">
                <p role="alert" class="text-xs">
                  {{ t('auth.mfaAdmin.exemptions.confirmRevoke') }}
                </p>
                <div class="flex gap-2">
                  <Button
                    type="button"
                    variant="destructive"
                    size="sm"
                    :disabled="revokeSubmitting"
                    @click="confirmRevoke"
                  >
                    {{ t('auth.mfaAdmin.exemptions.confirmYes') }}
                  </Button>
                  <Button type="button" variant="outline" size="sm" @click="cancelRevoke">
                    {{ t('auth.mfaAdmin.exemptions.cancel') }}
                  </Button>
                </div>
              </div>
            </template>
            <span v-else class="text-muted-foreground">—</span>
          </TableCell>
        </TableRow>
      </TableBody>
    </Table>

    <p v-if="revokeError" role="alert" class="text-destructive text-sm">{{ revokeError }}</p>

    <div v-if="lastPage > 1" class="flex items-center gap-2 text-sm">
      <Button
        type="button"
        variant="outline"
        size="sm"
        :disabled="page <= 1"
        @click="goToPage(page - 1)"
      >
        {{ t('auth.mfaAdmin.compliance.previousPage') }}
      </Button>
      <span>{{ t('auth.mfaAdmin.compliance.pageIndicator', { page, lastPage }) }}</span>
      <Button
        type="button"
        variant="outline"
        size="sm"
        :disabled="page >= lastPage"
        @click="goToPage(page + 1)"
      >
        {{ t('auth.mfaAdmin.compliance.nextPage') }}
      </Button>
    </div>
  </section>
</template>
