<script setup lang="ts">
/**
 * `/administracion/sso/{public_id}` (REQ-AUTH-004, 1.4b, funcional.md
 * §F.9, api.md §F.3-§F.5; ampliada por `1.4c`, funcional.md §G.9,
 * api.md §G.2-§G.5). Alta y edición de los dos protocolos.
 * `route.params.publicId === 'nuevo'` es el alta (sin recurso todavía):
 * el bloque «qué registrar en tu IdP» y la gestión de credenciales o
 * certificados solo tienen sentido una vez que el proveedor existe, así
 * que aparecen tras guardar por primera vez.
 *
 * `protocol` se elige una sola vez, en el alta, y es **inmutable**
 * después (`RN-AUTH-114`, `CA-AUTH-316`, `api.md §G.1`): en edición se
 * muestra como texto, nunca como control — un `PATCH` que lo trajera
 * respondería `422` aunque el valor coincidiera con el actual.
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  createIdentityProvider,
  createIdentityProviderCertificate,
  createIdentityProviderSecret,
  deleteIdentityProviderCertificate,
  deleteIdentityProviderSecret,
  downloadIdentityProviderSpMetadataXml,
  getIdentityProviderDetail,
  getIdentityProviderSpMetadata,
  refreshIdentityProviderDiscovery,
  refreshIdentityProviderMetadata,
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
  IdentityProviderInput,
  SamlSpMetadata,
  SsoClaimsSource,
  SsoEmailClaim,
  SsoProtocol,
  SsoProvisioningMode,
  SsoSamlMetadataSource,
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

// api.md §G.2 punto 1: `protocol` solo se elige en el alta, y solo aquí
// hay un control para él. En edición se toma del recurso cargado y no
// cambia jamás.
const protocol = ref<SsoProtocol>('oidc')

const form = reactive({
  display_name: '',
  allowed_email_domains: '',
  provisioning_mode: 'desactivado' as SsoProvisioningMode,
  is_enabled: false,
})

const oidcForm = reactive({
  discovery_url: '',
  client_id: '',
  email_claim: 'email' as SsoEmailClaim,
  claims_source: 'id_token' as SsoClaimsSource,
  scopes: 'openid, email, profile',
})

const samlForm = reactive({
  metadata_source: 'url' as SsoSamlMetadataSource,
  metadata_url: '',
  metadata_xml: '',
  email_attribute: '',
  sign_authn_requests: false,
})

function fillForm(detail: IdentityProviderDetail) {
  protocol.value = detail.protocol
  form.display_name = detail.display_name
  form.allowed_email_domains = detail.allowed_email_domains.join(', ')
  form.provisioning_mode = detail.provisioning_mode
  form.is_enabled = detail.is_enabled

  if (detail.protocol === 'oidc') {
    oidcForm.discovery_url = detail.discovery_url ?? ''
    oidcForm.client_id = detail.client_id ?? ''
    oidcForm.email_claim = detail.email_claim ?? 'email'
    oidcForm.claims_source = detail.claims_source ?? 'id_token'
    oidcForm.scopes = (detail.scopes ?? []).join(', ')
  } else {
    samlForm.metadata_source = detail.metadata_source ?? 'url'
    samlForm.metadata_url = detail.metadata_url ?? ''
    samlForm.metadata_xml = detail.metadata_xml ?? ''
    samlForm.email_attribute = detail.email_attribute ?? ''
    samlForm.sign_authn_requests = detail.sign_authn_requests ?? false
  }
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
    await loadSpMetadata()
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

/**
 * Los campos propios del protocolo elegido, **sin** `protocol` —
 * la mitad común a un `POST` (donde `protocol` se añade aparte, una vez)
 * y a un `PATCH` (donde `protocol` no va nunca, `RN-AUTH-114`,
 * `CA-AUTH-316`). Separarlo así evita tener que descartar `protocol` de
 * un objeto ya construido en `submit()`.
 */
