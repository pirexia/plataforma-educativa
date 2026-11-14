<script setup lang="ts">
/**
 * `/administracion/centro/marca` (`docs/modulos/REQ-CORE/funcional.md §14.10`,
 * `RN-CORE-83`, `RN-CORE-64`, 1.9e). Tres bloques (logo, *favicon*, fondo de
 * acceso), cada uno con la imagen ya guardada (URL firmada de
 * `GET /tenant/settings`), sus tipos y tamaños admitidos y, con
 * `configuracion.actualizar`, «Sustituir» y «Eliminar».
 *
 * - **Las comprobaciones de tipo y tamaño en cliente son comodidad**: el
 *   servidor decide por **contenido** (`413`, `415`, `422`, `RN-CORE-18`) y la
 *   vista muestra su mensaje. La SPA **nunca** sanea ni inspecciona un SVG
 *   (`§4.2`) y **no hace vista previa local** del fichero elegido
 *   (`URL.createObjectURL`, vetado por `CA-CORE-192`): la vista previa es la
 *   del activo ya guardado.
 * - Tras sustituir o eliminar se vuelve a pedir `GET /tenant/settings` y se
 *   llama a `useTenantBranding().refresh()` (logo del *shell*, *favicon*).
 * - Si una URL firmada falla al cargar (caducada), la vista vuelve a pedir la
 *   configuración **una sola vez**; si vuelve a fallar, estado de error sin
 *   bucle (`CA-CORE-254`).
 * - Eliminar pide confirmación (`RN-CORE-64`).
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useConfirm } from '@/components/useConfirm'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import { resolveErrorState, type ShellErrorState } from '@/layouts/errorState'
import { useTenantBranding } from '@/tenant/useTenantBranding'
import { deleteTenantSettingsAsset, getTenantSettings, putTenantSettingsAsset } from '../api'
import {
  problemDetail,
  problemFieldErrors,
  problemRetryAfter,
  problemStatus,
} from '../composables/problem'
import { usePermissions } from '../composables/usePermissions'
import type { BrandingAssetKind, TenantSettings } from '../types'

interface KindConfig {
  kind: BrandingAssetKind
  urlField: 'logo_url' | 'favicon_url' | 'login_background_url'
  /** `api.md §2`: tamaño máximo en bytes y tipos admitidos (comodidad de cliente). */
  maxBytes: number
  types: readonly string[]
  accept: string
}

const KINDS: readonly KindConfig[] = [
  {
    kind: 'logo',
    urlField: 'logo_url',
    maxBytes: 1024 * 1024,
    types: ['image/svg+xml', 'image/png', 'image/webp'],
    accept: '.svg,.png,.webp,image/svg+xml,image/png,image/webp',
  },
  {
    kind: 'favicon',
    urlField: 'favicon_url',
    maxBytes: 256 * 1024,
    types: ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/svg+xml'],
    accept: '.png,.ico,.svg,image/png,image/x-icon,image/svg+xml',
  },
  {
    kind: 'login-background',
    urlField: 'login_background_url',
    maxBytes: 3 * 1024 * 1024,
    types: ['image/jpeg', 'image/png', 'image/webp'],
    accept: '.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp',
  },
]

/** Clave de traducción por tipo (`login-background` → `loginBackground`). */
const KEY: Record<BrandingAssetKind, string> = {
  logo: 'logo',
  favicon: 'favicon',
  'login-background': 'loginBackground',
}

const t = useT()
const { can } = usePermissions()
const { branding, refresh } = useTenantBranding()
const confirmation = useConfirm()

const canEdit = computed(() => can('configuracion.actualizar'))
const centerName = computed(() => branding.value?.name ?? '')

const settings = ref<TenantSettings | null>(null)
const loading = ref(true)
const loadError = ref<ShellErrorState | null>(null)

const busy = reactive<Record<BrandingAssetKind, boolean>>({
  logo: false,
  favicon: false,
  'login-background': false,
})
const errorMessage = reactive<Record<BrandingAssetKind, string | null>>({
  logo: null,
  favicon: null,
  'login-background': null,
})
const statusMessage = reactive<Record<BrandingAssetKind, string | null>>({
  logo: null,
  favicon: null,
  'login-background': null,
})
/** Imagen que no ha cargado ni tras volver a pedir la configuración (`CA-CORE-254`). */
const imageFailed = reactive<Record<BrandingAssetKind, boolean>>({
  logo: false,
  favicon: false,
  'login-background': false,
})

const fileInputs = reactive<Partial<Record<BrandingAssetKind, HTMLInputElement | null>>>({})

let reloadedForImage = false
let reloading = false

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null

  try {
    settings.value = await getTenantSettings()
  } catch (err) {
    loadError.value = resolveErrorState(err)
  } finally {
    loading.value = false
  }
}

onMounted(load)

function urlOf(config: KindConfig): string | null {
  return settings.value?.branding[config.urlField] ?? null
}

/**
 * La URL firmada ha caducado (o el fallo es otro): se vuelve a pedir la
 * configuración **una sola vez**; los errores de carga que lleguen mientras
 * esa petición está en vuelo son de las URL viejas y se ignoran.
 */
async function onImageError(kind: BrandingAssetKind): Promise<void> {
  if (reloading) {
    return
  }

  if (reloadedForImage) {
    imageFailed[kind] = true

    return
  }

  reloadedForImage = true
  reloading = true

  try {
    settings.value = await getTenantSettings()
  } catch {
    imageFailed[kind] = true
  } finally {
    reloading = false
  }
}

