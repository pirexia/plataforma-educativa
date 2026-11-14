<script setup lang="ts">
/**
 * `/administracion/roles/nuevo`, `/administracion/roles/:publicId/clonar` y
 * `/administracion/roles/:publicId/editar` (`docs/modulos/REQ-PERM/funcional.md
 * §20.6`/`§20.7`, `RN-PERM-29`/`-30`/`-31`, `RPERM-005`/`-006`/`-014`). Un solo
 * formulario con tres modos: alta (`POST /roles`), clonación (`POST /roles` con
 * `clone_from`) y edición de datos (`PATCH /roles/{id}`).
 *
 * - **Validación de cliente = comodidad** (`INV-010`): el servidor decide. Un
 *   `422` pinta cada `errors.<campo>[].message` (ya traducido, `ADR-038 §6.3`)
 *   bajo su campo con `aria-invalid="true"` y `aria-describedby`, y el foco va al
 *   primer campo con error.
 * - **Alta**: `name`, `code` (propuesto a partir del nombre, editable mientras no
 *   se haya guardado), `mfa_required` y `special_data_access`. **Nunca** se envían
 *   `is_system`, `name_key` ni concesiones: tras `201`, el editor de concesiones
 *   (`OPEN-PERM-16` = A).
 * - **Clonación**: cuerpo exacto `{clone_from, code, name}`; copia concesiones y
 *   `mfa_required`, no los titulares, y el rol nuevo queda desligado (`ADR-044 §4.6`).
 * - **Edición**: `PATCH` con **solo las claves modificadas** (`ADR-038 §9.2`);
 *   `code` se muestra y nunca se envía; el nombre de un rol del sistema no se edita;
 *   `mfa_required` es solo lectura con enlace a `/administracion/mfa` (`OPEN-PERM-11`).
 * - **`special_data_access`** (`RN-PERM-31`): el control solo existe con
 *   `rol_datos_especiales.actualizar`; con `rol.leer` se **deriva** si el solicitante
 *   posee el atributo cruzando `/me.roles[].public_id` con `GET /roles`, y sin
 *   posesión se pinta deshabilitado con su explicación. Es comodidad: decide el
 *   servidor (`403` con su `detail`).
 * - **Permiso, nunca código de rol** (`RN-PERM-46`).
 */
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useConfirm } from '@/components/useConfirm'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import { resolveErrorState, type ShellErrorState } from '@/layouts/errorState'
import {
  createRole,
  getRole,
  listRoles,
  updateRole,
  type CloneRolePayload,
  type CreateRolePayload,
  type UpdateRolePayload,
} from '../api'
import FormFieldError from '../components/FormFieldError.vue'
import {
  problemDetail,
  problemErrorEntryWithCode,
  problemFieldErrors,
  problemRetryAfter,
  problemStatus,
} from '../composables/problem'
import { setFlash } from '../composables/useFlash'
import { usePermissions } from '../composables/usePermissions'
import { usePermissionVocabulary } from '../permissionVocabulary'
import type { PublicId, Role } from '../types'

type Mode = 'new' | 'clone' | 'edit'
type FieldName = 'name' | 'code' | 'special'
type Possession = 'yes' | 'no' | 'unknown'

/** `api.md §3.1`: formato del código; aquí solo como comodidad (`INV-010`). */
const CODE_FORMAT = /^[a-z][a-z0-9_]{2,63}$/

const t = useT()
const route = useRoute()
const router = useRouter()
const { can, me } = usePermissions()
const vocabulary = usePermissionVocabulary()
const confirmation = useConfirm()

const mode = computed<Mode>(() =>
  route.name === 'core-role-clone' ? 'clone' : route.name === 'core-role-edit' ? 'edit' : 'new',
)
const routeRoleId = computed<PublicId | null>(() =>
  route.params.publicId ? String(route.params.publicId) : null,
)

const values = reactive({ name: '', code: '', mfaRequired: false, specialData: false })
let initial = { name: '', specialData: false }
const codeTouched = ref(false)

const loading = ref(false)
const loadError = ref<ShellErrorState | null>(null)
/** El rol de origen (clonación) o el rol que se edita. */
const role = ref<Role | null>(null)
const submitting = ref(false)

const fieldErrors = ref<Partial<Record<FieldName, string[]>>>({})
const generalError = ref<string | null>(null)
const grantError = ref<{ detail: string | null; permission: string; scope: string } | null>(null)

const possession = ref<Possession>('unknown')