function buildProtocolFields() {
  const common = {
    display_name: form.display_name,
    allowed_email_domains: splitList(form.allowed_email_domains),
    provisioning_mode: form.provisioning_mode,
  }

  if (protocol.value === 'saml') {
    return {
      ...common,
      ...(samlForm.metadata_source === 'url'
        ? { metadata_url: samlForm.metadata_url }
        : { metadata_xml: samlForm.metadata_xml }),
      ...(samlForm.email_attribute ? { email_attribute: samlForm.email_attribute } : {}),
      sign_authn_requests: samlForm.sign_authn_requests,
    }
  }

  return {
    ...common,
    discovery_url: oidcForm.discovery_url,
    client_id: oidcForm.client_id,
    email_claim: oidcForm.email_claim,
    claims_source: oidcForm.claims_source,
    scopes: splitList(oidcForm.scopes),
  }
}

function buildCreatePayload(): IdentityProviderInput {
  return { protocol: protocol.value, ...buildProtocolFields() } as IdentityProviderInput
}

async function submit() {
  saving.value = true
  saveError.value = null
  savedMessage.value = null

  try {
    if (publicId.value === null) {
      const created = await createIdentityProvider(buildCreatePayload())
      savedMessage.value = t('auth.ssoAdmin.form.created')
      await router.replace({
        name: 'sso-administration-edit',
        params: { publicId: created.public_id },
      })
      provider.value = created
      fillForm(created)
      await loadSpMetadata()
      return
    }

    // `protocol` es inmutable (RN-AUTH-114, CA-AUTH-316): nunca va en un
    // PATCH, ni siquiera con el valor que ya tenía — `buildProtocolFields()`
    // no lo incluye, a diferencia de `buildCreatePayload()`.
    const updated = await updateIdentityProvider(publicId.value, {
      ...buildProtocolFields(),
      is_enabled: form.is_enabled,
    })
    provider.value = updated
    fillForm(updated)
    savedMessage.value = t('auth.ssoAdmin.form.saved')
  } catch (err) {
    const metadataField = protocol.value === 'saml' ? 'metadata_url' : 'discovery_url'
    const metadataError = fieldErrors(err, metadataField)[0]

    if (metadataError) {
      saveError.value = metadataError
    } else if (apiErrorType(err) === 'conflict') {
      saveError.value = apiErrorDetail(err) ?? t('auth.ssoAdmin.form.genericError')
    } else {
      saveError.value = t('auth.ssoAdmin.form.genericError')
    }
  } finally {
    saving.value = false
  }
}

// -- Credenciales OIDC (api.md §F.4) -----------------------------------------

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

// -- Certificados SAML (api.md §G.5) -----------------------------------------

const newCertificate = reactive({ certificate: '' })
const certificateSaving = ref(false)
const certificateError = ref<string | null>(null)
const retiringCertificateId = ref<string | null>(null)

async function submitCertificate() {
  if (provider.value === null) {
    return
  }

  certificateSaving.value = true
  certificateError.value = null

  try {
    await createIdentityProviderCertificate(provider.value.public_id, {
      certificate: newCertificate.certificate,
    })
    newCertificate.certificate = ''
    provider.value = await getIdentityProviderDetail(provider.value.public_id)
  } catch (err) {
    const certError = fieldErrors(err, 'certificate')[0]
    certificateError.value = certError ?? t('auth.ssoAdmin.certificates.genericError')
  } finally {
    certificateSaving.value = false
  }
}

async function retireCertificate(certificatePublicId: string) {
  if (provider.value === null) {
    return
  }

  if (!window.confirm(t('auth.ssoAdmin.certificates.retireConfirm'))) {
    return
  }

  retiringCertificateId.value = certificatePublicId

  try {
    await deleteIdentityProviderCertificate(provider.value.public_id, certificatePublicId)
    provider.value = await getIdentityProviderDetail(provider.value.public_id)
  } catch {
    certificateError.value = t('auth.ssoAdmin.certificates.lastActiveError')
  } finally {
    retiringCertificateId.value = null
  }
}