function fail(kind: BrandingAssetKind, err: unknown): void {
  const status = problemStatus(err)

  if (status === 429) {
    const seconds = problemRetryAfter(err)

    errorMessage[kind] =
      seconds !== null
        ? t('core.branding.errors.tooManyRequestsWithSeconds', { seconds })
        : t('core.branding.errors.tooManyRequests')

    return
  }

  const fileMessage = problemFieldErrors(err).file?.[0]
  const fallback =
    status === 413
      ? t('core.branding.errors.tooLarge')
      : status === 415
        ? t('core.branding.errors.unsupportedType')
        : status === 422
          ? t('core.branding.errors.invalid')
          : t('core.branding.errors.unexpected')

  errorMessage[kind] = fileMessage ?? problemDetail(err) ?? fallback
}

async function afterChange(kind: BrandingAssetKind, messageKey: string): Promise<void> {
  imageFailed[kind] = false
  reloadedForImage = false

  settings.value = await getTenantSettings()
  statusMessage[kind] = t(messageKey)
  void refresh()
}

function chooseFile(kind: BrandingAssetKind): void {
  fileInputs[kind]?.click()
}

async function onFileChosen(config: KindConfig, event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]

  // El mismo fichero se puede volver a elegir tras un error.
  const reset = (): void => {
    input.value = ''
  }

  errorMessage[config.kind] = null
  statusMessage[config.kind] = null

  if (!file) {
    return
  }

  // Comodidad de cliente (RN-CORE-83): el servidor decide por contenido.
  if (file.size > config.maxBytes) {
    errorMessage[config.kind] = t(`core.branding.kinds.${KEY[config.kind]}.tooLarge`)
    reset()

    return
  }

  if (file.type !== '' && !config.types.includes(file.type)) {
    errorMessage[config.kind] = t(`core.branding.kinds.${KEY[config.kind]}.wrongType`)
    reset()

    return
  }

  busy[config.kind] = true

  try {
    await putTenantSettingsAsset(config.kind, file)
    await afterChange(config.kind, 'core.branding.saved')
  } catch (err) {
    fail(config.kind, err)
  } finally {
    busy[config.kind] = false
    reset()
  }
}

async function remove(config: KindConfig): Promise<void> {
  const key = KEY[config.kind]

  errorMessage[config.kind] = null
  statusMessage[config.kind] = null

  const confirmed = await confirmation.ask({
    title: t(`core.branding.kinds.${key}.confirmTitle`),
    description: t(`core.branding.kinds.${key}.confirmDescription`),
    confirmLabel: t(`core.branding.kinds.${key}.confirmButton`, { center: centerName.value }),
    destructive: true,
  })

  if (!confirmed) {
    return
  }

  busy[config.kind] = true

  try {
    await deleteTenantSettingsAsset(config.kind)
    await afterChange(config.kind, 'core.branding.removed')
  } catch (err) {
    fail(config.kind, err)
  } finally {
    busy[config.kind] = false
  }
}
</script>

<template>
  <div class="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-6">
    <div>
      <h1 class="text-lg font-semibold">{{ t('core.branding.title') }}</h1>
      <p class="text-muted-foreground text-sm">
        {{ canEdit ? t('core.branding.intro') : t('core.branding.introReadOnly') }}
      </p>
    </div>

    <LoadingState v-if="loading" />
    <ErrorState v-else-if="loadError" :state="loadError" @retry="load" />

    <template v-else-if="settings">
      <section
        v-for="config in KINDS"
        :key="config.kind"
        class="flex flex-col gap-3"
        :aria-labelledby="`branding-${config.kind}-title`"
      >
        <h2 :id="`branding-${config.kind}-title`" class="text-base font-semibold">
          {{ t(`core.branding.kinds.${KEY[config.kind]}.name`) }}
        </h2>
        <p class="text-muted-foreground text-sm">
          {{ t(`core.branding.kinds.${KEY[config.kind]}.limits`) }}
        </p>

        <div class="border-border flex min-h-24 items-center justify-center rounded-lg border p-3">
          <p v-if="imageFailed[config.kind]" role="alert" class="text-destructive text-sm">
            {{ t('core.branding.imageError') }}
          </p>
          <img
            v-else-if="urlOf(config)"
            :src="urlOf(config) ?? undefined"
            :alt="
              config.kind === 'login-background'
                ? ''
                : t(`core.branding.kinds.${KEY[config.kind]}.alt`, { center: centerName })
            "
            class="max-h-40 max-w-full object-contain"
            @error="onImageError(config.kind)"
          />
          <p v-else class="text-muted-foreground text-sm">
            {{ t(`core.branding.kinds.${KEY[config.kind]}.none`) }}
          </p>
        </div>

        <div v-if="canEdit" class="flex flex-wrap gap-2">
          <input
            :ref="(el) => (fileInputs[config.kind] = el as HTMLInputElement | null)"
            :data-testid="`branding-file-${config.kind}`"
            type="file"
            class="hidden"
            :accept="config.accept"
            @change="onFileChosen(config, $event)"
          />
          <Button
            type="button"
            variant="outline"
            :disabled="busy[config.kind]"
            @click="chooseFile(config.kind)"
          >
            {{ t(`core.branding.kinds.${KEY[config.kind]}.replace`) }}
          </Button>
          <Button
            v-if="urlOf(config)"
            type="button"
            variant="outline"
            :disabled="busy[config.kind]"
            @click="remove(config)"
          >
            {{ t(`core.branding.kinds.${KEY[config.kind]}.remove`) }}
          </Button>
        </div>

        <p v-if="errorMessage[config.kind]" role="alert" class="text-destructive text-sm">
          {{ errorMessage[config.kind] }}
        </p>
        <p v-if="statusMessage[config.kind]" role="status" class="text-sm">
          {{ statusMessage[config.kind] }}
        </p>
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
