<script setup lang="ts">
/**
 * `/administracion/mfa` (funcional.md §D.1.3, §D.9.1) — pieza 3 de 1.3b.
 * Pantalla mínima, autónoma (sin `AppLayout` ni design system, igual que
 * las de 1.2/1.2b/1.3), con sesión y permiso. **No aporta ni un endpoint
 * ni un permiso nuevo** (permisos.md §D.6.3, api.md §D.5.1): las cuatro
 * áreas consumen los siete permisos/endpoints que ya existen tras la
 * pieza 2. Sin editor de roles, sin matriz de permisos — eso es `1.5`
 * (`§D.1.2`).
 *
 * La SPA no es control de acceso (`INV-002`, `permisos.md §D.6.3` regla
 * 1): la ruta no comprueba ningún rol en el cliente — si el usuario no
 * tiene el permiso de un área, el servidor responde `403` y esa área lo
 * muestra tal cual, sin ocultarlo ni redirigir al login (`CA-AUTH-176`).
 * Solo la ausencia de sesión (`401`) al cargar el selector de roles
 * redirige a `/entrar`, igual que el resto de pantallas del módulo.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useT } from '@/i18n'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Label } from '@/components/ui/label'
import { listRoles } from '@/modules/core/api'
import { apiErrorStatus } from '../composables/formErrors'
import MfaComplianceArea from '../components/admin/MfaComplianceArea.vue'
import MfaRoleRequirementArea from '../components/admin/MfaRoleRequirementArea.vue'
import MfaResetArea from '../components/admin/MfaResetArea.vue'
import MfaExemptionsArea from '../components/admin/MfaExemptionsArea.vue'
import type { AdminRoleOption, MfaComplianceUserSummary } from '../types'

const t = useT()
const router = useRouter()

const loadingRoles = ref(true)
const rolesError = ref<string | null>(null)
const roles = ref<AdminRoleOption[]>([])
const selectedRoleId = ref<string | null>(null)
const resetTarget = ref<MfaComplianceUserSummary | null>(null)

const selectedRole = computed(
  () => roles.value.find((role) => role.public_id === selectedRoleId.value) ?? null,
)

onMounted(async () => {
  try {
    const page = await listRoles({ per_page: 100 })
    roles.value = page.data
  } catch (err) {
    if (apiErrorStatus(err) === 401) {
      await router.push({ name: 'login' })
      return
    }
    rolesError.value = t('auth.common.unexpectedError')
  } finally {
    loadingRoles.value = false
  }
})

function onRoleUpdated(updated: AdminRoleOption): void {
  const index = roles.value.findIndex((role) => role.public_id === updated.public_id)
  if (index !== -1) {
    roles.value[index] = updated
  }
}

function onResetUser(user: MfaComplianceUserSummary): void {
  resetTarget.value = user
  document.getElementById('mfa-admin-reset-area')?.scrollIntoView({ behavior: 'smooth' })
}
</script>

<template>
  <div class="mx-auto flex max-w-5xl flex-col gap-8 px-4 py-10">
    <h1 class="text-lg font-semibold">{{ t('auth.mfaAdmin.title') }}</h1>

    <p v-if="rolesError" role="alert" class="text-destructive text-sm">{{ rolesError }}</p>
    <p v-if="loadingRoles" class="text-muted-foreground text-sm">
      {{ t('auth.mfaAdmin.loadingRoles') }}
    </p>

    <div v-else class="flex flex-col gap-1.5">
      <Label for="mfa-admin-role">{{ t('auth.mfaAdmin.roleLabel') }}</Label>
      <Select v-model="selectedRoleId">
        <SelectTrigger id="mfa-admin-role" class="w-72">
          <SelectValue :placeholder="t('auth.mfaAdmin.rolePlaceholder')" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem v-for="role in roles" :key="role.public_id" :value="role.public_id">
            {{ role.name }}
          </SelectItem>
        </SelectContent>
      </Select>
    </div>

    <MfaComplianceArea :role="selectedRole" @reset-user="onResetUser" />

    <MfaRoleRequirementArea :role="selectedRole" @updated="onRoleUpdated" />

    <div id="mfa-admin-reset-area">
      <MfaResetArea :target="resetTarget" @done="resetTarget = null" />
    </div>

    <MfaExemptionsArea />
  </div>
</template>
