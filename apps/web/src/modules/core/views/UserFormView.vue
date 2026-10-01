<script setup lang="ts">
/**
 * `/administracion/usuarios/nuevo` y `/administracion/usuarios/:publicId/editar`
 * (`docs/modulos/REQ-CORE/funcional.md §14.4.3`, `RN-CORE-65`/`-66`,
 * `OPEN-CORE-43` = A, 1.9b). Un solo formulario para el alta (`POST /users`) y
 * la edición (`PATCH /users/{id}`, sin roles ni invitación).
 *
 * - **Validación de cliente = comodidad** (`INV-010`): el servidor decide. Un
 *   `422` pinta cada `errors.<campo>[].message` (ya traducido, `ADR-038 §6.3`)
 *   bajo su campo con `aria-invalid="true"` y `aria-describedby`; el foco va al
 *   primer campo con error y un resumen con `role="alert"` los enumera.
 * - **`PATCH` solo con lo modificado** (`ADR-038 §9.2`): una clave ausente no
 *   toca el campo; vaciar uno opcional envía `null`, nunca `""`.
 * - **Un `403` de `RPERM-013`** (asignar un rol con permisos que el solicitante
 *   no tiene) muestra el `detail` del servidor junto al campo de roles.
 * - **Tipo de documento**: el servidor lo acepta como texto libre (hasta 32
 *   caracteres) y solo valida el formato de `DNI` y `NIE`
 *   (`DocumentNumberValidator`); no hay catálogo cerrado que ofrecer, así que
 *   es un campo de texto y no un selector con una lista inventada.
 * - **El token de la invitación nunca llega a la SPA** (`RN-CORE-19`): solo su
 *   caducidad (`invitation.expires_at`).
 */
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { localeFromDomain, useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useDataTableFormatters } from '@/data-table'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import { resolveErrorState, type ShellErrorState } from '@/layouts/errorState'
import { useTenantBranding } from '@/tenant/useTenantBranding'
import {
  createUser,
  getUser,
  listRoles,
  updateUser,
  type CreateUserPayload,
  type UpdateUserPayload,
  type UserPersonPayload,
} from '../api'
import {
  problemDetail,
  problemFieldErrors,
  problemRetryAfter,
  problemStatus,
} from '../composables/problem'
import FormFieldError from '../components/FormFieldError.vue'
import { usePermissions } from '../composables/usePermissions'
import { setFlash } from '../composables/useFlash'
import type { Locale, PublicId, Role } from '../types'

const t = useT()
const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const { branding } = useTenantBranding()
const { formatDateTime } = useDataTableFormatters()

const editingId = computed(() => (route.params.publicId ? String(route.params.publicId) : null))
const isEdit = computed(() => editingId.value !== null)

const FIELDS = [
  'email',
  'given_name',
  'family_name_1',
  'family_name_2',
  'birth_date',
  'document_type',
  'document_number',
  'contact_email',
  'contact_phone',
  'locale',
] as const

type FieldName = (typeof FIELDS)[number]
type FormValues = Record<FieldName, string>

const PERSON_FIELDS: readonly Exclude<FieldName, 'email'>[] = FIELDS.filter(
  (name): name is Exclude<FieldName, 'email'> => name !== 'email',
)

const ALL_LOCALES: Locale[] = ['es-ES', 'en', 'de', 'fr']

const activeLocales = computed<Locale[]>(() => branding.value?.active_locales ?? ALL_LOCALES)

function emptyValues(): FormValues {
  return {
    email: '',
    given_name: '',
    family_name_1: '',
    family_name_2: '',
    birth_date: '',
    document_type: '',
    document_number: '',
    contact_email: '',
    contact_phone: '',
    locale: branding.value?.default_locale ?? 'es-ES',
  }
}

const values = reactive<FormValues>(emptyValues())
let initial: FormValues = emptyValues()

const loading = ref(false)
const loadError = ref<ShellErrorState | null>(null)
const submitting = ref(false)

const sendInvitation = ref(true)
const roles = ref<Role[]>([])
const selectedRoleIds = ref<PublicId[]>([])

/** Mensajes por campo (nombre de campo del formulario) y mensajes sin campo. */
const fieldErrors = ref<Partial<Record<FieldName | 'roles', string[]>>>({})
const generalError = ref<string | null>(null)

// `rol.leer` y `asignacion_rol.crear`: sin ellos no hay selector ni `GET /roles` (RN-CORE-62).
const canAssignRoles = computed(
  () => !isEdit.value && can('rol.leer') && can('asignacion_rol.crear'),
)