// `rol.leer` hace falta para cargar el rol de origen o el que se edita (`RN-PERM-25`/`-26`).
const missingRead = computed(() => mode.value !== 'new' && !can('rol.leer'))
const showSpecial = computed(() => mode.value !== 'clone' && can('rol_datos_especiales.actualizar'))
const specialDisabled = computed(() => possession.value === 'no')

const isSystem = computed(() => mode.value === 'edit' && role.value?.is_system === true)

const submitKey = computed(() => `core.roles.form.submit.${mode.value}`)
const titleKey = computed(
  () =>
    ({
      new: 'core.roles.form.titleNew',
      clone: 'core.roles.form.titleClone',
      edit: 'core.roles.form.titleEdit',
    })[mode.value],
)

const hasMfaAdminRoute = computed(() => router.hasRoute('mfa-administration'))

// --- Código propuesto a partir del nombre (RN-PERM-29) -----------------------

/** Minúsculas, sin tildes, espacios a `_`, recortado a 64. */
function proposeCode(name: string): string {
  return name
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 64)
}

watch(
  () => values.name,
  (name) => {
    if (mode.value !== 'edit' && !codeTouched.value) {
      values.code = proposeCode(name)
    }
  },
)

// --- Posesión de `special_data_access` (RN-PERM-31) --------------------------

async function derivePossession(): Promise<void> {
  if (!showSpecial.value) {
    return
  }

  // Sin `rol.leer` no se pide `GET /roles` (RN-CORE-62): el control se habilita y decide el servidor.
  if (!can('rol.leer')) {
    possession.value = 'unknown'

    return
  }

  const mine = new Set((me.value?.roles ?? []).map((candidate) => candidate.public_id))

  if (mine.size === 0) {
    possession.value = 'no'

    return
  }

  try {
    let found = 0
    let page = 1

    for (;;) {
      const response = await listRoles({ page, per_page: 100 })

      for (const candidate of response.data) {
        if (!mine.has(candidate.public_id)) {
          continue
        }

        found += 1

        if (candidate.special_data_access) {
          possession.value = 'yes'

          return
        }
      }

      if (found >= mine.size) {
        possession.value = 'no'

        return
      }

      if (page >= response.meta.last_page) {
        // Roles propios que no aparecen: no se puede afirmar nada; decide el servidor.
        possession.value = 'unknown'

        return
      }

      page += 1
    }
  } catch {
    possession.value = 'unknown'
  }
}

// --- Carga ------------------------------------------------------------------

async function load(): Promise<void> {
  if (mode.value === 'new' || routeRoleId.value === null || missingRead.value) {
    return
  }

  loading.value = true
  loadError.value = null

  try {
    const loaded = await getRole(routeRoleId.value)

    role.value = loaded

    if (mode.value === 'edit') {
      values.name = loaded.name
      values.specialData = loaded.special_data_access
      initial = { name: loaded.name, specialData: loaded.special_data_access }
    }
  } catch (err) {
    loadError.value = resolveErrorState(err)
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  void derivePossession()
  await load()
})

// --- Errores ------------------------------------------------------------------

const errorSummary = computed(() => {
  const lines: string[] = []

  for (const [field, messages] of Object.entries(fieldErrors.value)) {
    // El error de `special_data_access` ya se anuncia junto a su control (`role="alert"`): no se duplica aquí.
    if (field !== 'special') {
      lines.push(...(messages ?? []))
    }
  }

  if (generalError.value) {
    lines.push(generalError.value)
  }

  return lines
})

function describedBy(name: FieldName, extra: string[] = []): string | undefined {
  const ids = [
    ...extra,
    (fieldErrors.value[name]?.length ?? 0) > 0 ? `role-form-${name}-error` : null,
  ].filter(Boolean)

  return ids.length > 0 ? ids.join(' ') : undefined
}

function invalid(name: FieldName): true | undefined {
  return (fieldErrors.value[name]?.length ?? 0) > 0 ? true : undefined
}

async function focusFirstError(): Promise<void> {
  await nextTick()

  const order: FieldName[] = ['name', 'code', 'special']
  const first = order.find((name) => (fieldErrors.value[name]?.length ?? 0) > 0)

  if (first) {
    document.getElementById(`role-form-${first}`)?.focus()

    return
  }

  document.getElementById('role-form-summary')?.focus()
}

function formField(serverField: string): FieldName | null {
  if (serverField === 'name' || serverField === 'code') {
    return serverField
  }

  // En la clonación no hay control de `special_data_access`: su error va al resumen, no a un campo que no existe.
  return serverField === 'special_data_access' && showSpecial.value ? 'special' : null
}

