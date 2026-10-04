<script setup lang="ts">
/**
 * `/administracion/centro` (`docs/modulos/REQ-CORE/funcional.md §14.9`,
 * `RN-CORE-79` a `-82`, `OPEN-CORE-37` = B, 1.9e). Un formulario por grupo de
 * `GET /tenant/settings`: Regional, Fiscal, Paleta y Seguridad.
 *
 * - **Lectura o edición por permiso** (`RN-CORE-79`): con `configuracion.leer`
 *   sin `configuracion.actualizar` la pantalla se pinta en solo lectura (valores
 *   como texto, sin campos editables ni botón de guardar). Con
 *   `configuracion.actualizar`, cada grupo se guarda por separado con su propio
 *   `PATCH` que envía **solo las claves modificadas de ese grupo** (`RN-CORE-65`,
 *   `ADR-038 §9.2`); vaciar un campo opcional envía `null`, nunca `""`.
 * - **Validación de cliente = comodidad** (`INV-010`): el servidor decide. Un
 *   `422` pinta cada mensaje (ya traducido, `ADR-038 §6.3`) bajo su campo con
 *   `aria-invalid="true"` y `aria-describedby`; el foco va al primer campo con
 *   error del grupo que se guardaba.
 * - **Paleta** (`RN-CORE-80`): vista previa y razón de contraste con las
 *   funciones puras de 1.7; **no** aplica la paleta al documento (eso es de la
 *   capa B). Tras guardar, `useTenantBranding().refresh()`.
 * - **Idiomas** (`RN-CORE-81`): «idioma por defecto» solo ofrece los idiomas
 *   marcados; si se retira el idioma de la interfaz actual, aviso previo.
 * - **Seguridad** (`OPEN-CORE-37` = B): `/administracion/mfa` no edita ninguna
 *   clave de `security.*` de la configuración (solo `mfa_required` por rol,
 *   exenciones y restablecimientos), así que las tres se editan aquí; el método
 *   `sms` no se ofrece porque el servidor lo rechaza mientras no haya proveedor
 *   (`RN-AUTH-69`), y `totp` va siempre marcado (es obligatorio). La pantalla de
 *   MFA se enlaza para el resto.
 */
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { i18n, localeFromDomain, useT, type DomainLocale } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { contrastRatio } from '@/design-system/color/contrast'
import { isValidHex } from '@/design-system/color/color'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import { resolveErrorState, type ShellErrorState } from '@/layouts/errorState'
import { useTenantBranding } from '@/tenant/useTenantBranding'
import { AUTONOMOUS_COMMUNITIES } from '../autonomousCommunities'
import { getTenantSettings, updateTenantSettings, type UpdateTenantSettingsPayload } from '../api'
import {
  problemDetail,
  problemErrorParams,
  problemFieldErrors,
  problemRetryAfter,
  problemStatus,
} from '../composables/problem'
import { usePermissions } from '../composables/usePermissions'
import FormFieldError from '../components/FormFieldError.vue'
import SettingsField from '../components/SettingsField.vue'
import type { Locale, TenantSettings } from '../types'

type GroupId = 'regional' | 'fiscal' | 'palette' | 'security'

const GROUPS: readonly GroupId[] = ['regional', 'fiscal', 'palette', 'security']
const ALL_LOCALES: readonly Locale[] = ['es-ES', 'en', 'de', 'fr']
/** `RUX-BRAND-006`: umbral WCAG 2.2 AA de texto normal (`RN-CORE-80`). */
const REQUIRED_CONTRAST = 4.5
const UI_TO_DOMAIN: Record<string, Locale> = { es: 'es-ES', en: 'en', de: 'de', fr: 'fr' }

/** Permisos de `/administracion/mfa` (`auth/shell.ts`): sin ninguno no se ofrece el enlace (`RN-CORE-62`). */
const MFA_ADMIN_PERMISSIONS = [
  'mfa.leer',
  'rol.actualizar',
  'mfa.eliminar',
  'exencion_mfa.crear',
  'exencion_mfa.leer',
  'exencion_mfa.eliminar',
]

const t = useT()
const { can } = usePermissions()
const { refresh } = useTenantBranding()

const canEdit = computed(() => can('configuracion.actualizar'))
const canOpenMfaAdmin = computed(() => MFA_ADMIN_PERMISSIONS.some((code) => can(code)))

const settings = ref<TenantSettings | null>(null)
const loading = ref(true)
const loadError = ref<ShellErrorState | null>(null)

const regional = reactive({
  default_locale: '' as string,
  active_locales: [] as Locale[],
  timezone: '',
  currency: '',
  autonomous_community: '',
})
const fiscal = reactive({
  legal_name: '',
  tax_id: '',
  address: '',
  postal_code: '',
  city: '',
  province: '',
  country_code: '',
})
const palette = reactive({ color_primary: '', color_secondary: '' })
const security = reactive({
  session_timeout_minutes: '',
  email_method: false,
  mfa_grace_period_days: '',
})