function translated(key: string, fallback: string): string {
  const label = t(key)

  return label === key ? fallback : label
}

function localeName(locale: string): string {
  return translated(`core.locale.name.${localeFromDomain(locale as Locale)}`, locale)
}

/** `person.family_name_1` → `family_name_1`; `role_ids.0` → `roles`; el resto, igual. */
function formField(serverField: string): FieldName | 'roles' | null {
  if (serverField === 'role_ids' || serverField.startsWith('role_ids.')) {
    return 'roles'
  }

  const name = serverField.startsWith('person.') ? serverField.slice('person.'.length) : serverField

  return (FIELDS as readonly string[]).includes(name) ? (name as FieldName) : null
}

const errorSummary = computed(() => {
  const lines: string[] = []

  for (const messages of Object.values(fieldErrors.value)) {
    lines.push(...(messages ?? []))
  }

  if (generalError.value) {
    lines.push(generalError.value)
  }

  return lines
})

function describedBy(name: FieldName | 'roles'): string | undefined {
  return (fieldErrors.value[name]?.length ?? 0) > 0 ? `user-form-${name}-error` : undefined
}

function invalid(name: FieldName | 'roles'): true | undefined {
  return (fieldErrors.value[name]?.length ?? 0) > 0 ? true : undefined
}

async function focusFirstError(): Promise<void> {
  await nextTick()

  const order: (FieldName | 'roles')[] = [...FIELDS, 'roles']
  const first = order.find((name) => (fieldErrors.value[name]?.length ?? 0) > 0)

  if (first) {
    document.getElementById(`user-form-${first}`)?.focus()
  }
}

async function load(): Promise<void> {
  if (!editingId.value) {
    return
  }

  loading.value = true
  loadError.value = null

  try {
    const user = await getUser(editingId.value)
    const loaded: FormValues = {
      email: user.email,
      given_name: user.person.given_name,
      family_name_1: user.person.family_name_1,
      family_name_2: user.person.family_name_2 ?? '',
      birth_date: user.person.birth_date ?? '',
      document_type: user.person.document_type ?? '',
      document_number: user.person.document_number ?? '',
      contact_email: user.person.contact_email ?? '',
      contact_phone: user.person.contact_phone ?? '',
      locale: user.person.locale,
    }

    initial = { ...loaded }
    Object.assign(values, loaded)
  } catch (err) {
    loadError.value = resolveErrorState(err)
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  if (canAssignRoles.value) {
    try {
      roles.value = (await listRoles({ per_page: 100 })).data
    } catch {
      roles.value = []
    }
  }

  await load()
})

function toggleRole(roleId: PublicId, checked: boolean): void {
  selectedRoleIds.value = checked
    ? [...selectedRoleIds.value, roleId]
    : selectedRoleIds.value.filter((id) => id !== roleId)
}

function createPayload(): CreateUserPayload {
  const person: UserPersonPayload = {
    given_name: values.given_name.trim(),
    family_name_1: values.family_name_1.trim(),
  }

  for (const name of PERSON_FIELDS) {
    const value = values[name].trim()

    if (value !== '' && name !== 'given_name' && name !== 'family_name_1') {
      person[name] = value
    }
  }

  const payload: CreateUserPayload = {
    email: values.email.trim(),
    person,
    send_invitation: sendInvitation.value,
  }

  if (canAssignRoles.value && selectedRoleIds.value.length > 0) {
    payload.role_ids = selectedRoleIds.value
  }

  return payload
}

/** `ADR-038 §9.2`: solo las claves modificadas; vaciar un campo envía `null`, nunca `""`. */
function updatePayload(): UpdateUserPayload | null {
  const payload: UpdateUserPayload = {}
  const person: Partial<Record<(typeof PERSON_FIELDS)[number], string | null>> = {}

  if (values.email.trim() !== initial.email) {
    payload.email = values.email.trim()
  }

  for (const name of PERSON_FIELDS) {
    const value = values[name].trim()

    if (value !== initial[name]) {
      person[name] = value === '' ? null : value
    }
  }

  if (Object.keys(person).length > 0) {
    payload.person = person as UpdateUserPayload['person']
  }

  return Object.keys(payload).length > 0 ? payload : null
}