async function showError(err: unknown): Promise<void> {
  const status = problemStatus(err)

  fieldErrors.value = {}
  generalError.value = null
  grantError.value = null

  if (status === 422) {
    const next: Partial<Record<FieldName, string[]>> = {}
    const general: string[] = []

    for (const [serverField, messages] of Object.entries(problemFieldErrors(err))) {
      const field = formField(serverField)

      if (field) {
        next[field] = [...(next[field] ?? []), ...messages]
      } else {
        general.push(...messages)
      }
    }

    fieldErrors.value = next
    generalError.value =
      general.length > 0
        ? general.join(' ')
        : Object.keys(next).length === 0
          ? t('core.roles.errors.unexpected')
          : null

    await focusFirstError()

    return
  }

  if (status === 403) {
    // RPERM-013 (`errors.grant[0]`, api.md §9.2.1): el permiso y el ámbito con sus etiquetas.
    const entry = problemErrorEntryWithCode(
      err,
      'grant',
      'core.authorization.cannot_grant_unheld_permission',
    )

    if (entry) {
      grantError.value = {
        detail: problemDetail(err),
        permission: String(entry.params?.code ?? ''),
        scope: String(entry.params?.scope ?? ''),
      }
      generalError.value = null
      await focusFirstError()

      return
    }

    if (showSpecial.value) {
      // `special_data_access_not_held`: el `detail` del servidor, junto al control.
      fieldErrors.value = { special: [problemDetail(err) ?? t('core.roles.errors.unexpected')] }
      await focusFirstError()

      return
    }
  }

  if (status === 429) {
    const seconds = problemRetryAfter(err)

    generalError.value =
      seconds !== null
        ? t('core.roles.errors.tooManyRequestsWithSeconds', { seconds })
        : t('core.roles.errors.tooManyRequests')

    return
  }

  generalError.value = problemDetail(err) ?? t('core.roles.errors.unexpected')
}

// --- Envío --------------------------------------------------------------------

/** Tras crear el rol: al editor de concesiones si se puede usar; si no, a la ficha o al listado. */
function afterCreateRoute(publicId: PublicId) {
  if (can('rol.actualizar')) {
    return { name: 'core-role-permissions', params: { publicId } }
  }

  return can('rol.leer')
    ? { name: 'core-role-detail', params: { publicId } }
    : { name: 'core-roles' }
}

async function submit(): Promise<void> {
  fieldErrors.value = {}
  generalError.value = null
  grantError.value = null

  if (mode.value === 'edit') {
    await submitEdit()

    return
  }

  const name = values.name.trim()
  const code = values.code.trim()

  // Formato del código: solo comodidad (`INV-010`); el servidor decide.
  if (!CODE_FORMAT.test(code)) {
    fieldErrors.value = { code: [t('core.roles.form.errors.codeFormat')] }
    await focusFirstError()

    return
  }

  submitting.value = true

  try {
    if (mode.value === 'clone' && routeRoleId.value) {
      const payload: CloneRolePayload = { clone_from: routeRoleId.value, code, name }
      const created = await createRole(payload)

      setFlash({
        key: 'core.roles.flash.cloned',
        params: { name: created.name, source: role.value?.name ?? '' },
      })
      await router.push(
        can('rol.leer')
          ? { name: 'core-role-detail', params: { publicId: created.public_id } }
          : { name: 'core-roles' },
      )

      return
    }

    const payload: CreateRolePayload = { code, name }

    if (values.mfaRequired) {
      payload.mfa_required = true
    }

    if (showSpecial.value && values.specialData) {
      payload.special_data_access = true
    }

    const created = await createRole(payload)

    setFlash({ key: 'core.roles.flash.created', params: { name: created.name } })
    await router.push(afterCreateRoute(created.public_id))
  } catch (err) {
    await showError(err)
  } finally {
    submitting.value = false
  }
}

/** `ADR-038 §9.2`: solo las claves modificadas; nunca `code` ni `permissions`. */
function updatePayload(): UpdateRolePayload | null {
  const payload: UpdateRolePayload = {}

  if (!isSystem.value && values.name.trim() !== initial.name) {
    payload.name = values.name.trim()
  }

  if (showSpecial.value && values.specialData !== initial.specialData) {
    payload.special_data_access = values.specialData
  }

  return Object.keys(payload).length > 0 ? payload : null
}

