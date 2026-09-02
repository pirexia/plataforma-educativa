<script setup lang="ts">
/**
 * `/administracion/sso/{public_id}` (REQ-AUTH-004, 1.4b, funcional.md
 * §F.9, api.md §F.3-§F.5). Alta y edición. `route.params.publicId ===
 * 'nuevo'` es el alta (sin recurso todavía): el bloque «qué registrar en
 * tu IdP» (`ADR-043 §5.2`) y la gestión de credenciales solo tienen
 * sentido una vez que el proveedor existe, así que aparecen tras
 * guardar por primera vez.
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  createIdentityProvider,
  createIdentityProviderSecret,
  deleteIdentityProviderSecret,
  getIdentityProviderDetail,
  refreshIdentityProviderDiscovery,
  updateIdentityProvider,
} from '../api'
import {
  apiErrorDetail,
  apiErrorStatus,
  apiErrorType,
  fieldErrors,
} from '../composables/formErrors'
import type {
  IdentityProviderDetail,
  SsoClaimsSource,
  SsoEmailClaim,
  SsoProvisioningMode,
} from '../types'

const t = useT()
const route = useRoute()
const router = useRouter()

// El modo se decide por el NOMBRE de la ruta, no por el valor de
// `params.publicId`: la ruta estática `/administracion/sso/nuevo`
// (`sso-administration-new`) no tiene segmento `:publicId` en absoluto
// — `route.params.publicId` es `undefined` ahí, nunca la cadena
// "nuevo" — así que inferirlo desde el parámetro llevaba a tratar el
// alta como una edición de un recurso inexistente (hallazgo propio,
// verificado en navegador real: quedaba en "Cargando…" para siempre).
const publicId = computed(() => {
  if (route.name === 'sso-administration-new') {
    return null
  }

  const raw = route.params.publicId
  return typeof raw === 'string' ? raw : ''
})

const provider = ref<IdentityProviderDetail | null>(null)
const loading = ref(false)
const loadError = ref<string | null>(null)
const saving = ref(false)
const saveError = ref<string | null>(null)
const savedMessage = ref<string | null>(null)

const form = reactive({
  display_name: '',
  discovery_url: '',
  client_id: '',
  email_claim: 'email' as SsoEmailClaim,
  claims_source: 'id_token' as SsoClaimsSource,
  scopes: 'openid, email, profile',
  allowed_email_domains: '',
  provisioning_mode: 'desactivado' as SsoProvisioningMode,
  is_enabled: false,
})

function fillForm(detail: IdentityProviderDetail) {
  form.display_name = detail.display_name
  form.discovery_url = detail.discovery_url
  form.client_id = detail.client_id
  form.email_claim = detail.email_claim
  form.claims_source = detail.claims_source
  form.scopes = detail.scopes.join(', ')
  form.allowed_email_domains = detail.allowed_email_domains.join(', ')
  form.provisioning_mode = detail.provisioning_mode
  form.is_enabled = detail.is_enabled
}

async function load() {
  if (publicId.value === null) {
    return
  }

  loading.value = true
  loadError.value = null

  try {
    provider.value = await getIdentityProviderDetail(publicId.value)
    fillForm(provider.value)
  } catch (err) {
    if (apiErrorStatus(err) === 401) {
      await router.push({ name: 'login' })
      return
    }

    loadError.value = t('auth.ssoAdmin.loadError')
  } finally {
    loading.value = false
  }
}

onMounted(load)

function splitList(value: string): string[] {
  return value
    .split(',')
    .map((item) => item.trim())
    .filter((item) => item.length > 0)
}

async function submit() {
  saving.value = true
  saveError.value = null
  savedMessage.value = null

  const commonPayload = {
    display_name: form.display_name,
    discovery_url: form.discovery_url,
    client_id: form.client_id,
    email_claim: form.email_claim,
    claims_source: form.claims_source,
    scopes: splitList(form.scopes),
    allowed_email_domains: splitList(form.allowed_email_domains),
    provisioning_mode: form.provisioning_mode,
  }

  try {
    if (publicId.value === null) {
      const created = await createIdentityProvider(commonPayload)
      savedMessage.value = t('auth.ssoAdmin.form.created')
      await router.replace({
        name: 'sso-administration-edit',
        params: { publicId: created.public_id },
      })
      provider.value = created
      fillForm(created)
      return
    }

    const updated = await updateIdentityProvider(publicId.value, {
      ...commonPayload,
      is_enabled: form.is_enabled,
    })
    provider.value = updated
    fillForm(updated)
    savedMessage.value = t('auth.ssoAdmin.form.saved')
  } catch (err) {
    const discoveryUrlError = fieldErrors(err, 'discovery_url')[0]

    if (discoveryUrlError) {
      saveError.value = discoveryUrlError
    } else if (apiErrorType(err) === 'conflict') {
      saveError.value = apiErrorDetail(err) ?? t('auth.ssoAdmin.form.genericError')
    } else {
      saveError.value = t('auth.ssoAdmin.form.genericError')
    }
  } finally {
    saving.value = false
  }
}

// -- Credenciales (api.md §F.4) ---------------------------------------------

const newSecret = reactive({ client_secret: '', expires_at: '' })
const secretSaving = ref(false)
const secretError = ref<string | null>(null)
const retiringSecretId = ref<string | null>(null)

async function submitSecret() {
  if (provider.value === null) {
    return
  }

  secretSaving.value = true
  secretError.value = null

  try {
    await createIdentityProviderSecret(provider.value.public_id, {
      client_secret: newSecret.client_secret,
      ...(newSecret.expires_at ? { expires_at: new Date(newSecret.expires_at).toISOString() } : {}),
    })
    newSecret.client_secret = ''
    newSecret.expires_at = ''
    provider.value = await getIdentityProviderDetail(provider.value.public_id)
  } catch {
    secretError.value = t('auth.ssoAdmin.secrets.genericError')
  } finally {
    secretSaving.value = false
  }
}

async function retireSecret(secretPublicId: string) {
  if (provider.value === null) {
    return
  }

  if (!window.confirm(t('auth.ssoAdmin.secrets.retireConfirm'))) {
    return
  }

  retiringSecretId.value = secretPublicId

  try {
    await deleteIdentityProviderSecret(provider.value.public_id, secretPublicId)
    provider.value = await getIdentityProviderDetail(provider.value.public_id)
  } catch {
    secretError.value = t('auth.ssoAdmin.secrets.lastActiveError')
  } finally {
    retiringSecretId.value = null
  }
}

// -- Refresco de descubrimiento (api.md §F.5) --------------------------------

const refreshing = ref(false)
const refreshMessage = ref<string | null>(null)

async function forceRefresh() {
  if (provider.value === null) {
    return
  }

  refreshing.value = true
  refreshMessage.value = null

  try {
    provider.value = await refreshIdentityProviderDiscovery(provider.value.public_id)
    fillForm(provider.value)
    refreshMessage.value = t('auth.ssoAdmin.discoveryRefresh.success')
  } catch {
    refreshMessage.value = t('auth.ssoAdmin.discoveryRefresh.failure')
  } finally {
    refreshing.value = false
  }
}

// -- Copiar al portapapeles --------------------------------------------------

const copiedField = ref<string | null>(null)

async function copy(field: string, value: string) {
  try {
    await navigator.clipboard.writeText(value)
    copiedField.value = field
    setTimeout(() => {
      if (copiedField.value === field) {
        copiedField.value = null
      }
    }, 2000)
  } catch {
    // Sin portapapeles disponible: el valor sigue seleccionable a mano.
  }
}

function formatDate(value: string | null): string | null {
  return value ? new Date(value).toLocaleString() : null
}
</script>

<template>
  <div class="mx-auto flex max-w-3xl flex-col gap-8 px-4 py-10">
    <div>
      <RouterLink :to="{ name: 'sso-administration' }" class="text-primary text-sm hover:underline">
        {{ t('auth.ssoAdmin.back') }}
      </RouterLink>
      <h1 class="mt-2 text-lg font-semibold">
        {{
          publicId === null ? t('auth.ssoAdmin.form.titleNew') : t('auth.ssoAdmin.form.titleEdit')
        }}
      </h1>
    </div>

    <p v-if="loadError" role="alert" class="text-destructive text-sm">{{ loadError }}</p>
    <p v-if="loading" class="text-muted-foreground text-sm">{{ t('auth.ssoAdmin.loading') }}</p>

    <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <div class="flex flex-col gap-1.5">
        <Label for="sso-display-name">{{ t('auth.ssoAdmin.form.displayName') }}</Label>
        <Input id="sso-display-name" v-model="form.display_name" required />
        <p class="text-muted-foreground text-xs">{{ t('auth.ssoAdmin.form.displayNameHint') }}</p>
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="sso-discovery-url">{{ t('auth.ssoAdmin.form.discoveryUrl') }}</Label>
        <Input id="sso-discovery-url" v-model="form.discovery_url" type="url" required />
        <p class="text-muted-foreground text-xs">{{ t('auth.ssoAdmin.form.discoveryUrlHint') }}</p>
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="sso-client-id">{{ t('auth.ssoAdmin.form.clientId') }}</Label>
        <Input id="sso-client-id" v-model="form.client_id" required />
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="sso-email-claim">{{ t('auth.ssoAdmin.form.emailClaim') }}</Label>
        <Select v-model="form.email_claim">
          <SelectTrigger id="sso-email-claim"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="email">{{
              t('auth.ssoAdmin.form.emailClaimOptions.email')
            }}</SelectItem>
            <SelectItem value="preferred_username">{{
              t('auth.ssoAdmin.form.emailClaimOptions.preferred_username')
            }}</SelectItem>
            <SelectItem value="upn">{{ t('auth.ssoAdmin.form.emailClaimOptions.upn') }}</SelectItem>
          </SelectContent>
        </Select>
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="sso-claims-source">{{ t('auth.ssoAdmin.form.claimsSource') }}</Label>
        <Select v-model="form.claims_source">
          <SelectTrigger id="sso-claims-source"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="id_token">{{
              t('auth.ssoAdmin.form.claimsSourceIdToken')
            }}</SelectItem>
            <SelectItem value="userinfo">{{
              t('auth.ssoAdmin.form.claimsSourceUserinfo')
            }}</SelectItem>
          </SelectContent>
        </Select>
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="sso-scopes">{{ t('auth.ssoAdmin.form.scopes') }}</Label>
        <Input id="sso-scopes" v-model="form.scopes" />
        <p class="text-muted-foreground text-xs">{{ t('auth.ssoAdmin.form.scopesHint') }}</p>
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="sso-domains">{{ t('auth.ssoAdmin.form.allowedDomains') }}</Label>
        <Input
          id="sso-domains"
          v-model="form.allowed_email_domains"
          :placeholder="t('auth.ssoAdmin.form.allowedDomainsPlaceholder')"
        />
        <p class="text-muted-foreground text-xs">
          {{ t('auth.ssoAdmin.form.allowedDomainsHint') }}
        </p>
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="sso-provisioning-mode">{{ t('auth.ssoAdmin.form.provisioningMode') }}</Label>
        <Select v-model="form.provisioning_mode">
          <SelectTrigger id="sso-provisioning-mode"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="desactivado">{{
              t('auth.ssoAdmin.provisioningMode.desactivado')
            }}</SelectItem>
            <SelectItem value="emparejamiento">{{
              t('auth.ssoAdmin.provisioningMode.emparejamiento')
            }}</SelectItem>
          </SelectContent>
        </Select>
      </div>

      <!-- funcional.md §F.4.1: is_enabled no se acepta en el alta. -->
      <label v-if="publicId !== null" class="flex items-center gap-2 text-sm">
        <input v-model="form.is_enabled" type="checkbox" />
        {{ t('auth.ssoAdmin.form.isEnabled') }}
      </label>
      <p v-if="publicId !== null" class="text-muted-foreground text-xs">
        {{ t('auth.ssoAdmin.form.isEnabledHint') }}
      </p>

      <p v-if="saveError" role="alert" class="text-destructive text-sm">{{ saveError }}</p>
      <p v-if="savedMessage" class="text-sm text-green-700">{{ savedMessage }}</p>

      <Button type="submit" :disabled="saving" class="w-fit">
        {{ saving ? t('auth.ssoAdmin.form.submitting') : t('auth.ssoAdmin.form.submit') }}
      </Button>
    </form>

    <template v-if="provider !== null">
      <!-- ADR-043 §5.2: qué registrar en el IdP. -->
      <section class="border-border flex flex-col gap-3 rounded-lg border p-4">
        <h2 class="font-medium">{{ t('auth.ssoAdmin.integration.title') }}</h2>
        <p class="text-muted-foreground text-sm">{{ t('auth.ssoAdmin.integration.intro') }}</p>

        <div class="flex items-center justify-between gap-2 text-sm">
          <div>
            <span class="text-muted-foreground"
              >{{ t('auth.ssoAdmin.integration.redirectUri') }}:</span
            >
            <code class="ml-1">{{ provider.integration.redirect_uri }}</code>
          </div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            @click="copy('redirect_uri', provider.integration.redirect_uri)"
          >
            {{
              copiedField === 'redirect_uri'
                ? t('auth.ssoAdmin.integration.copied')
                : t('auth.ssoAdmin.integration.copy')
            }}
          </Button>
        </div>

        <div class="text-sm">
          <span class="text-muted-foreground">{{ t('auth.ssoAdmin.integration.scopes') }}:</span>
          <code class="ml-1">{{ provider.integration.scopes.join(' ') }}</code>
        </div>
        <div class="text-sm">
          <span class="text-muted-foreground"
            >{{ t('auth.ssoAdmin.integration.subjectClaim') }}:</span
          >
          <code class="ml-1">{{ provider.integration.subject_claim }}</code>
        </div>
        <div class="text-sm">
          <span class="text-muted-foreground"
            >{{ t('auth.ssoAdmin.integration.emailClaim') }}:</span
          >
          <code class="ml-1">{{ provider.integration.email_claim }}</code>
        </div>

        <div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            :disabled="refreshing"
            @click="forceRefresh"
          >
            {{
              refreshing
                ? t('auth.ssoAdmin.discoveryRefresh.refreshing')
                : t('auth.ssoAdmin.discoveryRefresh.button')
            }}
          </Button>
          <p v-if="refreshMessage" class="text-muted-foreground mt-1 text-xs">
            {{ refreshMessage }}
          </p>
        </div>
      </section>

      <!-- RN-AUTH-112: nunca se muestra el valor de una credencial. -->
      <section class="border-border flex flex-col gap-3 rounded-lg border p-4">
        <h2 class="font-medium">{{ t('auth.ssoAdmin.secrets.title') }}</h2>
        <p class="text-muted-foreground text-sm">{{ t('auth.ssoAdmin.secrets.intro') }}</p>

        <p v-if="provider.secrets.length === 0" class="text-muted-foreground text-sm">
          {{ t('auth.ssoAdmin.secrets.none') }}
        </p>
        <ul v-else class="flex flex-col gap-2">
          <li
            v-for="secret in provider.secrets"
            :key="secret.public_id"
            class="border-border flex items-center justify-between gap-2 rounded border px-3 py-2 text-sm"
          >
            <div>
              <div>
                {{
                  t('auth.ssoAdmin.secrets.activatedAt', { date: formatDate(secret.activated_at) })
                }}
              </div>
              <div class="text-muted-foreground text-xs">
                {{
                  secret.retired_at
                    ? t('auth.ssoAdmin.secrets.retired', { date: formatDate(secret.retired_at) })
                    : secret.expires_at
                      ? t('auth.ssoAdmin.secrets.expiresAtLabel', {
                          date: formatDate(secret.expires_at),
                        })
                      : t('auth.ssoAdmin.secrets.noExpiry')
                }}
              </div>
            </div>
            <Button
              v-if="!secret.retired_at"
              type="button"
              variant="outline"
              size="sm"
              :disabled="retiringSecretId === secret.public_id"
              @click="retireSecret(secret.public_id)"
            >
              {{ t('auth.ssoAdmin.secrets.retire') }}
            </Button>
          </li>
        </ul>

        <p v-if="secretError" role="alert" class="text-destructive text-sm">{{ secretError }}</p>

        <form
          class="border-border flex flex-col gap-3 border-t pt-3"
          novalidate
          @submit.prevent="submitSecret"
        >
          <div class="flex flex-col gap-1.5">
            <Label for="sso-new-secret">{{ t('auth.ssoAdmin.secrets.clientSecret') }}</Label>
            <Input id="sso-new-secret" v-model="newSecret.client_secret" type="password" required />
          </div>
          <div class="flex flex-col gap-1.5">
            <Label for="sso-new-secret-expires">{{ t('auth.ssoAdmin.secrets.expiresAt') }}</Label>
            <Input id="sso-new-secret-expires" v-model="newSecret.expires_at" type="date" />
            <p class="text-muted-foreground text-xs">
              {{ t('auth.ssoAdmin.secrets.expiresAtHint') }}
            </p>
          </div>
          <Button type="submit" :disabled="secretSaving" class="w-fit">
            {{
              secretSaving
                ? t('auth.ssoAdmin.secrets.submitting')
                : t('auth.ssoAdmin.secrets.submit')
            }}
          </Button>
        </form>
      </section>
    </template>
  </div>
</template>
