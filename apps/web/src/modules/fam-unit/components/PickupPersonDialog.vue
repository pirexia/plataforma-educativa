<script setup lang="ts">
/**
 * `funcional.md §23.4.2` (filas «Ver ficha de la persona» y «Corregir datos»), `D-66`, `RN-FAM-UNIT-89`/`-90`. La ficha
 * se abre solo al pulsar «Ver los datos de {persona}» (una petición `GET /pickup-persons/{p}`, **auditada**) y es el
 * único sitio con el documento **completo**, de solo lectura y que no se copia a avisos ni a `document.title`.
 * `PATCH` corrige nombre, apellidos y teléfono (al menos uno; el documento no se edita). `404` al corregir ⇒
 * `person.notCorrectable` sin distinguir la causa (`api.md §8.2`). Nada se persiste en el navegador (UI-3).
 *
 * La API no devuelve el nombre dividido, solo `display_name`: los campos de nombre empiezan vacíos y solo se envía lo que
 * se rellena.
 */
import { computed, ref, watch } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { getPickupPerson, updatePickupPerson } from '../api'
import { problemDetail, problemFieldErrors, problemStatus } from '../composables/problem'
import { useNtLang } from '../composables/useNtLang'
import type { PickupPerson, UpdatePickupPersonBody } from '../types'
import LoadingState from '@/layouts/components/LoadingState.vue'

const props = defineProps<{ open: boolean; personPublicId: string }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const t = useT()
const ntLang = useNtLang()

const person = ref<PickupPerson | null>(null)
const loading = ref(false)
const loadError = ref<string | null>(null)
const busy = ref(false)
const givenName = ref('')
const familyName1 = ref('')
const familyName2 = ref('')
const phone = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  person.value = null

  try {
    person.value = await getPickupPerson(props.personPublicId)
    phone.value = person.value.contact_phone ?? ''
  } catch (err) {
    loadError.value =
      problemStatus(err) === 404
        ? t('famUnit.pickup.person.unavailable')
        : (problemDetail(err) ?? t('famUnit.errors.unexpected'))
  } finally {
    loading.value = false
  }
}

watch(
  () => props.open,
  (open) => {
    if (open) {
      givenName.value = ''
      familyName1.value = ''
      familyName2.value = ''
      phone.value = ''
      fieldErrors.value = {}
      formError.value = null
      void load()
    } else {
      person.value = null
      phone.value = ''
    }
  },
  { immediate: true },
)

const documentText = computed(() => {
  const doc = person.value?.document

  if (!doc) {
    return ''
  }

  const key = `core.person.documentType.${doc.type}`
  const label = t(key)

  return `${label === key ? doc.type : label} ${doc.number}`
})

function errorsOf(field: string): string[] {
  return fieldErrors.value[field] ?? []
}