async function submit(): Promise<void> {
  fieldErrors.value = {}
  generalError.value = null

  if (isEdit.value && editingId.value) {
    const payload = updatePayload()

    if (payload === null) {
      await router.push({ name: 'core-user-detail', params: { publicId: editingId.value } })

      return
    }

    submitting.value = true

    try {
      await updateUser(editingId.value, payload)
      setFlash({ key: 'core.users.flash.updated' })
      await router.push({ name: 'core-user-detail', params: { publicId: editingId.value } })
    } catch (err) {
      await showError(err)
    } finally {
      submitting.value = false
    }

    return
  }

  submitting.value = true

  try {
    const created = await createUser(createPayload())

    setFlash(
      created.invitation
        ? {
            key: 'core.users.flash.createdInvited',
            params: { date: formatDateTime(created.invitation.expires_at) ?? '' },
          }
        : { key: 'core.users.flash.created' },
    )
    await router.push({ name: 'core-user-detail', params: { publicId: created.public_id } })
  } catch (err) {
    await showError(err)
  } finally {
    submitting.value = false
  }
}

async function showError(err: unknown): Promise<void> {
  const status = problemStatus(err)
  const next: Partial<Record<FieldName | 'roles', string[]>> = {}

  if (status === 422) {
    for (const [serverField, messages] of Object.entries(problemFieldErrors(err))) {
      const field = formField(serverField)

      if (field) {
        next[field] = [...(next[field] ?? []), ...messages]
      } else {
        generalError.value = messages.join(' ')
      }
    }

    fieldErrors.value = next

    if (errorSummary.value.length === 0) {
      generalError.value = t('core.users.errors.unexpected')
    }

    await focusFirstError()

    return
  }

  if (status === 403 && canAssignRoles.value && selectedRoleIds.value.length > 0) {
    // RPERM-013: el `detail` del servidor, junto al selector de roles.
    fieldErrors.value = { roles: [problemDetail(err) ?? t('core.users.errors.unexpected')] }
    await focusFirstError()

    return
  }

  if (status === 429) {
    const seconds = problemRetryAfter(err)

    generalError.value =
      seconds !== null
        ? t('core.users.errors.tooManyRequestsWithSeconds', { seconds })
        : t('core.users.errors.tooManyRequests')

    return
  }

  generalError.value = problemDetail(err) ?? t('core.users.errors.unexpected')
}

interface InputField {
  name: Exclude<FieldName, 'email' | 'locale'>
  labelKey: string
  type?: 'date' | 'email' | 'tel'
  required?: boolean
  maxlength?: number
  hintKey?: string
}

/** Campos de texto de la persona, en el orden del formulario (y del foco de errores). */
const inputFields: readonly InputField[] = [
  { name: 'given_name', labelKey: 'core.users.form.givenName', required: true },
  { name: 'family_name_1', labelKey: 'core.users.form.familyName1', required: true },
  { name: 'family_name_2', labelKey: 'core.users.form.familyName2' },
  { name: 'birth_date', labelKey: 'core.users.form.birthDate', type: 'date' },
  {
    name: 'document_type',
    labelKey: 'core.users.form.documentType',
    maxlength: 32,
    hintKey: 'core.users.form.documentTypeHint',
  },
  { name: 'document_number', labelKey: 'core.users.form.documentNumber', maxlength: 32 },
  { name: 'contact_email', labelKey: 'core.users.form.contactEmail', type: 'email' },
  { name: 'contact_phone', labelKey: 'core.users.form.contactPhone', type: 'tel', maxlength: 32 },
]

function fieldDescribedBy(field: InputField): string | undefined {
  const ids = [
    field.hintKey ? `user-form-${field.name}-hint` : null,
    describedBy(field.name) ?? null,
  ].filter(Boolean)

  return ids.length > 0 ? ids.join(' ') : undefined
}

const selectClass =
  'border-input bg-background flex h-8 w-full min-w-0 rounded-lg border px-2.5 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-3 aria-invalid:border-destructive disabled:cursor-not-allowed disabled:opacity-50 [@media(any-pointer:coarse)]:min-h-11'
</script>