const FISCAL_FIELDS = [
  { name: 'legal_name', labelKey: 'core.settings.fiscal.legalName', maxlength: 255 },
  { name: 'tax_id', labelKey: 'core.settings.fiscal.taxId', maxlength: 32 },
  { name: 'address', labelKey: 'core.settings.fiscal.address', maxlength: 255 },
  { name: 'postal_code', labelKey: 'core.settings.fiscal.postalCode', maxlength: 16 },
  { name: 'city', labelKey: 'core.settings.fiscal.city', maxlength: 120 },
  { name: 'province', labelKey: 'core.settings.fiscal.province', maxlength: 120 },
  {
    name: 'country_code',
    labelKey: 'core.settings.fiscal.countryCode',
    maxlength: 2,
    hintKey: 'core.settings.fiscal.countryCodeHint',
  },
] as const

type FiscalField = (typeof FISCAL_FIELDS)[number]['name']

const errors = reactive<Record<GroupId, Record<string, string[]>>>({
  regional: {},
  fiscal: {},
  palette: {},
  security: {},
})
const generalError = reactive<Record<GroupId, string | null>>({
  regional: null,
  fiscal: null,
  palette: null,
  security: null,
})
const statusMessage = reactive<Record<GroupId, string | null>>({
  regional: null,
  fiscal: null,
  palette: null,
  security: null,
})
const saving = reactive<Record<GroupId, boolean>>({
  regional: false,
  fiscal: false,
  palette: false,
  security: false,
})
/** Razón y mínimo que el servidor comunicó en un `422 contrast_insufficient` (`CA-CORE-250`). */
const serverContrast = ref<{ ratio: number; required: number } | null>(null)

let initialRegional = { ...regional, active_locales: [] as Locale[] }
let initialFiscal = { ...fiscal }
let initialPalette = { ...palette }
let initialSecurity = { ...security }
// Lista de métodos tal como la devuelve el servidor (issue #323): solo se alterna `email` sobre ella.
let initialMfaMethods: string[] = []

function fill(data: TenantSettings): void {
  settings.value = data

  Object.assign(regional, {
    default_locale: data.regional.default_locale,
    active_locales: [...data.regional.active_locales],
    timezone: data.regional.timezone,
    currency: data.regional.currency,
    autonomous_community: data.regional.autonomous_community ?? '',
  })
  initialRegional = { ...regional, active_locales: [...regional.active_locales] }

  Object.assign(fiscal, {
    legal_name: data.fiscal.legal_name ?? '',
    tax_id: data.fiscal.tax_id ?? '',
    address: data.fiscal.address ?? '',
    postal_code: data.fiscal.postal_code ?? '',
    city: data.fiscal.city ?? '',
    province: data.fiscal.province ?? '',
    country_code: data.fiscal.country_code ?? '',
  })
  initialFiscal = { ...fiscal }

  Object.assign(palette, {
    color_primary: data.branding.color_primary ?? '',
    color_secondary: data.branding.color_secondary ?? '',
  })
  initialPalette = { ...palette }

  if (data.security) {
    Object.assign(security, {
      session_timeout_minutes: String(data.security.session_timeout_minutes),
      email_method: data.security.mfa_allowed_methods.includes('email'),
      mfa_grace_period_days: String(data.security.mfa_grace_period_days),
    })
    initialSecurity = { ...security }
    initialMfaMethods = [...data.security.mfa_allowed_methods]
  }
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null

  try {
    fill(await getTenantSettings())
  } catch (err) {
    loadError.value = resolveErrorState(err)
  } finally {
    loading.value = false
  }
}

onMounted(load)

function translated(key: string, fallback: string): string {
  const label = t(key)

  return label === key ? fallback : label
}

function localeName(locale: string): string {
  return translated(`core.locale.name.${localeFromDomain(locale as DomainLocale)}`, locale)
}

function communityName(code: string): string {
  return translated(`core.settings.autonomousCommunity.${code}`, code)
}

// -- Regional ---------------------------------------------------------------

const timezoneQuery = ref('')

const allTimezones = computed<string[]>(() => {
  const intl = Intl as unknown as { supportedValuesOf?: (key: string) => string[] }

  try {
    return intl.supportedValuesOf ? intl.supportedValuesOf('timeZone') : []
  } catch {
    return []
  }
})

/** Búsqueda por texto (`RN-CORE-82`); el valor actual se conserva siempre como opción. */
const timezoneOptions = computed<string[]>(() => {
  const query = timezoneQuery.value.trim().toLowerCase().replace(/\s+/g, '_')
  const matches = allTimezones.value.filter((zone) => zone.toLowerCase().includes(query))

  return regional.timezone !== '' && !matches.includes(regional.timezone)
    ? [regional.timezone, ...matches]
    : matches
})

