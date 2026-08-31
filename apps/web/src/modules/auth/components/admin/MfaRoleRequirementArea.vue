<script setup lang="ts">
/**
 * Área 2 de `/administracion/mfa` (funcional.md §D.9.1): conmutador de
 * `mfa_required` del rol elegido, **con vista previa del impacto antes
 * de guardar** — `GET /mfa-compliance?role=…&mfa_required=…` en modo
 * hipótesis (`preview: true`, no escribe nada, `CA-AUTH-136`) y
 * confirmación explícita antes de `PATCH /roles/{public_id}`
 * (`rol.actualizar`, de `REQ-CORE`, api.md §C.6).
 */
import { ref, watch } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { updateRoleMfaRequired } from '@/modules/core/api'
import { getMfaCompliance } from '../../api'
import { apiErrorStatus } from '../../composables/formErrors'
import type { AdminRoleOption, MfaComplianceSummary } from '../../types'

const props = defineProps<{ role: AdminRoleOption | null }>()
const emit = defineEmits<{ updated: [AdminRoleOption]; forbidden: [] }>()

const t = useT()

const preview = ref<MfaComplianceSummary | null>(null)
const previewError = ref<string | null>(null)
const loadingPreview = ref(false)
const confirming = ref(false)
const applying = ref(false)
const applyError = ref<string | null>(null)
const successMessage = ref<string | null>(null)

function resetState(): void {
  preview.value = null
  previewError.value = null
  confirming.value = false
  applyError.value = null
  successMessage.value = null
}

watch(
  () => props.role?.public_id,
  () => resetState(),
)

async function loadPreview(): Promise<void> {
  if (!props.role) {
    return
  }

  loadingPreview.value = true
  previewError.value = null
  successMessage.value = null

  try {
    preview.value = await getMfaCompliance({
      role: props.role.public_id,
      mfaRequired: !props.role.mfa_required,
    })
    confirming.value = true
  } catch (err) {
    if (apiErrorStatus(err) === 403) {
      previewError.value = t('auth.mfaAdmin.forbidden')
      emit('forbidden')
      return
    }
    previewError.value = t('auth.common.unexpectedError')
  } finally {
    loadingPreview.value = false
  }
}

function cancelPreview(): void {
  confirming.value = false
  preview.value = null
}

async function apply(): Promise<void> {
  if (!props.role) {
    return
  }

  applying.value = true
  applyError.value = null

  try {
    const updated = await updateRoleMfaRequired(props.role.public_id, !props.role.mfa_required)
    confirming.value = false
    preview.value = null
    successMessage.value = t('auth.mfaAdmin.roleRequirement.applied')
    emit('updated', updated)
  } catch (err) {
    if (apiErrorStatus(err) === 403) {
      applyError.value = t('auth.mfaAdmin.forbidden')
      emit('forbidden')
      return
    }
    applyError.value = t('auth.common.unexpectedError')
  } finally {
    applying.value = false
  }
}
</script>

<template>
  <section class="flex flex-col gap-3">
    <h2 class="text-sm font-semibold">{{ t('auth.mfaAdmin.roleRequirement.title') }}</h2>

    <p v-if="!role" class="text-muted-foreground text-sm">
      {{ t('auth.mfaAdmin.compliance.chooseRoleHint') }}
    </p>

    <template v-else>
      <p class="text-sm">
        {{
          role.mfa_required
            ? t('auth.mfaAdmin.roleRequirement.currentlyRequired', { role: role.name })
            : t('auth.mfaAdmin.roleRequirement.currentlyNotRequired', { role: role.name })
        }}
      </p>

      <p v-if="successMessage" role="status" class="text-sm">{{ successMessage }}</p>
      <p v-if="previewError || applyError" role="alert" class="text-destructive text-sm">
        {{ previewError || applyError }}
      </p>

      <Button
        v-if="!confirming"
        type="button"
        variant="outline"
        :disabled="loadingPreview"
        @click="loadPreview"
      >
        {{
          role.mfa_required
            ? t('auth.mfaAdmin.roleRequirement.previewDisable')
            : t('auth.mfaAdmin.roleRequirement.previewEnable')
        }}
      </Button>

      <div
        v-if="confirming && preview"
        class="border-border flex flex-col gap-2 rounded-lg border px-3 py-3 text-sm"
      >
        <!-- D.9 regla 2: la vista previa no escribe nada, y lo dice. -->
        <p class="text-muted-foreground text-xs">
          {{ t('auth.mfaAdmin.roleRequirement.previewNotice') }}
        </p>
        <p>
          {{
            role.mfa_required
              ? t('auth.mfaAdmin.roleRequirement.impactDisable', { count: preview.users_obligated })
              : t('auth.mfaAdmin.roleRequirement.impactEnable', { count: preview.users_obligated })
          }}
        </p>
        <div class="flex gap-2">
          <Button type="button" :disabled="applying" @click="apply">
            {{
              applying
                ? t('auth.mfaAdmin.roleRequirement.applying')
                : t('auth.mfaAdmin.roleRequirement.confirm')
            }}
          </Button>
          <Button type="button" variant="outline" :disabled="applying" @click="cancelPreview">
            {{ t('auth.mfaAdmin.roleRequirement.cancel') }}
          </Button>
        </div>
      </div>
    </template>
  </section>
</template>