async function submitEdit(): Promise<void> {
  const current = role.value

  if (!current) {
    return
  }

  const payload = updatePayload()

  if (payload === null) {
    await router.push({ name: 'core-role-detail', params: { publicId: current.public_id } })

    return
  }

  // RN-CORE-64: activar o desactivar el acceso a datos especiales cambia lo que ven los titulares.
  if (payload.special_data_access !== undefined) {
    const count = current.users_count
    const activate = payload.special_data_access
    const base = activate ? 'activate' : 'deactivate'
    const confirmed = await confirmation.ask({
      title: t(`core.roles.form.confirmSpecial.${base}Title`, { name: current.name }),
      description:
        count === undefined
          ? t(`core.roles.form.confirmSpecial.${base}DescriptionUnknown`)
          : t(`core.roles.form.confirmSpecial.${base}Description`, { count }),
      confirmLabel: t(`core.roles.form.confirmSpecial.${base}Confirm`, { name: current.name }),
      destructive: !activate,
    })

    if (!confirmed) {
      return
    }
  }

  submitting.value = true

  try {
    await updateRole(current.public_id, payload)
    setFlash({ key: 'core.roles.flash.updated' })
    await router.push({ name: 'core-role-detail', params: { publicId: current.public_id } })
  } catch (err) {
    await showError(err)
  } finally {
    submitting.value = false
  }
}

function cancel(): void {
  void router.push(
    mode.value === 'new' || !routeRoleId.value
      ? { name: 'core-roles' }
      : { name: 'core-role-detail', params: { publicId: routeRoleId.value } },
  )
}
</script>