function toggleLocale(locale: Locale, checked: boolean): void {
  const next = ALL_LOCALES.filter((candidate) =>
    candidate === locale ? checked : regional.active_locales.includes(candidate),
  )

  regional.active_locales = [...next]

  // «Idioma por defecto» solo ofrece los marcados (RN-CORE-81): si deja de ser uno, se pide elegir otro.
  if (!next.includes(regional.default_locale as Locale)) {
    regional.default_locale = ''
  }
}

const activeLocalesChanged = computed(
  () => regional.active_locales.join(',') !== initialRegional.active_locales.join(','),
)

const interfaceLocaleRetired = computed(() => {
  const domain = UI_TO_DOMAIN[i18n.global.locale.value] ?? 'es-ES'

  return activeLocalesChanged.value && !regional.active_locales.includes(domain)
})

const regionalBlocker = computed<string | null>(() => {
  if (regional.active_locales.length === 0) {
    return t('core.settings.regional.activeLocalesRequired')
  }

  if (regional.default_locale === '') {
    return t('core.settings.regional.defaultLocaleRequired')
  }

  return null
})

function regionalPayload(): UpdateTenantSettingsPayload['regional'] | null {
  const payload: NonNullable<UpdateTenantSettingsPayload['regional']> = {}

  if (regional.default_locale !== initialRegional.default_locale) {
    payload.default_locale = regional.default_locale
  }

  if (activeLocalesChanged.value) {
    payload.active_locales = [...regional.active_locales]
  }

  if (regional.timezone !== initialRegional.timezone) {
    payload.timezone = regional.timezone
  }

  const currency = regional.currency.trim().toUpperCase()

  if (currency !== initialRegional.currency) {
    payload.currency = currency
  }

  if (regional.autonomous_community !== initialRegional.autonomous_community) {
    payload.autonomous_community =
      regional.autonomous_community === '' ? null : regional.autonomous_community
  }

  return Object.keys(payload).length > 0 ? payload : null
}

// -- Fiscal -----------------------------------------------------------------

function fiscalPayload(): UpdateTenantSettingsPayload['fiscal'] | null {
  const payload: NonNullable<UpdateTenantSettingsPayload['fiscal']> = {}

  for (const { name } of FISCAL_FIELDS) {
    const raw = fiscal[name as FiscalField].trim()
    const value = name === 'country_code' ? raw.toUpperCase() : raw

    if (value !== initialFiscal[name as FiscalField]) {
      payload[name as FiscalField] = value === '' ? null : value
    }
  }

  return Object.keys(payload).length > 0 ? payload : null
}

// -- Paleta -----------------------------------------------------------------

const contrastFormat = computed(
  () =>
    new Intl.NumberFormat(i18n.global.locale.value, {
      minimumFractionDigits: 1,
      maximumFractionDigits: 2,
    }),
)

function formatRatio(value: number): string {
  // Se trunca (no se redondea) a dos decimales: 4,497 no debe leerse como 4,5.
  return contrastFormat.value.format(Math.floor(value * 100) / 100)
}

const paletteRatio = computed<number | null>(() => {
  const primary = palette.color_primary.trim()
  const secondary = palette.color_secondary.trim()

  return isValidHex(primary) && isValidHex(secondary) ? contrastRatio(primary, secondary) : null
})

const paletteMeetsContrast = computed(
  () => paletteRatio.value !== null && paletteRatio.value >= REQUIRED_CONTRAST,
)

function palettePayload(): UpdateTenantSettingsPayload['branding'] | null {
  const payload: NonNullable<UpdateTenantSettingsPayload['branding']> = {}

  for (const name of ['color_primary', 'color_secondary'] as const) {
    const value = palette[name].trim()

    if (value !== initialPalette[name]) {
      payload[name] = value === '' ? null : value
    }
  }

  return Object.keys(payload).length > 0 ? payload : null
}

// -- Seguridad --------------------------------------------------------------

function securityPayload(): UpdateTenantSettingsPayload['security'] | null {
  const payload: NonNullable<UpdateTenantSettingsPayload['security']> = {}

  if (security.session_timeout_minutes.trim() !== initialSecurity.session_timeout_minutes) {
    payload.session_timeout_minutes = Number(security.session_timeout_minutes)
  }

  if (security.email_method !== initialSecurity.email_method) {
    const others = initialMfaMethods.filter((method) => method !== 'email')

    payload.mfa_allowed_methods = security.email_method ? [...others, 'email'] : others
  }

  if (security.mfa_grace_period_days.trim() !== initialSecurity.mfa_grace_period_days) {
    payload.mfa_grace_period_days = Number(security.mfa_grace_period_days)
  }

  return Object.keys(payload).length > 0 ? payload : null
}

const INTEGER = /^-?\d+$/

