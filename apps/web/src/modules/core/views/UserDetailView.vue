<script setup lang="ts">
/**
 * `/administracion/usuarios/:publicId` (`docs/modulos/REQ-CORE/funcional.md
 * §14.4.2`, `RN-CORE-61`/`-62`/`-64`/`-66`, `OPEN-CORE-43` = A, 1.9b). Ficha
 * de un usuario con todos los campos de `GET /users/{id}` y sus acciones.
 *
 * - **Permiso, nunca rol** (`RN-CORE-61`, `CA-CORE-210`): una acción se muestra
 *   si y solo si `/me.permissions` contiene el permiso de su *endpoint*. La
 *   interfaz **no** reproduce `RN-CORE-06` (no tocarse a uno mismo) ni
 *   `RN-CORE-07` (último administrador) con códigos de rol: el servidor
 *   responde `409` y se muestra su `detail`. Única excepción, por identidad y
 *   no por rol: si la ficha es la propia (`public_id` de `/me`), estado, baja
 *   y roles se muestran deshabilitados con su explicación (`CA-CORE-211`).
 * - **Sin peticiones a ciegas** (`RN-CORE-62`): `GET /users/{id}/roles` solo con
 *   `asignacion_rol.leer`; las opciones del editor de roles (`GET /roles`)
 *   solo con `rol.leer` y `asignacion_rol.crear`; `include_deleted` solo con
 *   `usuario.eliminar`.
 * - **Confirmación** (`RN-CORE-64`): dar de baja, desactivar, enviar la
 *   invitación y retirar roles piden confirmación antes de la petición.
 *   Restaurar y activar no (son reversibles y no dejan a nadie sin acceso).
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { localeFromDomain, useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useConfirm } from '@/components/useConfirm'
import { useDataTableFormatters } from '@/data-table'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import { resolveErrorState, type ShellErrorState } from '@/layouts/errorState'
import {
  deleteUser,
  getUser,
  issueInvitation,
  listRoles,
  listUserRoles,
  replaceUserRoles,
  restoreUser,
  updateUserStatus,
} from '../api'
import { problemDetail, problemRetryAfter, problemStatus } from '../composables/problem'
import { usePermissions } from '../composables/usePermissions'
import { takeFlash } from '../composables/useFlash'
import type { Locale, PublicId, Role, RoleSummary, User } from '../types'

const t = useT()
const route = useRoute()
const { can, me } = usePermissions()
const { formatDate, formatDateTime } = useDataTableFormatters()
const confirmation = useConfirm()

const publicId = computed(() => String(route.params.publicId))

const loading = ref(true)
const loadError = ref<ShellErrorState | null>(null)
const user = ref<User | null>(null)
const userRoles = ref<RoleSummary[]>([])
const allRoles = ref<Role[]>([])
const selectedRoleIds = ref<PublicId[]>([])

const busy = ref(false)
const actionError = ref<string | null>(null)
const rolesError = ref<string | null>(null)
const message = ref<string | null>(null)

const isDeleted = computed(() => user.value?.deleted_at != null)
const isSelf = computed(() => me.value !== null && me.value.public_id === publicId.value)

const canReadRoles = computed(() => can('asignacion_rol.leer'))
const canManageRoles = computed(
  () => can('asignacion_rol.crear') && can('rol.leer') && !isDeleted.value,
)

const rolesDescribedBy = computed(
  () =>
    [rolesError.value ? 'user-roles-error' : null, isSelf.value ? 'user-self-note' : null]
      .filter(Boolean)
      .join(' ') || undefined,
)

function translated(key: string, fallback: string): string {
  const label = t(key)

  return label === key ? fallback : label
}

const displayName = computed(() => {
  if (!user.value) {
    return ''
  }

  const person = user.value.person
  const surnames = [person.family_name_1, person.family_name_2].filter(Boolean).join(' ')

  return `${person.given_name} ${surnames}`.trim()
})

const statusText = computed(() => {
  if (!user.value) {
    return ''
  }

  return isDeleted.value
    ? t('core.users.status.deleted')
    : translated(`core.user.status.${user.value.status}`, user.value.status)
})

function localeName(locale: string): string {
  return translated(`core.locale.name.${localeFromDomain(locale as Locale)}`, locale)
}

function fail(err: unknown): void {
  if (problemStatus(err) === 429) {
    const seconds = problemRetryAfter(err)

    actionError.value =
      seconds !== null
        ? t('core.users.errors.tooManyRequestsWithSeconds', { seconds })
        : t('core.users.errors.tooManyRequests')

    return
  }

  actionError.value = problemDetail(err) ?? t('core.users.errors.unexpected')
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null

  try {
    user.value = await getUser(publicId.value, {
      // RN-CORE-62: el servidor exige `usuario.eliminar` para este parámetro.
      include_deleted: can('usuario.eliminar'),
    })
  } catch (err) {
    user.value = null
    loadError.value = resolveErrorState(err)
    loading.value = false

    return
  }

  try {
    if (canReadRoles.value) {
      userRoles.value = (await listUserRoles(publicId.value)).data
    } else {
      userRoles.value = user.value.roles
    }

    if (can('asignacion_rol.crear') && can('rol.leer')) {
      allRoles.value = (await listRoles({ per_page: 100 })).data
    }

    selectedRoleIds.value = userRoles.value.map((role) => role.public_id)
  } catch (err) {
    // Los roles son una sección de la ficha: su fallo no la tumba.
    fail(err)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  const flash = takeFlash()

  if (flash) {
    message.value = t(flash.key, flash.params ?? {})
  }

  void load()
})

async function run(action: () => Promise<void>): Promise<void> {
  busy.value = true
  actionError.value = null
  rolesError.value = null
  message.value = null

  try {
    await action()
  } catch (err) {
    fail(err)
  } finally {
    busy.value = false
  }
}

async function toggleStatus(): Promise<void> {
  const current = user.value

  if (!current || (current.status !== 'activo' && current.status !== 'inactivo')) {
    return
  }

  const next = current.status === 'activo' ? 'inactivo' : 'activo'

  if (next === 'inactivo') {
    const confirmed = await confirmation.ask({
      title: t('core.users.confirm.deactivate.title', { name: displayName.value }),
      description: t('core.users.confirm.deactivate.description'),
      confirmLabel: t('core.users.confirm.deactivate.confirm', { name: displayName.value }),
      destructive: true,
    })

    if (!confirmed) {
      return
    }
  }

  await run(async () => {
    user.value = await updateUserStatus(current.public_id, next)
    message.value = t(
      next === 'activo' ? 'core.users.flash.activated' : 'core.users.flash.deactivated',
    )
  })
}

async function softDelete(): Promise<void> {
  const current = user.value

  if (!current) {
    return
  }

  const confirmed = await confirmation.ask({
    title: t('core.users.confirm.delete.title', { name: displayName.value }),
    description: t('core.users.confirm.delete.description'),
    confirmLabel: t('core.users.confirm.delete.confirm', { name: displayName.value }),
    destructive: true,
  })

  if (!confirmed) {
    return
  }

  await run(async () => {
    await deleteUser(current.public_id)
    message.value = t('core.users.flash.deleted')
    await load()
  })
}

async function restore(): Promise<void> {
  const current = user.value

  if (!current) {
    return
  }

  await run(async () => {
    user.value = await restoreUser(current.public_id)
    // api.md §3: el usuario restaurado vuelve en `inactivo`.
    message.value = t('core.users.flash.restored')
  })
}

async function sendInvitation(): Promise<void> {
  const current = user.value

  if (!current) {
    return
  }

  const confirmed = await confirmation.ask({
    title: t('core.users.confirm.invite.title', { name: displayName.value }),
    description: t('core.users.confirm.invite.description', { email: current.email }),
    confirmLabel: t('core.users.confirm.invite.confirm', { name: displayName.value }),
  })

  if (!confirmed) {
    return
  }

  await run(async () => {
    const invitation = await issueInvitation(current.public_id)

    message.value = t('core.users.flash.invited', { date: formatDateTime(invitation.expires_at) })
  })
}

function toggleRole(roleId: PublicId, checked: boolean): void {
  selectedRoleIds.value = checked
    ? [...selectedRoleIds.value, roleId]
    : selectedRoleIds.value.filter((id) => id !== roleId)
}

async function saveRoles(): Promise<void> {
  const current = user.value

  if (!current) {
    return
  }

  const removed = userRoles.value.filter((role) => !selectedRoleIds.value.includes(role.public_id))

  if (removed.length > 0) {
    const confirmed = await confirmation.ask({
      title: t('core.users.confirm.removeRoles.title', { name: displayName.value }),
      description: t('core.users.confirm.removeRoles.description', {
        roles: removed.map((role) => role.name).join(', '),
      }),
      confirmLabel: t('core.users.confirm.removeRoles.confirm', { name: displayName.value }),
      destructive: true,
    })

    if (!confirmed) {
      return
    }
  }

  busy.value = true
  actionError.value = null
  rolesError.value = null
  message.value = null

  try {
    userRoles.value = (await replaceUserRoles(current.public_id, selectedRoleIds.value)).data
    selectedRoleIds.value = userRoles.value.map((role) => role.public_id)
    message.value = t('core.users.flash.rolesSaved')
  } catch (err) {
    // RPERM-013 (`403`) y `RN-CORE-06`/`07` (`409`): el `detail` del servidor, junto al campo.
    const status = problemStatus(err)

    if (status === 403 || status === 409) {
      rolesError.value = problemDetail(err) ?? t('core.users.errors.unexpected')
    } else {
      fail(err)
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="mx-auto flex max-w-4xl flex-col gap-6 px-4 py-6">
    <LoadingState v-if="loading" />

    <ErrorState v-else-if="loadError" :state="loadError" @retry="load" />

    <template v-else-if="user">
      <header class="flex flex-col gap-1">
        <h1 class="text-lg font-semibold">{{ displayName }}</h1>
        <p class="text-muted-foreground text-sm">{{ user.email }}</p>
      </header>

      <p
        v-if="message"
        role="status"
        class="border-border bg-muted rounded-lg border px-3 py-2 text-sm"
      >
        {{ message }}
      </p>
      <p v-if="actionError" role="alert" class="text-destructive text-sm">{{ actionError }}</p>

      <section class="flex flex-col gap-2" aria-labelledby="user-actions-title">
        <h2 id="user-actions-title" class="text-sm font-semibold">
          {{ t('core.users.detail.actions') }}
        </h2>

        <div class="flex flex-wrap gap-2">
          <Button v-if="can('usuario.actualizar') && !isDeleted" variant="outline" as-child>
            <RouterLink :to="{ name: 'core-user-edit', params: { publicId: user.public_id } }">
              {{ t('core.users.detail.edit') }}
            </RouterLink>
          </Button>

          <Button
            v-if="
              can('usuario.actualizar') &&
              !isDeleted &&
              (user.status === 'activo' || user.status === 'inactivo')
            "
            type="button"
            variant="outline"
            :disabled="busy || isSelf"
            :aria-describedby="isSelf ? 'user-self-note' : undefined"
            @click="toggleStatus"
          >
            {{
              user.status === 'activo'
                ? t('core.users.detail.deactivate')
                : t('core.users.detail.activate')
            }}
          </Button>

          <Button
            v-if="can('invitacion.crear') && !isDeleted && user.status === 'pendiente'"
            type="button"
            variant="outline"
            :disabled="busy"
            @click="sendInvitation"
          >
            {{ t('core.users.detail.invite') }}
          </Button>

          <!--
            1.9d, `OPEN-CORE-33` = C (`RN-CORE-76`): navegación a la auditoría
            filtrada por este usuario. Permiso del *endpoint* destino; no es una
            petición, solo un enlace, así que no hay llamada a ciegas.
          -->
          <Button v-if="can('auditoria.leer')" variant="outline" as-child>
            <RouterLink
              :to="{ name: 'core-audit', query: { actor_id: user.public_id } }"
              :aria-label="t('core.users.detail.viewActivityFor', { name: displayName })"
            >
              {{ t('core.users.detail.viewActivity') }}
            </RouterLink>
          </Button>

          <!--
            1.5b, `RN-PERM-41`/`RN-CORE-61`: permisos efectivos de esta persona, con
            procedencia. Solo con `permiso_efectivo.leer`; es un enlace, no una petición.
          -->
          <Button v-if="can('permiso_efectivo.leer') && !isDeleted" variant="outline" as-child>
            <RouterLink
              :to="{
                name: 'core-user-effective-permissions',
                params: { publicId: user.public_id },
              }"
              :aria-label="
                t('core.users.detail.viewEffectivePermissionsFor', { name: displayName })
              "
            >
              {{ t('core.users.detail.viewEffectivePermissions') }}
            </RouterLink>
          </Button>

          <Button
            v-if="can('usuario.eliminar') && !isDeleted"
            type="button"
            variant="destructive"
            :disabled="busy || isSelf"
            :aria-describedby="isSelf ? 'user-self-note' : undefined"
            @click="softDelete"
          >
            {{ t('core.users.detail.delete') }}
          </Button>

          <Button
            v-if="can('usuario.eliminar') && isDeleted"
            type="button"
            variant="outline"
            :disabled="busy"
            @click="restore"
          >
            {{ t('core.users.detail.restore') }}
          </Button>
        </div>

        <p v-if="isSelf" id="user-self-note" class="text-muted-foreground text-xs">
          {{ t('core.users.detail.selfNote') }}
        </p>
      </section>

      <section class="flex flex-col gap-2" aria-labelledby="user-data-title">
        <h2 id="user-data-title" class="text-sm font-semibold">
          {{ t('core.users.detail.data') }}
        </h2>
        <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-muted-foreground text-xs">{{ t('core.users.detail.status') }}</dt>
            <dd>{{ statusText }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">{{ t('core.users.detail.email') }}</dt>
            <dd>{{ user.email }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">{{ t('core.users.detail.documentType') }}</dt>
            <dd>
              {{
                user.person.document_type
                  ? translated(
                      `core.person.documentType.${user.person.document_type}`,
                      user.person.document_type,
                    )
                  : t('dataTable.emptyValue')
              }}
            </dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">
              {{ t('core.users.detail.documentNumber') }}
            </dt>
            <dd>{{ user.person.document_number ?? t('dataTable.emptyValue') }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">{{ t('core.users.detail.birthDate') }}</dt>
            <dd>
              {{
                user.person.birth_date
                  ? formatDate(user.person.birth_date)
                  : t('dataTable.emptyValue')
              }}
            </dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">{{ t('core.users.detail.locale') }}</dt>
            <dd>{{ localeName(user.person.locale) }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">
              {{ t('core.users.detail.contactEmail') }}
            </dt>
            <dd>{{ user.person.contact_email ?? t('dataTable.emptyValue') }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">
              {{ t('core.users.detail.contactPhone') }}
            </dt>
            <dd>{{ user.person.contact_phone ?? t('dataTable.emptyValue') }}</dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">
              {{ t('core.users.detail.emailVerifiedAt') }}
            </dt>
            <dd>
              {{
                user.email_verified_at
                  ? formatDateTime(user.email_verified_at)
                  : t('dataTable.emptyValue')
              }}
            </dd>
          </div>
          <div>
            <dt class="text-muted-foreground text-xs">{{ t('core.users.detail.createdAt') }}</dt>
            <dd>{{ formatDateTime(user.created_at) }}</dd>
          </div>
          <div v-if="user.deleted_at">
            <dt class="text-muted-foreground text-xs">{{ t('core.users.detail.deletedAt') }}</dt>
            <dd>{{ formatDateTime(user.deleted_at) }}</dd>
          </div>
        </dl>
      </section>

      <section
        v-if="canReadRoles || canManageRoles"
        class="flex flex-col gap-2"
        aria-labelledby="user-roles-title"
      >
        <h2 id="user-roles-title" class="text-sm font-semibold">
          {{ t('core.users.detail.roles') }}
        </h2>

        <p v-if="userRoles.length === 0" class="text-muted-foreground text-sm">
          {{ t('core.users.detail.noRoles') }}
        </p>
        <ul v-else class="flex flex-wrap gap-2 text-sm" data-slot="user-roles">
          <li
            v-for="role in userRoles"
            :key="role.public_id"
            class="bg-secondary text-secondary-foreground rounded-md px-2 py-0.5"
          >
            {{ role.name }}
          </li>
        </ul>

        <form
          v-if="canManageRoles"
          class="flex flex-col gap-2"
          novalidate
          @submit.prevent="saveRoles"
        >
          <fieldset
            class="flex flex-col gap-1"
            :disabled="busy || isSelf"
            :aria-describedby="rolesDescribedBy"
          >
            <legend class="text-muted-foreground mb-1 text-xs">
              {{ t('core.users.detail.manageRoles') }}
            </legend>
            <label
              v-for="role in allRoles"
              :key="role.public_id"
              class="flex min-h-8 items-center gap-2 text-sm [@media(any-pointer:coarse)]:min-h-11"
            >
              <input
                type="checkbox"
                class="accent-primary-on-background size-4"
                :checked="selectedRoleIds.includes(role.public_id)"
                @change="
                  (event: Event) =>
                    toggleRole(role.public_id, (event.target as HTMLInputElement).checked)
                "
              />
              {{ role.name }}
            </label>
          </fieldset>
          <p v-if="rolesError" id="user-roles-error" role="alert" class="text-destructive text-sm">
            {{ rolesError }}
          </p>
          <div>
            <Button type="submit" variant="outline" :disabled="busy || isSelf">
              {{ t('core.users.detail.saveRoles') }}
            </Button>
          </div>
        </form>
      </section>
    </template>

    <ConfirmDialog
      :open="confirmation.open.value"
      :request="confirmation.request.value"
      @confirm="confirmation.confirm"
      @cancel="confirmation.cancel"
    />
  </div>
</template>
