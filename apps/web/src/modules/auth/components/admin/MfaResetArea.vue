<script setup lang="ts">
/**
 * Área 3 de `/administracion/mfa` (funcional.md §D.9.1): restablecimiento
 * de MFA de un usuario, motivo obligatorio de 10 caracteres, aviso de que
 * se cerrarán todas sus sesiones y se le notificará (`POST /mfa-resets`,
 * permiso `mfa.eliminar`, api.md §C.5).
 */
import { ref, watch } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { createMfaReset } from '../../api'
import { apiErrorDetail, apiErrorStatus, fieldErrors } from '../../composables/formErrors'
import MfaUserPicker from './MfaUserPicker.vue'
import type { MfaComplianceUserSummary } from '../../types'

const props = defineProps<{ target: MfaComplianceUserSummary | null }>()
const emit = defineEmits<{ done: []; forbidden: [] }>()

const t = useT()

const selected = ref<MfaComplianceUserSummary | null>(props.target)
const reason = ref('')
const submitting = ref(false)
const reasonErrors = ref<string[]>([])
const forbiddenMessage = ref<string | null>(null)
const successMessage = ref<string | null>(null)

watch(
  () => props.target,
  (value) => {
    if (value) {
      selected.value = value
      successMessage.value = null
      forbiddenMessage.value = null
    }
  },
)

async function submit(): Promise<void> {
  if (!selected.value) {
    return
  }

  submitting.value = true
  reasonErrors.value = []
  forbiddenMessage.value = null
  successMessage.value = null

  try {
    await createMfaReset({ user: selected.value.public_id, reason: reason.value })
    successMessage.value = t('auth.mfaAdmin.reset.success', {
      name: `${selected.value.given_name} ${selected.value.family_name_1}`,
    })
    selected.value = null
    reason.value = ''
    emit('done')
  } catch (err) {
    const status = apiErrorStatus(err)

    if (status === 403) {
      // api.md §C.5: el 403 de autorrestablecimiento (RN-AUTH-67) trae un
      // `detail` distinguido y ya traducido por el servidor; el 403 llano
      // de `permission:` no lo trae, y entonces es "no tienes permiso"
      // (D.9 regla 1: se muestra tal cual, no se oculta).
      forbiddenMessage.value = apiErrorDetail(err) ?? t('auth.mfaAdmin.forbidden')
      emit('forbidden')
    } else if (status === 422) {
      reasonErrors.value = fieldErrors(err, 'reason')
      if (reasonErrors.value.length === 0) {
        reasonErrors.value = [t('auth.mfaAdmin.reset.reasonTooShort')]
      }
    } else if (status === 404) {
      forbiddenMessage.value = t('auth.mfaAdmin.reset.userNotFound')
    } else {
      forbiddenMessage.value = t('auth.common.unexpectedError')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="flex flex-col gap-3">
    <h2 class="text-sm font-semibold">{{ t('auth.mfaAdmin.reset.title') }}</h2>

    <MfaUserPicker
      id="mfa-reset-user"
      :selected="selected"
      @select="(user) => (selected = user)"
      @clear="selected = null"
    />

    <form v-if="selected" class="flex flex-col gap-3" novalidate @submit.prevent="submit">
      <div class="flex flex-col gap-1.5">
        <Label for="mfa-reset-reason">{{ t('auth.mfaAdmin.reset.reasonLabel') }}</Label>
        <textarea
          id="mfa-reset-reason"
          v-model="reason"
          rows="3"
          minlength="10"
          required
          class="border-input dark:bg-input/30 rounded-lg border px-3 py-2 text-sm"
        ></textarea>
        <p
          v-for="message in reasonErrors"
          :key="message"
          role="alert"
          class="text-destructive text-sm"
        >
          {{ message }}
        </p>
      </div>

      <!-- D.9 regla 3: el motivo queda registrado y quién puede leerlo, y no debe contener datos de salud. -->
      <p class="text-muted-foreground text-xs">{{ t('auth.mfaAdmin.reset.reasonWarning') }}</p>
      <p class="text-muted-foreground text-xs">{{ t('auth.mfaAdmin.reset.effectWarning') }}</p>

      <p v-if="forbiddenMessage" role="alert" class="text-destructive text-sm">
        {{ forbiddenMessage }}
      </p>
      <p v-if="successMessage" role="status" class="text-sm">{{ successMessage }}</p>

      <Button type="submit" :disabled="submitting" class="w-fit">
        {{ submitting ? t('auth.mfaAdmin.reset.submitting') : t('auth.mfaAdmin.reset.submit') }}
      </Button>
    </form>

    <p v-else-if="successMessage" role="status" class="text-sm">{{ successMessage }}</p>
  </section>
</template>