<template>
  <div class="mx-auto flex max-w-2xl flex-col gap-4 px-4 py-6">
    <h1 class="text-lg font-semibold">{{ t(titleKey) }}</h1>

    <p v-if="missingRead" class="border-border bg-muted rounded-lg border px-3 py-2 text-sm">
      {{ t('core.roles.form.missing', { permission: 'rol.leer' }) }}
    </p>

    <LoadingState v-else-if="loading" />
    <ErrorState v-else-if="loadError" :state="loadError" @retry="load" />

    <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <p class="text-muted-foreground text-xs">{{ t('core.roles.form.requiredHint') }}</p>

      <div
        v-if="errorSummary.length > 0 || grantError"
        id="role-form-summary"
        role="alert"
        tabindex="-1"
        class="border-destructive text-destructive rounded-lg border px-3 py-2 text-sm"
      >
        <p class="font-medium">{{ t('core.roles.form.errorSummary') }}</p>
        <ul class="list-disc pl-5">
          <li v-for="line in errorSummary" :key="line">{{ line }}</li>
        </ul>
        <div v-if="grantError" class="flex flex-col gap-1">
          <p v-if="grantError.detail">{{ grantError.detail }}</p>
          <p>
            {{
              t('core.roles.form.grant.detail', {
                permission: grantError.permission,
                scope: vocabulary.scope(grantError.scope),
              })
            }}
          </p>
          <p>{{ t('core.roles.form.grant.denied') }}</p>
        </div>
      </div>

      <section
        v-if="mode === 'clone' && role"
        class="border-border flex flex-col gap-1 rounded-lg border px-3 py-2 text-sm"
        aria-labelledby="role-form-source-title"
      >
        <h2 id="role-form-source-title" class="font-medium">
          {{ t('core.roles.form.source.title') }}: {{ role.name }}
        </h2>
        <p>
          {{
            t('core.roles.form.source.summary', {
              count: role.permissions?.length ?? 0,
              mfa: role.mfa_required ? t('core.roles.yes') : t('core.roles.no'),
              special: role.special_data_access ? t('core.roles.yes') : t('core.roles.no'),
            })
          }}
        </p>
        <p class="text-muted-foreground">{{ t('core.roles.form.source.explanation') }}</p>
        <p v-if="role.special_data_access" class="text-sm font-medium">
          {{ t('core.roles.form.source.specialWarning') }}
        </p>
      </section>

      <div class="flex flex-col gap-1.5">
        <template v-if="isSystem">
          <p class="text-sm font-medium">{{ t('core.roles.form.name') }}: {{ role?.name }}</p>
          <p class="text-muted-foreground text-xs">{{ t('core.roles.form.nameSystem') }}</p>
        </template>
        <template v-else>
          <Label for="role-form-name">
            {{ t('core.roles.form.name') }}
            <span aria-hidden="true">*</span>
            <span class="sr-only">{{ t('core.roles.form.required') }}</span>
          </Label>
          <Input
            id="role-form-name"
            v-model="values.name"
            autocomplete="off"
            required
            :aria-invalid="invalid('name')"
            :aria-describedby="describedBy('name', ['role-form-name-help'])"
          />
          <p id="role-form-name-help" class="text-muted-foreground text-xs">
            {{ t('core.roles.form.nameHelp') }}
          </p>
          <FormFieldError id="role-form-name-error" :messages="fieldErrors.name" />
        </template>
      </div>

      <div v-if="mode !== 'edit'" class="flex flex-col gap-1.5">
        <Label for="role-form-code">
          {{ t('core.roles.form.code') }}
          <span aria-hidden="true">*</span>
          <span class="sr-only">{{ t('core.roles.form.required') }}</span>
        </Label>
        <Input
          id="role-form-code"
          v-model="values.code"
          autocomplete="off"
          required
          class="font-mono"
          :aria-invalid="invalid('code')"
          :aria-describedby="describedBy('code', ['role-form-code-help'])"
          @input="codeTouched = true"
        />
        <p id="role-form-code-help" class="text-muted-foreground text-xs">
          {{ t('core.roles.form.codeHelp') }}
        </p>
        <FormFieldError id="role-form-code-error" :messages="fieldErrors.code" />
      </div>
      <p v-else-if="role" class="text-sm">
        {{ t('core.roles.detail.code') }}: <code class="font-mono text-xs">{{ role.code }}</code>
      </p>

      <div v-if="mode === 'new'" class="flex flex-col gap-1">
        <label
          class="flex min-h-8 items-center gap-2 text-sm [@media(any-pointer:coarse)]:min-h-11"
          for="role-form-mfa"
        >
          <input
            id="role-form-mfa"
            v-model="values.mfaRequired"
            type="checkbox"
            class="accent-primary-on-background size-4"
            aria-describedby="role-form-mfa-help"
          />
          {{ t('core.roles.form.mfaRequired') }}
        </label>
        <p id="role-form-mfa-help" class="text-muted-foreground text-xs">
          {{ t('core.roles.form.mfaHelp') }}
        </p>
      </div>
      <div v-else-if="mode === 'edit' && role" class="flex flex-col gap-1 text-sm">
        <p>
          {{
            t('core.roles.form.mfaReadOnly', {
              value: role.mfa_required ? t('core.roles.yes') : t('core.roles.no'),
            })
          }}
        </p>
        <RouterLink
          v-if="hasMfaAdminRoute"
          :to="{ name: 'mfa-administration' }"
          class="text-primary-on-background focus-visible:ring-ring/50 w-fit rounded-sm underline-offset-4 outline-none hover:underline focus-visible:ring-3"
        >
          {{ t('core.roles.form.mfaLink') }}
        </RouterLink>
      </div>

      <div v-if="showSpecial" class="flex flex-col gap-1">
        <label
          class="flex min-h-8 items-center gap-2 text-sm [@media(any-pointer:coarse)]:min-h-11"
          for="role-form-special"
        >
          <input
            id="role-form-special"
            v-model="values.specialData"
            type="checkbox"
            class="accent-primary-on-background size-4"
            :disabled="specialDisabled"
            :aria-invalid="invalid('special')"
            :aria-describedby="
              describedBy('special', [
                'role-form-special-hint',
                ...(specialDisabled ? ['role-form-special-not-held'] : []),
              ])
            "
          />
          {{ t('core.roles.form.specialData') }}
        </label>
        <p
          v-if="specialDisabled"
          id="role-form-special-not-held"
          class="text-muted-foreground text-xs"
        >
          {{ t('core.roles.form.specialNotHeld') }}
        </p>
        <p id="role-form-special-hint" class="text-muted-foreground text-xs">
          {{ t('core.roles.form.specialHint') }}
        </p>
        <!-- `CA-PERM-105`: el `403` del servidor se anuncia junto al control. -->
        <div v-if="fieldErrors.special" role="alert">
          <FormFieldError id="role-form-special-error" :messages="fieldErrors.special" />
        </div>
      </div>

      <div class="flex flex-wrap gap-2">
        <Button type="submit" :disabled="submitting">{{ t(submitKey) }}</Button>
        <Button type="button" variant="outline" :disabled="submitting" @click="cancel">
          {{ t('core.roles.form.cancel') }}
        </Button>
      </div>
    </form>

    <ConfirmDialog
      :open="confirmation.open.value"
      :request="confirmation.request.value"
      @confirm="confirmation.confirm"
      @cancel="confirmation.cancel"
    />
  </div>
</template>