async function submit(): Promise<void> {
  fieldErrors.value = {}
  formError.value = null

  const body: UpdatePickupPersonBody = {}

  if (givenName.value.trim() !== '') body.given_name = givenName.value.trim()
  if (familyName1.value.trim() !== '') body.family_name_1 = familyName1.value.trim()
  if (familyName2.value.trim() !== '') body.family_name_2 = familyName2.value.trim()
  if (phone.value.trim() !== (person.value?.contact_phone ?? ''))
    body.contact_phone = phone.value.trim()

  if (Object.keys(body).length === 0) {
    formError.value = t('famUnit.pickup.person.nothingToSave')

    return
  }

  busy.value = true

  try {
    await updatePickupPerson(props.personPublicId, body)
    emit('saved')
    emit('update:open', false)
  } catch (err) {
    if (problemStatus(err) === 404) {
      formError.value = t('famUnit.pickup.person.notCorrectable')
    } else {
      fieldErrors.value = problemFieldErrors(err)
      formError.value =
        problemDetail(err) ??
        (Object.keys(fieldErrors.value).length === 0 ? t('famUnit.errors.unexpected') : null)
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent :close-label="t('famUnit.form.close')" data-slot="fam-unit-pickup-person">
      <DialogHeader>
        <DialogTitle>
          {{ t('famUnit.pickup.person.title', { person: person?.display_name ?? '' }) }}
        </DialogTitle>
        <DialogDescription>
          <span :lang="ntLang">{{ t('famUnit.pickup.list.auditNotice') }}</span>
        </DialogDescription>
      </DialogHeader>

      <LoadingState v-if="loading" />
      <p v-else-if="loadError" role="alert" class="text-destructive text-sm">{{ loadError }}</p>

      <form v-else-if="person" class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="flex flex-col gap-1.5">
          <Label for="fam-unit-pickup-person-document">{{
            t('famUnit.pickup.person.documentNumber')
          }}</Label>
          <Input
            id="fam-unit-pickup-person-document"
            :model-value="documentText"
            readonly
            aria-describedby="fam-unit-pickup-person-document-hint"
          />
          <p id="fam-unit-pickup-person-document-hint" class="text-muted-foreground text-xs">
            {{ t('famUnit.pickup.person.documentReadOnly') }}
          </p>
        </div>

        <p class="text-muted-foreground text-xs">{{ t('famUnit.pickup.person.correctHint') }}</p>

        <div class="flex flex-col gap-1.5">
          <Label for="fam-unit-pickup-person-given">{{
            t('famUnit.pickup.person.givenName')
          }}</Label>
          <Input
            id="fam-unit-pickup-person-given"
            v-model="givenName"
            :aria-invalid="errorsOf('given_name').length ? 'true' : undefined"
          />
          <ul v-if="errorsOf('given_name').length" role="alert" class="text-destructive text-xs">
            <li v-for="m in errorsOf('given_name')" :key="m">{{ m }}</li>
          </ul>
        </div>
        <div class="flex flex-col gap-1.5">
          <Label for="fam-unit-pickup-person-family1">{{
            t('famUnit.pickup.person.familyName1')
          }}</Label>
          <Input
            id="fam-unit-pickup-person-family1"
            v-model="familyName1"
            :aria-invalid="errorsOf('family_name_1').length ? 'true' : undefined"
          />
          <ul v-if="errorsOf('family_name_1').length" role="alert" class="text-destructive text-xs">
            <li v-for="m in errorsOf('family_name_1')" :key="m">{{ m }}</li>
          </ul>
        </div>
        <div class="flex flex-col gap-1.5">
          <Label for="fam-unit-pickup-person-family2">{{
            t('famUnit.pickup.person.familyName2')
          }}</Label>
          <Input
            id="fam-unit-pickup-person-family2"
            v-model="familyName2"
            :aria-invalid="errorsOf('family_name_2').length ? 'true' : undefined"
          />
          <ul v-if="errorsOf('family_name_2').length" role="alert" class="text-destructive text-xs">
            <li v-for="m in errorsOf('family_name_2')" :key="m">{{ m }}</li>
          </ul>
        </div>
        <div class="flex flex-col gap-1.5">
          <Label for="fam-unit-pickup-person-phone">{{ t('famUnit.pickup.person.phone') }}</Label>
          <Input
            id="fam-unit-pickup-person-phone"
            v-model="phone"
            type="tel"
            :aria-invalid="errorsOf('contact_phone').length ? 'true' : undefined"
          />
          <ul v-if="errorsOf('contact_phone').length" role="alert" class="text-destructive text-xs">
            <li v-for="m in errorsOf('contact_phone')" :key="m">{{ m }}</li>
          </ul>
        </div>

        <p v-if="formError" role="alert" class="text-destructive text-sm">{{ formError }}</p>

        <DialogFooter>
          <Button type="button" variant="outline" @click="emit('update:open', false)">
            {{ t('famUnit.form.cancel') }}
          </Button>
          <Button type="submit" :disabled="busy">{{ t('famUnit.form.save') }}</Button>
        </DialogFooter>
      </form>

      <DialogFooter v-else>
        <Button type="button" variant="outline" @click="emit('update:open', false)">
          {{ t('famUnit.form.close') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