<template>
  <div class="mx-auto flex max-w-2xl flex-col gap-4 px-4 py-6">
    <h1 class="text-lg font-semibold">
      {{ isEdit ? t('core.users.form.titleEdit') : t('core.users.form.titleNew') }}
    </h1>

    <LoadingState v-if="loading" />
    <ErrorState v-else-if="loadError" :state="loadError" @retry="load" />

    <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <p class="text-muted-foreground text-xs">{{ t('core.users.form.requiredHint') }}</p>

      <div
        v-if="errorSummary.length > 0"
        role="alert"
        class="border-destructive text-destructive rounded-lg border px-3 py-2 text-sm"
      >
        <p class="font-medium">{{ t('core.users.form.errorSummary') }}</p>
        <ul class="list-disc pl-5">
          <li v-for="line in errorSummary" :key="line">{{ line }}</li>
        </ul>
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="user-form-email">
          {{ t('core.users.form.email') }}
          <span aria-hidden="true">*</span>
          <span class="sr-only">{{ t('core.users.form.required') }}</span>
        </Label>
        <Input
          id="user-form-email"
          v-model="values.email"
          type="email"
          autocomplete="off"
          required
          :aria-invalid="invalid('email')"
          :aria-describedby="describedBy('email')"
        />
        <FormFieldError id="user-form-email-error" :messages="fieldErrors.email" />
      </div>

      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div v-for="field in inputFields" :key="field.name" class="flex flex-col gap-1.5">
          <Label :for="`user-form-${field.name}`">
            {{ t(field.labelKey) }}
            <template v-if="field.required">
              <span aria-hidden="true">*</span>
              <span class="sr-only">{{ t('core.users.form.required') }}</span>
            </template>
          </Label>
          <Input
            :id="`user-form-${field.name}`"
            v-model="values[field.name]"
            :type="field.type"
            :maxlength="field.maxlength"
            :required="field.required"
            autocomplete="off"
            :aria-invalid="invalid(field.name)"
            :aria-describedby="fieldDescribedBy(field)"
          />
          <p
            v-if="field.hintKey"
            :id="`user-form-${field.name}-hint`"
            class="text-muted-foreground text-xs"
          >
            {{ t(field.hintKey) }}
          </p>
          <FormFieldError
            :id="`user-form-${field.name}-error`"
            :messages="fieldErrors[field.name]"
          />
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="user-form-locale">{{ t('core.users.form.locale') }}</Label>
          <select
            id="user-form-locale"
            v-model="values.locale"
            :class="selectClass"
            :aria-invalid="invalid('locale')"
            :aria-describedby="describedBy('locale')"
          >
            <option v-for="locale in activeLocales" :key="locale" :value="locale">
              {{ localeName(locale) }}
            </option>
            <!-- Un idioma que el centro ya no tiene activo se conserva al editar, no se pierde en silencio. -->
            <option v-if="!activeLocales.includes(values.locale as Locale)" :value="values.locale">
              {{ localeName(values.locale) }}
            </option>
          </select>
          <FormFieldError id="user-form-locale-error" :messages="fieldErrors.locale" />
        </div>
      </div>

      <fieldset
        v-if="canAssignRoles"
        class="flex flex-col gap-1"
        :aria-describedby="describedBy('roles')"
      >
        <legend class="mb-1 text-sm font-medium">{{ t('core.users.form.roles') }}</legend>
        <label
          v-for="(role, index) in roles"
          :key="role.public_id"
          class="flex min-h-8 items-center gap-2 text-sm [@media(any-pointer:coarse)]:min-h-11"
        >
          <input
            :id="index === 0 ? 'user-form-roles' : undefined"
            type="checkbox"
            class="accent-primary-on-background size-4"
            :checked="selectedRoleIds.includes(role.public_id)"
            :aria-invalid="invalid('roles')"
            @change="
              (event: Event) =>
                toggleRole(role.public_id, (event.target as HTMLInputElement).checked)
            "
          />
          {{ role.name }}
        </label>
        <FormFieldError id="user-form-roles-error" :messages="fieldErrors.roles" />
      </fieldset>

      <label
        v-if="!isEdit"
        class="flex min-h-8 items-center gap-2 text-sm [@media(any-pointer:coarse)]:min-h-11"
      >
        <input
          v-model="sendInvitation"
          type="checkbox"
          class="accent-primary-on-background size-4"
        />
        {{ t('core.users.form.sendInvitation') }}
      </label>

      <div class="flex flex-wrap gap-2">
        <Button type="submit" :disabled="submitting">
          {{ submitting ? t('core.users.form.saving') : t('core.users.form.save') }}
        </Button>
        <Button variant="outline" as-child>
          <RouterLink
            :to="
              isEdit && editingId
                ? { name: 'core-user-detail', params: { publicId: editingId } }
                : { name: 'core-users' }
            "
          >
            {{ t('core.users.form.cancel') }}
          </RouterLink>
        </Button>
      </div>
    </form>
  </div>
</template>