// -- Refresco de descubrimiento OIDC (api.md §F.5) ---------------------------

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

// -- Refresco de metadatos SAML (api.md §G.4) --------------------------------

const metadataRefreshing = ref(false)
const metadataRefreshMessage = ref<string | null>(null)

async function forceMetadataRefresh() {
  if (provider.value === null) {
    return
  }

  metadataRefreshing.value = true
  metadataRefreshMessage.value = null

  try {
    provider.value = await refreshIdentityProviderMetadata(provider.value.public_id)
    fillForm(provider.value)
    metadataRefreshMessage.value = t('auth.ssoAdmin.metadataRefresh.success')
  } catch {
    metadataRefreshMessage.value = t('auth.ssoAdmin.metadataRefresh.failure')
  } finally {
    metadataRefreshing.value = false
  }
}

// -- Metadatos del SP: qué registrar en el IdP (api.md §G.3) -----------------

const spMetadata = ref<SamlSpMetadata | null>(null)
const spMetadataError = ref<string | null>(null)

async function loadSpMetadata() {
  if (provider.value === null || provider.value.protocol !== 'saml') {
    spMetadata.value = null
    return
  }

  spMetadataError.value = null

  try {
    spMetadata.value = await getIdentityProviderSpMetadata(provider.value.public_id)
  } catch {
    spMetadataError.value = t('auth.ssoAdmin.spMetadata.loadError')
  }
}

const downloadingMetadata = ref(false)