/** Issue #324: un valor que no es entero no se envía (`Number('')` daría `0`); el rango lo valida el servidor. */
function securityClientErrors(): Record<string, string[]> {
  const found: Record<string, string[]> = {}

  for (const name of ['session_timeout_minutes', 'mfa_grace_period_days'] as const) {
    if (security[name].trim() !== initialSecurity[name] && !INTEGER.test(security[name].trim())) {
      found[name] = [t('core.settings.errors.notInteger')]
    }
  }

  return found
}

// -- Cambios y guardado -----------------------------------------------------

const dirty = computed<Record<GroupId, boolean>>(() => ({
  regional: regionalPayload() !== null,
  fiscal: fiscalPayload() !== null,
  palette: palettePayload() !== null,
  security: securityPayload() !== null,
}))

watch(
  () => i18n.global.locale.value,
  () => {
    // El mensaje de estado se redacta en el idioma activo: se descarta al cambiarlo.
    for (const group of GROUPS) {
      statusMessage[group] = null
    }
  },
)

/** `regional.default_locale` → `['regional', 'default_locale']`; `branding` → `['palette', '_']`. */
function routeServerField(serverField: string, current: GroupId): [GroupId, string] {
  const [head, ...rest] = serverField.split('.')
  const key = rest.join('.')

  if (head === 'regional' || head === 'fiscal' || head === 'security') {
    return [head, key === '' ? '_' : key]
  }

  if (head === 'branding') {
    return ['palette', key === '' ? '_' : key]
  }

  return [current, '_']
}

function inputId(group: GroupId, field: string): string {
  return `settings-${group}-${field}`
}

async function focusFirstError(group: GroupId): Promise<void> {
  await nextTick()

  const field = Object.keys(errors[group]).find((key) => key !== '_')

  document.getElementById(field ? inputId(group, field) : `settings-${group}-summary`)?.focus()
}

function fail(group: GroupId, err: unknown): Promise<void> {
  const status = problemStatus(err)

  if (status === 422) {
    const next: Record<string, string[]> = {}
    const general: string[] = []

    for (const [serverField, messages] of Object.entries(problemFieldErrors(err))) {
      const [target, key] = routeServerField(serverField, group)

      if (target === group) {
        next[key] = [...(next[key] ?? []), ...messages]
      } else {
        general.push(...messages)
      }
    }

    errors[group] = next
    generalError[group] = general.length > 0 ? general.join(' ') : null

    const contrast = problemErrorParams(err, 'contrast_insufficient')

    if (group === 'palette' && contrast) {
      serverContrast.value = {
        ratio: Number(contrast.ratio),
        required: Number(contrast.required),
      }
    }

    if (Object.keys(next).length === 0 && generalError[group] === null) {
      generalError[group] = t('core.settings.errors.unexpected')
    }

    return focusFirstError(group)
  }

  if (status === 429) {
    const seconds = problemRetryAfter(err)

    generalError[group] =
      seconds !== null
        ? t('core.settings.errors.tooManyRequestsWithSeconds', { seconds })
        : t('core.settings.errors.tooManyRequests')

    return Promise.resolve()
  }

  generalError[group] = problemDetail(err) ?? t('core.settings.errors.unexpected')

  return Promise.resolve()
}

async function save(group: GroupId): Promise<void> {
  errors[group] = {}
  generalError[group] = null
  statusMessage[group] = null

  if (group === 'palette') {
    serverContrast.value = null
  }

  const body: UpdateTenantSettingsPayload = {}

  if (group === 'regional') {
    const payload = regionalPayload()

    if (payload) body.regional = payload
  } else if (group === 'fiscal') {
    const payload = fiscalPayload()

    if (payload) body.fiscal = payload
  } else if (group === 'palette') {
    const payload = palettePayload()

    if (payload) body.branding = payload
  } else {
    const clientErrors = securityClientErrors()

    if (Object.keys(clientErrors).length > 0) {
      errors.security = clientErrors
      await focusFirstError('security')

      return
    }

    const payload = securityPayload()

    if (payload) body.security = payload
  }

  if (Object.keys(body).length === 0) {
    return
  }

  saving[group] = true

  try {
    const updated = await updateTenantSettings(body)

    // Solo se recarga el grupo guardado: lo que el usuario haya tocado en los demás se conserva.
    const others = {
      regional: { ...regional, active_locales: [...regional.active_locales] },
      fiscal: { ...fiscal },
      palette: { ...palette },
      security: { ...security },
    }
    const othersInitial = {
      regional: initialRegional,
      fiscal: initialFiscal,
      palette: initialPalette,
      security: initialSecurity,
    }

    fill(updated)

    for (const other of GROUPS) {
      if (other === group) {
        continue
      }

      if (other === 'regional') Object.assign(regional, others.regional)
      if (other === 'fiscal') Object.assign(fiscal, others.fiscal)
      if (other === 'palette') Object.assign(palette, others.palette)
      if (other === 'security') Object.assign(security, others.security)
    }

    if (group !== 'regional') initialRegional = othersInitial.regional
    if (group !== 'fiscal') initialFiscal = othersInitial.fiscal
    if (group !== 'palette') initialPalette = othersInitial.palette
    if (group !== 'security') initialSecurity = othersInitial.security

    statusMessage[group] = t(`core.settings.saved.${group}`)

    // RN-CORE-80/81: el shell adopta la paleta y los idiomas activos sin recargar.
    if (group === 'palette' || group === 'regional') {
      void refresh()
    }
  } catch (err) {
    await fail(group, err)
  } finally {
    saving[group] = false
  }
}

function fieldErrors(group: GroupId, field: string): string[] | undefined {
  return errors[group][field]
}

function invalid(group: GroupId, field: string): true | undefined {
  return (errors[group][field]?.length ?? 0) > 0 ? true : undefined
}

function describedBy(group: GroupId, field: string, extra?: string): string | undefined {
  const ids = [extra, invalid(group, field) ? `${inputId(group, field)}-error` : undefined].filter(
    Boolean,
  )

  return ids.length > 0 ? ids.join(' ') : undefined
}

const selectClass =
  'border-input bg-background flex h-8 w-full min-w-0 rounded-lg border px-2.5 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-3 aria-invalid:border-destructive disabled:cursor-not-allowed disabled:opacity-50 [@media(any-pointer:coarse)]:min-h-11'

const checkboxLabelClass =
  'flex min-h-8 items-center gap-2 text-sm [@media(any-pointer:coarse)]:min-h-11'

function display(value: string | number | null | undefined): string {
  return value === null || value === undefined || value === ''
    ? t('core.settings.notSet')
    : String(value)
}
</script>

<template>
  <div class="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-6">
    <div>
      <h1 class="text-lg font-semibold">{{ t('core.settings.title') }}</h1>
      <p class="text-muted-foreground text-sm">
        {{ canEdit ? t('core.settings.intro') : t('core.settings.introReadOnly') }}
      </p>
    </div>

    <LoadingState v-if="loading" />
    <ErrorState v-else-if="loadError" :state="loadError" @retry="load" />

    <template v-else-if="settings">
      <p>
        <RouterLink
          class="text-primary-on-background text-sm underline"
          :to="{ name: 'core-branding-assets' }"
        >
          {{ t('core.settings.brandingLink') }}
        </RouterLink>
      </p>

      <!-- Regional -->
      <section class="flex flex-col gap-3" aria-labelledby="settings-regional-title">
        <h2 id="settings-regional-title" class="text-base font-semibold">
          {{ t('core.settings.regional.title') }}
        </h2>

        <dl
          v-if="!canEdit"
          class="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-[14rem_1fr]"
        >
          <dt class="text-muted-foreground">{{ t('core.settings.regional.defaultLocale') }}</dt>
          <dd>{{ localeName(settings.regional.default_locale) }}</dd>
          <dt class="text-muted-foreground">{{ t('core.settings.regional.activeLocales') }}</dt>
          <dd>{{ settings.regional.active_locales.map(localeName).join(', ') }}</dd>
          <dt class="text-muted-foreground">{{ t('core.settings.regional.timezone') }}</dt>
          <dd>{{ display(settings.regional.timezone) }}</dd>
          <dt class="text-muted-foreground">{{ t('core.settings.regional.currency') }}</dt>
          <dd>{{ display(settings.regional.currency) }}</dd>
          <dt class="text-muted-foreground">
            {{ t('core.settings.regional.autonomousCommunity') }}
          </dt>
          <dd>
            {{
              settings.regional.autonomous_community
                ? communityName(settings.regional.autonomous_community)
                : t('core.settings.notSet')
            }}
          </dd>
        </dl>

        <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="save('regional')">
          <p class="text-muted-foreground text-xs">{{ t('core.settings.requiredHint') }}</p>

          <div
            v-if="generalError.regional"
            id="settings-regional-summary"
            role="alert"
            tabindex="-1"
            class="border-destructive text-destructive rounded-lg border px-3 py-2 text-sm"
          >
            {{ generalError.regional }}
          </div>

          <fieldset
            class="flex flex-col gap-1"
            :aria-describedby="
              describedBy('regional', 'active_locales', 'settings-regional-active_locales-hint')
            "
          >
            <legend class="mb-1 text-sm font-medium">
              {{ t('core.settings.regional.activeLocales') }}
              <span aria-hidden="true">*</span>
              <span class="sr-only">{{ t('core.settings.required') }}</span>
            </legend>
            <label v-for="(locale, index) in ALL_LOCALES" :key="locale" :class="checkboxLabelClass">
              <input
                :id="index === 0 ? inputId('regional', 'active_locales') : undefined"
                type="checkbox"
                class="accent-primary-on-background size-4"
                :checked="regional.active_locales.includes(locale)"
                :aria-invalid="invalid('regional', 'active_locales')"
                @change="
                  (event: Event) => toggleLocale(locale, (event.target as HTMLInputElement).checked)
                "
              />
              {{ localeName(locale) }}
            </label>
            <p id="settings-regional-active_locales-hint" class="text-muted-foreground text-xs">
              {{ t('core.settings.regional.activeLocalesHint') }}
            </p>
            <FormFieldError
              :id="`${inputId('regional', 'active_locales')}-error`"
              :messages="fieldErrors('regional', 'active_locales')"
            />
            <p v-if="regional.active_locales.length === 0" class="text-destructive text-sm">
              {{ t('core.settings.regional.activeLocalesRequired') }}
            </p>
          </fieldset>

          <div class="flex flex-col gap-1.5">
            <Label :for="inputId('regional', 'default_locale')">
              {{ t('core.settings.regional.defaultLocale') }}
              <span aria-hidden="true">*</span>
              <span class="sr-only">{{ t('core.settings.required') }}</span>
            </Label>
            <select
              :id="inputId('regional', 'default_locale')"
              v-model="regional.default_locale"
              :class="selectClass"
              required
              :aria-invalid="invalid('regional', 'default_locale')"
              :aria-describedby="describedBy('regional', 'default_locale')"
            >
              <option v-if="regional.default_locale === ''" value="" disabled>
                {{ t('core.settings.regional.chooseDefaultLocale') }}
              </option>
              <option v-for="locale in regional.active_locales" :key="locale" :value="locale">
                {{ localeName(locale) }}
              </option>
            </select>
            <p
              v-if="regional.default_locale === '' && regional.active_locales.length > 0"
              class="text-destructive text-sm"
            >
              {{ t('core.settings.regional.defaultLocaleRequired') }}
            </p>
            <FormFieldError
              :id="`${inputId('regional', 'default_locale')}-error`"
              :messages="fieldErrors('regional', 'default_locale')"
            />
          </div>

          <p
            v-if="interfaceLocaleRetired"
            role="status"
            class="border-border rounded-lg border px-3 py-2 text-sm"
          >
            {{ t('core.settings.regional.interfaceLocaleWarning') }}
          </p>

          <div class="flex flex-col gap-1.5">
            <Label :for="`${inputId('regional', 'timezone')}-search`">
              {{ t('core.settings.regional.timezoneSearch') }}
            </Label>
            <input
              :id="`${inputId('regional', 'timezone')}-search`"
              v-model="timezoneQuery"
              type="search"
              autocomplete="off"
              :class="selectClass"
            />
            <Label :for="inputId('regional', 'timezone')">
              {{ t('core.settings.regional.timezone') }}
              <span aria-hidden="true">*</span>
              <span class="sr-only">{{ t('core.settings.required') }}</span>
            </Label>
            <select
              :id="inputId('regional', 'timezone')"
              v-model="regional.timezone"
              :class="selectClass"
              required
              :aria-invalid="invalid('regional', 'timezone')"
              :aria-describedby="describedBy('regional', 'timezone')"
            >
              <option v-for="zone in timezoneOptions" :key="zone" :value="zone">{{ zone }}</option>
            </select>
            <FormFieldError
              :id="`${inputId('regional', 'timezone')}-error`"
              :messages="fieldErrors('regional', 'timezone')"
            />
          </div>

          <SettingsField
            :id="inputId('regional', 'currency')"
            v-model="regional.currency"
            :label="t('core.settings.regional.currency')"
            :hint="t('core.settings.regional.currencyHint')"
            :maxlength="3"
            required
            :errors="fieldErrors('regional', 'currency')"
          />

          <div class="flex flex-col gap-1.5">
            <Label :for="inputId('regional', 'autonomous_community')">
              {{ t('core.settings.regional.autonomousCommunity') }}
            </Label>
            <select
              :id="inputId('regional', 'autonomous_community')"
              v-model="regional.autonomous_community"
              :class="selectClass"
              :aria-invalid="invalid('regional', 'autonomous_community')"
              :aria-describedby="describedBy('regional', 'autonomous_community')"
            >
              <option value="">{{ t('core.settings.regional.autonomousCommunityNone') }}</option>
              <option v-for="code in AUTONOMOUS_COMMUNITIES" :key="code" :value="code">
                {{ communityName(code) }}
              </option>
            </select>
            <FormFieldError
              :id="`${inputId('regional', 'autonomous_community')}-error`"
              :messages="fieldErrors('regional', 'autonomous_community')"
            />
          </div>

          <div class="flex flex-wrap items-center gap-3">
            <Button
              type="submit"
              :disabled="saving.regional || !dirty.regional || regionalBlocker !== null"
            >
              {{ saving.regional ? t('core.settings.saving') : t('core.settings.regional.save') }}
            </Button>
            <p v-if="statusMessage.regional" role="status" class="text-sm">
              {{ statusMessage.regional }}
            </p>
          </div>
        </form>
      </section>

      <!-- Fiscal -->
      <section class="flex flex-col gap-3" aria-labelledby="settings-fiscal-title">
        <h2 id="settings-fiscal-title" class="text-base font-semibold">
          {{ t('core.settings.fiscal.title') }}
        </h2>

        <dl
          v-if="!canEdit"
          class="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-[14rem_1fr]"
        >
          <template v-for="field in FISCAL_FIELDS" :key="field.name">
            <dt class="text-muted-foreground">{{ t(field.labelKey) }}</dt>
            <dd>{{ display(settings.fiscal[field.name]) }}</dd>
          </template>
        </dl>

        <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="save('fiscal')">
          <div
            v-if="generalError.fiscal"
            id="settings-fiscal-summary"
            role="alert"
            tabindex="-1"
            class="border-destructive text-destructive rounded-lg border px-3 py-2 text-sm"
          >
            {{ generalError.fiscal }}
          </div>

          <SettingsField
            v-for="field in FISCAL_FIELDS"
            :id="inputId('fiscal', field.name)"
            :key="field.name"
            v-model="fiscal[field.name]"
            :label="t(field.labelKey)"
            :maxlength="field.maxlength"
            :hint="'hintKey' in field ? t(field.hintKey) : undefined"
            :errors="fieldErrors('fiscal', field.name)"
          />

          <div class="flex flex-wrap items-center gap-3">
            <Button type="submit" :disabled="saving.fiscal || !dirty.fiscal">
              {{ saving.fiscal ? t('core.settings.saving') : t('core.settings.fiscal.save') }}
            </Button>
            <p v-if="statusMessage.fiscal" role="status" class="text-sm">
              {{ statusMessage.fiscal }}
            </p>
          </div>
        </form>
      </section>

      <!-- Paleta -->
      <section class="flex flex-col gap-3" aria-labelledby="settings-palette-title">
        <h2 id="settings-palette-title" class="text-base font-semibold">
          {{ t('core.settings.palette.title') }}
        </h2>

        <dl
          v-if="!canEdit"
          class="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-[14rem_1fr]"
        >
          <dt class="text-muted-foreground">{{ t('core.settings.palette.primary') }}</dt>
          <dd>{{ display(settings.branding.color_primary) }}</dd>
          <dt class="text-muted-foreground">{{ t('core.settings.palette.secondary') }}</dt>
          <dd>{{ display(settings.branding.color_secondary) }}</dd>
        </dl>

        <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="save('palette')">
          <div
            v-if="generalError.palette || fieldErrors('palette', '_')"
            id="settings-palette-summary"
            role="alert"
            tabindex="-1"
            class="border-destructive text-destructive rounded-lg border px-3 py-2 text-sm"
          >
            <p v-if="generalError.palette">{{ generalError.palette }}</p>
            <p v-for="message in fieldErrors('palette', '_') ?? []" :key="message">{{ message }}</p>
            <p v-if="serverContrast">
              {{
                t('core.settings.palette.serverRatio', {
                  ratio: formatRatio(serverContrast.ratio),
                  required: formatRatio(serverContrast.required),
                })
              }}
            </p>
          </div>

          <SettingsField
            :id="inputId('palette', 'color_primary')"
            v-model="palette.color_primary"
            :label="t('core.settings.palette.primary')"
            :hint="t('core.settings.palette.hexHint')"
            :maxlength="7"
            :errors="fieldErrors('palette', 'color_primary')"
          />
          <SettingsField
            :id="inputId('palette', 'color_secondary')"
            v-model="palette.color_secondary"
            :label="t('core.settings.palette.secondary')"
            :hint="t('core.settings.palette.hexHint')"
            :maxlength="7"
            :errors="fieldErrors('palette', 'color_secondary')"
          />

          <div class="flex flex-col gap-2">
            <p class="text-sm font-medium">{{ t('core.settings.palette.preview') }}</p>
            <div
              v-if="paletteRatio !== null"
              class="rounded-lg border px-4 py-3 text-sm font-medium"
              :style="{
                backgroundColor: palette.color_primary.trim(),
                color: palette.color_secondary.trim(),
              }"
            >
              {{ t('core.settings.palette.sample') }}
            </div>
            <p v-if="paletteRatio !== null" class="text-sm">
              {{ t('core.settings.palette.ratio', { ratio: formatRatio(paletteRatio) }) }}
              <span :class="paletteMeetsContrast ? '' : 'text-destructive'">
                {{
                  paletteMeetsContrast
                    ? t('core.settings.palette.meets', { required: formatRatio(REQUIRED_CONTRAST) })
                    : t('core.settings.palette.fails', { required: formatRatio(REQUIRED_CONTRAST) })
                }}
              </span>
            </p>
            <p v-else class="text-muted-foreground text-sm">
              {{ t('core.settings.palette.previewEmpty') }}
            </p>
          </div>

          <div class="flex flex-wrap items-center gap-3">
            <Button type="submit" :disabled="saving.palette || !dirty.palette">
              {{ saving.palette ? t('core.settings.saving') : t('core.settings.palette.save') }}
            </Button>
            <p v-if="statusMessage.palette" role="status" class="text-sm">
              {{ statusMessage.palette }}
            </p>
          </div>
        </form>
      </section>

      <!-- Seguridad (OPEN-CORE-37 = B) -->
      <section
        v-if="settings.security"
        class="flex flex-col gap-3"
        aria-labelledby="settings-security-title"
      >
        <h2 id="settings-security-title" class="text-base font-semibold">
          {{ t('core.settings.security.title') }}
        </h2>

        <dl
          v-if="!canEdit"
          class="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-[14rem_1fr]"
        >
          <dt class="text-muted-foreground">{{ t('core.settings.security.sessionTimeout') }}</dt>
          <dd>
            {{
              t('core.settings.security.minutes', {
                count: settings.security.session_timeout_minutes,
              })
            }}
          </dd>
          <dt class="text-muted-foreground">{{ t('core.settings.security.allowedMethods') }}</dt>
          <dd>
            {{
              settings.security.mfa_allowed_methods
                .map((method) => translated(`core.settings.security.method.${method}`, method))
                .join(', ')
            }}
          </dd>
          <dt class="text-muted-foreground">{{ t('core.settings.security.gracePeriod') }}</dt>
          <dd>
            {{
              t('core.settings.security.days', { count: settings.security.mfa_grace_period_days })
            }}
          </dd>
        </dl>

        <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="save('security')">
          <div
            v-if="generalError.security"
            id="settings-security-summary"
            role="alert"
            tabindex="-1"
            class="border-destructive text-destructive rounded-lg border px-3 py-2 text-sm"
          >
            {{ generalError.security }}
          </div>

          <SettingsField
            :id="inputId('security', 'session_timeout_minutes')"
            v-model="security.session_timeout_minutes"
            :label="t('core.settings.security.sessionTimeout')"
            :hint="t('core.settings.security.sessionTimeoutHint')"
            type="number"
            :min="5"
            :max="480"
            required
            :errors="fieldErrors('security', 'session_timeout_minutes')"
          />

          <fieldset
            class="flex flex-col gap-1"
            :aria-describedby="
              describedBy('security', 'mfa_allowed_methods', 'settings-security-methods-hint')
            "
          >
            <legend class="mb-1 text-sm font-medium">
              {{ t('core.settings.security.allowedMethods') }}
            </legend>
            <label :class="checkboxLabelClass">
              <input type="checkbox" class="accent-primary-on-background size-4" checked disabled />
              {{ t('core.settings.security.method.totp') }}
            </label>
            <label :class="checkboxLabelClass">
              <input
                :id="inputId('security', 'mfa_allowed_methods')"
                v-model="security.email_method"
                type="checkbox"
                class="accent-primary-on-background size-4"
                :aria-invalid="invalid('security', 'mfa_allowed_methods')"
              />
              {{ t('core.settings.security.method.email') }}
            </label>
            <p id="settings-security-methods-hint" class="text-muted-foreground text-xs">
              {{ t('core.settings.security.allowedMethodsHint') }}
            </p>
            <FormFieldError
              :id="`${inputId('security', 'mfa_allowed_methods')}-error`"
              :messages="fieldErrors('security', 'mfa_allowed_methods')"
            />
          </fieldset>

          <SettingsField
            :id="inputId('security', 'mfa_grace_period_days')"
            v-model="security.mfa_grace_period_days"
            :label="t('core.settings.security.gracePeriod')"
            :hint="t('core.settings.security.gracePeriodHint')"
            type="number"
            :min="1"
            :max="90"
            required
            :errors="fieldErrors('security', 'mfa_grace_period_days')"
          />

          <div class="flex flex-wrap items-center gap-3">
            <Button type="submit" :disabled="saving.security || !dirty.security">
              {{ saving.security ? t('core.settings.saving') : t('core.settings.security.save') }}
            </Button>
            <p v-if="statusMessage.security" role="status" class="text-sm">
              {{ statusMessage.security }}
            </p>
          </div>
        </form>

        <p v-if="canOpenMfaAdmin" class="text-sm">
          {{ t('core.settings.security.mfaAdminText') }}
          <RouterLink
            class="text-primary-on-background underline"
            :to="{ name: 'mfa-administration' }"
          >
            {{ t('core.settings.security.mfaAdminLink') }}
          </RouterLink>
        </p>
      </section>
    </template>
  </div>
</template>