async function downloadSpMetadata() {
  if (provider.value === null) {
    return
  }

  downloadingMetadata.value = true

  try {
    const xml = await downloadIdentityProviderSpMetadataXml(provider.value.public_id)
    const blob = new Blob([xml], { type: 'application/samlmetadata+xml' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `metadatos-sp-${provider.value.public_id}.xml`
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
  } catch {
    spMetadataError.value = t('auth.ssoAdmin.spMetadata.loadError')
  } finally {
    downloadingMetadata.value = false
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

function formatDate(value: string | null | undefined): string | null {
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
      <!-- api.md §G.2 punto 1: protocol solo se elige aquí, y es inmutable
           después. En edición se muestra como texto. -->
      <div class="flex flex-col gap-1.5">
        <Label for="sso-protocol">{{ t('auth.ssoAdmin.form.protocol') }}</Label>
        <Select v-if="publicId === null" v-model="protocol">
          <SelectTrigger id="sso-protocol"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="oidc">{{ t('auth.ssoAdmin.protocolLabel.oidc') }}</SelectItem>
            <SelectItem value="saml">{{ t('auth.ssoAdmin.protocolLabel.saml') }}</SelectItem>
          </SelectContent>
        </Select>
        <p v-else id="sso-protocol" class="text-sm">
          {{ t(`auth.ssoAdmin.protocolLabel.${protocol}`) }}
        </p>
      </div>

      <div class="flex flex-col gap-1.5">
        <Label for="sso-display-name">{{ t('auth.ssoAdmin.form.displayName') }}</Label>
        <Input id="sso-display-name" v-model="form.display_name" required />
        <p class="text-muted-foreground text-xs">{{ t('auth.ssoAdmin.form.displayNameHint') }}</p>
      </div>

      <!-- Campos OIDC (api.md §F.3) -->
      <template v-if="protocol === 'oidc'">
        <div class="flex flex-col gap-1.5">
          <Label for="sso-discovery-url">{{ t('auth.ssoAdmin.form.discoveryUrl') }}</Label>
          <Input id="sso-discovery-url" v-model="oidcForm.discovery_url" type="url" required />
          <p class="text-muted-foreground text-xs">
            {{ t('auth.ssoAdmin.form.discoveryUrlHint') }}
          </p>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="sso-client-id">{{ t('auth.ssoAdmin.form.clientId') }}</Label>
          <Input id="sso-client-id" v-model="oidcForm.client_id" required />
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="sso-email-claim">{{ t('auth.ssoAdmin.form.emailClaim') }}</Label>
          <Select v-model="oidcForm.email_claim">
            <SelectTrigger id="sso-email-claim"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="email">{{
                t('auth.ssoAdmin.form.emailClaimOptions.email')
              }}</SelectItem>
              <SelectItem value="preferred_username">{{
                t('auth.ssoAdmin.form.emailClaimOptions.preferred_username')
              }}</SelectItem>
              <SelectItem value="upn">{{
                t('auth.ssoAdmin.form.emailClaimOptions.upn')
              }}</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="sso-claims-source">{{ t('auth.ssoAdmin.form.claimsSource') }}</Label>
          <Select v-model="oidcForm.claims_source">
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
          <Input id="sso-scopes" v-model="oidcForm.scopes" />
          <p class="text-muted-foreground text-xs">{{ t('auth.ssoAdmin.form.scopesHint') }}</p>
        </div>
      </template>

      <!-- Campos SAML (api.md §G.2) -->
      <template v-else>
        <div class="flex flex-col gap-1.5">
          <Label for="sso-metadata-source">{{ t('auth.ssoAdmin.form.metadataSource') }}</Label>
          <Select v-model="samlForm.metadata_source">
            <SelectTrigger id="sso-metadata-source"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="url">{{ t('auth.ssoAdmin.form.metadataSourceUrl') }}</SelectItem>
              <SelectItem value="xml">{{ t('auth.ssoAdmin.form.metadataSourceXml') }}</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div v-if="samlForm.metadata_source === 'url'" class="flex flex-col gap-1.5">
          <Label for="sso-metadata-url">{{ t('auth.ssoAdmin.form.metadataUrl') }}</Label>
          <Input id="sso-metadata-url" v-model="samlForm.metadata_url" type="url" required />
          <p class="text-muted-foreground text-xs">{{ t('auth.ssoAdmin.form.metadataUrlHint') }}</p>
        </div>
        <div v-else class="flex flex-col gap-1.5">
          <Label for="sso-metadata-xml">{{ t('auth.ssoAdmin.form.metadataXml') }}</Label>
          <Textarea
            id="sso-metadata-xml"
            v-model="samlForm.metadata_xml"
            required
            class="font-mono"
          />
          <p class="text-muted-foreground text-xs">{{ t('auth.ssoAdmin.form.metadataXmlHint') }}</p>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="sso-email-attribute">{{ t('auth.ssoAdmin.form.emailAttribute') }}</Label>
          <Input id="sso-email-attribute" v-model="samlForm.email_attribute" />
          <p class="text-muted-foreground text-xs">
            {{ t('auth.ssoAdmin.form.emailAttributeHint') }}
          </p>
        </div>

        <label class="flex items-center gap-2 text-sm">
          <input v-model="samlForm.sign_authn_requests" type="checkbox" />
          {{ t('auth.ssoAdmin.form.signAuthnRequests') }}
        </label>
        <p class="text-muted-foreground text-xs">
          {{ t('auth.ssoAdmin.form.signAuthnRequestsHint') }}
        </p>

        <p
          v-if="provider !== null && provider.protocol === 'saml'"
          class="text-muted-foreground text-xs"
        >
          {{ t('auth.ssoAdmin.form.nameIdFormatLabel', { format: provider.name_id_format }) }}
        </p>
      </template>

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

    <template v-if="provider !== null && provider.protocol === 'oidc'">
      <!-- ADR-043 §5.2: qué registrar en el IdP. -->
      <section class="border-border flex flex-col gap-3 rounded-lg border p-4">
        <h2 class="font-medium">{{ t('auth.ssoAdmin.integration.title') }}</h2>
        <p class="text-muted-foreground text-sm">{{ t('auth.ssoAdmin.integration.intro') }}</p>

        <div class="flex items-center justify-between gap-2 text-sm">
          <div>
            <span class="text-muted-foreground"
              >{{ t('auth.ssoAdmin.integration.redirectUri') }}:</span
            >
            <code class="ml-1">{{ provider.integration?.redirect_uri }}</code>
          </div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            @click="copy('redirect_uri', provider.integration?.redirect_uri ?? '')"
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
          <code class="ml-1">{{ provider.integration?.scopes.join(' ') }}</code>
        </div>
        <div class="text-sm">
          <span class="text-muted-foreground"
            >{{ t('auth.ssoAdmin.integration.subjectClaim') }}:</span
          >
          <code class="ml-1">{{ provider.integration?.subject_claim }}</code>
        </div>
        <div class="text-sm">
          <span class="text-muted-foreground"
            >{{ t('auth.ssoAdmin.integration.emailClaim') }}:</span
          >
          <code class="ml-1">{{ provider.integration?.email_claim }}</code>
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

        <p v-if="(provider.secrets ?? []).length === 0" class="text-muted-foreground text-sm">
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

    <template v-else-if="provider !== null && provider.protocol === 'saml'">
      <!-- ADR-043 §5.2, api.md §G.3: qué registrar en el IdP. Este
           endpoint no es anónimo (§G.3.1): los valores solo se cargan
           con sesión de administrador, igual que el resto de la
           pantalla. -->
      <section class="border-border flex flex-col gap-3 rounded-lg border p-4">
        <h2 class="font-medium">{{ t('auth.ssoAdmin.spMetadata.title') }}</h2>
        <p class="text-muted-foreground text-sm">{{ t('auth.ssoAdmin.spMetadata.intro') }}</p>

        <p v-if="spMetadataError" role="alert" class="text-destructive text-sm">
          {{ spMetadataError }}
        </p>

        <template v-if="spMetadata !== null">
          <div class="flex items-center justify-between gap-2 text-sm">
            <div>
              <span class="text-muted-foreground"
                >{{ t('auth.ssoAdmin.spMetadata.entityId') }}:</span
              >
              <code class="ml-1">{{ spMetadata.entity_id }}</code>
            </div>
            <Button
              type="button"
              variant="outline"
              size="sm"
              @click="copy('entity_id', spMetadata.entity_id)"
            >
              {{
                copiedField === 'entity_id'
                  ? t('auth.ssoAdmin.integration.copied')
                  : t('auth.ssoAdmin.integration.copy')
              }}
            </Button>
          </div>

          <div class="flex items-center justify-between gap-2 text-sm">
            <div>
              <span class="text-muted-foreground">{{ t('auth.ssoAdmin.spMetadata.acsUrl') }}:</span>
              <code class="ml-1">{{ spMetadata.assertion_consumer_service_url }}</code>
            </div>
            <Button
              type="button"
              variant="outline"
              size="sm"
              @click="copy('acs_url', spMetadata.assertion_consumer_service_url)"
            >
              {{
                copiedField === 'acs_url'
                  ? t('auth.ssoAdmin.integration.copied')
                  : t('auth.ssoAdmin.integration.copy')
              }}
            </Button>
          </div>

          <div class="text-sm">
            <span class="text-muted-foreground"
              >{{ t('auth.ssoAdmin.spMetadata.nameIdFormat') }}:</span
            >
            <code class="ml-1">{{ spMetadata.name_id_format }}</code>
          </div>

          <div
            v-if="spMetadata.certificate"
            class="flex items-center justify-between gap-2 text-sm"
          >
            <span class="text-muted-foreground">{{
              t('auth.ssoAdmin.spMetadata.certificate')
            }}</span>
            <Button
              type="button"
              variant="outline"
              size="sm"
              @click="copy('sp_certificate', spMetadata.certificate)"
            >
              {{
                copiedField === 'sp_certificate'
                  ? t('auth.ssoAdmin.integration.copied')
                  : t('auth.ssoAdmin.integration.copy')
              }}
            </Button>
          </div>

          <div>
            <Button
              type="button"
              variant="outline"
              size="sm"
              :disabled="downloadingMetadata"
              @click="downloadSpMetadata"
            >
              {{ t('auth.ssoAdmin.spMetadata.download') }}
            </Button>
          </div>
        </template>

        <div v-if="samlForm.metadata_source === 'url'">
          <Button
            type="button"
            variant="outline"
            size="sm"
            :disabled="metadataRefreshing"
            @click="forceMetadataRefresh"
          >
            {{
              metadataRefreshing
                ? t('auth.ssoAdmin.metadataRefresh.refreshing')
                : t('auth.ssoAdmin.metadataRefresh.button')
            }}
          </Button>
          <p v-if="metadataRefreshMessage" class="text-muted-foreground mt-1 text-xs">
            {{ metadataRefreshMessage }}
          </p>
        </div>
      </section>

      <!-- api.md §G.5: los certificados de firma del IdP. -->
      <section class="border-border flex flex-col gap-3 rounded-lg border p-4">
        <h2 class="font-medium">{{ t('auth.ssoAdmin.certificates.title') }}</h2>
        <p class="text-muted-foreground text-sm">{{ t('auth.ssoAdmin.certificates.intro') }}</p>

        <p v-if="(provider.certificates ?? []).length === 0" class="text-muted-foreground text-sm">
          {{ t('auth.ssoAdmin.certificates.none') }}
        </p>
        <ul v-else class="flex flex-col gap-2">
          <li
            v-for="certificate in provider.certificates"
            :key="certificate.public_id"
            class="border-border flex items-center justify-between gap-2 rounded border px-3 py-2 text-sm"
          >
            <div>
              <div class="font-mono text-xs">{{ certificate.fingerprint_sha256 }}</div>
              <div class="text-muted-foreground text-xs">
                {{
                  certificate.retired_at
                    ? t('auth.ssoAdmin.certificates.retired', {
                        date: formatDate(certificate.retired_at),
                      })
                    : t('auth.ssoAdmin.certificates.validUntil', {
                        date: formatDate(certificate.not_after),
                      })
                }}
                · {{ t(`auth.ssoAdmin.certificates.source.${certificate.source}`) }}
              </div>
            </div>
            <Button
              v-if="!certificate.retired_at"
              type="button"
              variant="outline"
              size="sm"
              :disabled="retiringCertificateId === certificate.public_id"
              @click="retireCertificate(certificate.public_id)"
            >
              {{ t('auth.ssoAdmin.certificates.retire') }}
            </Button>
          </li>
        </ul>

        <p v-if="certificateError" role="alert" class="text-destructive text-sm">
          {{ certificateError }}
        </p>

        <!-- funcional.md §G.9: retirar un certificado no lo revoca en el IdP del centro. -->
        <p class="text-muted-foreground text-xs">
          {{ t('auth.ssoAdmin.certificates.retireWarning') }}
        </p>

        <form
          class="border-border flex flex-col gap-3 border-t pt-3"
          novalidate
          @submit.prevent="submitCertificate"
        >
          <div class="flex flex-col gap-1.5">
            <Label for="sso-new-certificate">{{
              t('auth.ssoAdmin.certificates.certificate')
            }}</Label>
            <Textarea
              id="sso-new-certificate"
              v-model="newCertificate.certificate"
              class="font-mono"
              required
            />
            <p class="text-muted-foreground text-xs">
              {{ t('auth.ssoAdmin.certificates.certificateHint') }}
            </p>
          </div>
          <Button type="submit" :disabled="certificateSaving" class="w-fit">
            {{
              certificateSaving
                ? t('auth.ssoAdmin.certificates.submitting')
                : t('auth.ssoAdmin.certificates.submit')
            }}
          </Button>
        </form>
      </section>
    </template>
  </div>
</template>
