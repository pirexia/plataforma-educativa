<script setup lang="ts">
/**
 * `funcional.md §23.4.3` punto 1 (`OPEN-FAM-UNIT-48`, `D-52`, `RN-FAM-UNIT-89`). Persona: tipo y número de documento
 * (obligatorios en el diálogo, `OPEN-FAM-UNIT-47`). Con `autorizado_recogida.actualizar`, «Buscar» llama a `GET
 * /pickup-persons?…` (auditada y con cupo, solo por acción explícita): con coincidencia se ofrece «Usar esta persona»;
 * sin ella, el alta (`POST /pickup-persons`). Sin `.actualizar`, directo al alta. Una persona reutilizada (`200
 * reused`) avisa y no cambia sus datos; con `data_mismatch`, aviso adicional. Lo escrito se conserva entre pasos
 * (WCAG 3.3.7) y volver atrás no repite el alta.
 */
import { computed, ref, watch } from 'vue'
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
import { createPickupPerson, findPickupPerson } from '../api'
import { problemDetail, problemFieldErrors } from '../composables/problem'
import type { PickupPerson } from '../types'

const DOCUMENT_TYPES = ['dni', 'nie', 'pasaporte']

const props = defineProps<{ canUpdate: boolean }>()
const emit = defineEmits<{ use: [person: PickupPerson] }>()

const t = useT()

const documentType = ref('dni')
const documentNumber = ref('')
const givenName = ref('')
const familyName1 = ref('')
const familyName2 = ref('')
const phone = ref('')

const mode = ref<'lookup' | 'found' | 'create'>(props.canUpdate ? 'lookup' : 'create')
const found = ref<PickupPerson | null>(null)
/** Clave (tipo + número) con la que se buscó `found`: una coincidencia solo vale para esa clave. */
const foundKey = ref<string | null>(null)
const resolved = ref<{ key: string; person: PickupPerson } | null>(null)
const reused = ref(false)
const mismatch = ref(false)
const busy = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)

const key = computed(() => `${documentType.value}|${documentNumber.value.trim()}`)

// Cambiar el tipo o el número invalida la búsqueda y los avisos de la persona anterior: hay que buscar de nuevo.
/** Cada petición lleva su número: una respuesta que ya no es la última (o de otra clave) se descarta. */
let requestSeq = 0

watch(key, () => {
  // Lo que esté en vuelo era para otra clave: se descarta al llegar y el paso deja de estar ocupado.
  requestSeq++
  busy.value = false
  found.value = null
  foundKey.value = null
  reused.value = false
  mismatch.value = false
  fieldErrors.value = {}
  formError.value = null

  if (props.canUpdate) {
    mode.value = 'lookup'
  }
})

function typeLabel(code: string): string {
  const k = `core.person.documentType.${code}`
  const text = t(k)

  return text === k ? code : text
}

function errorsOf(field: string): string[] {
  return fieldErrors.value[field] ?? []
}

function fail(err: unknown): void {
  fieldErrors.value = problemFieldErrors(err)
  formError.value =
    problemDetail(err) ??
    (Object.keys(fieldErrors.value).length === 0 ? t('famUnit.errors.unexpected') : null)
}

async function search(): Promise<void> {
  fieldErrors.value = {}
  formError.value = null

  if (documentNumber.value.trim() === '') {
    fieldErrors.value = { document_number: [t('famUnit.pickup.person.documentRequired')] }

    return
  }

  const seq = ++requestSeq
  const searchedKey = key.value
  const type = documentType.value
  const number = documentNumber.value.trim()

  busy.value = true

  try {
    const result = await findPickupPerson(type, number)

    if (seq !== requestSeq || searchedKey !== key.value) return

    found.value = result.data[0] ?? null
    foundKey.value = searchedKey
    mode.value = found.value ? 'found' : 'create'
  } catch (err) {
    if (seq === requestSeq && searchedKey === key.value) fail(err)
  } finally {
    if (seq === requestSeq) busy.value = false
  }
}

/** Devuelve la persona con la que seguir al paso 2, o `null` si el paso no puede avanzar todavía. */
async function submit(): Promise<PickupPerson | null> {
  fieldErrors.value = {}
  formError.value = null

  if (resolved.value && resolved.value.key === key.value) {
    return resolved.value.person
  }

  if (mode.value === 'lookup') {
    await search()

    return null
  }

  if (mode.value === 'found') {
    return foundKey.value === key.value ? found.value : null
  }

  const missing: Record<string, string[]> = {}

  if (documentNumber.value.trim() === '')
    missing.document_number = [t('famUnit.pickup.person.documentRequired')]
  if (givenName.value.trim() === '') missing.given_name = [t('famUnit.pickup.person.nameRequired')]
  if (familyName1.value.trim() === '')
    missing.family_name_1 = [t('famUnit.pickup.person.nameRequired')]

  if (Object.keys(missing).length > 0) {
    fieldErrors.value = missing

    return null
  }

  const seq = ++requestSeq
  const createdKey = key.value

  busy.value = true

  try {
    const person = await createPickupPerson({
      given_name: givenName.value.trim(),
      family_name_1: familyName1.value.trim(),
      family_name_2: familyName2.value.trim() === '' ? null : familyName2.value.trim(),
      document_type: documentType.value,
      document_number: documentNumber.value.trim(),
      contact_phone: phone.value.trim() === '' ? null : phone.value.trim(),
    })

    // La persona ya existe en el servidor para la clave con la que se envió; solo se usa si el formulario sigue en ella.
    resolved.value = { key: createdKey, person }

    if (seq !== requestSeq || createdKey !== key.value) return null

    reused.value = person.reused === true
    mismatch.value = person.data_mismatch === true

    return person
  } catch (err) {
    if (seq === requestSeq && createdKey === key.value) fail(err)

    return null
  } finally {
    if (seq === requestSeq) busy.value = false
  }
}

/** «Usar esta persona»: solo vale si la coincidencia es de la clave que muestra el formulario. */
function use(person: PickupPerson): void {
  if (foundKey.value === key.value) {
    emit('use', person)
  }
}

defineExpose({ submit })
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="grid gap-4 sm:grid-cols-2">
      <div class="flex flex-col gap-1.5">
        <Label for="fam-unit-pickup-doc-type">{{ t('famUnit.pickup.person.documentType') }}</Label>
        <Select v-model="documentType">
          <SelectTrigger id="fam-unit-pickup-doc-type">
            <SelectValue :placeholder="t('famUnit.form.choose')" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem v-for="code in DOCUMENT_TYPES" :key="code" :value="code">
              {{ typeLabel(code) }}
            </SelectItem>
          </SelectContent>
        </Select>
      </div>
      <div class="flex flex-col gap-1.5">
        <Label for="fam-unit-pickup-doc-number">{{
          t('famUnit.pickup.person.documentNumber')
        }}</Label>
        <Input
          id="fam-unit-pickup-doc-number"
          v-model="documentNumber"
          autocomplete="off"
          :aria-invalid="errorsOf('document_number').length ? 'true' : undefined"
          :aria-describedby="
            errorsOf('document_number').length ? 'fam-unit-pickup-doc-number-errors' : undefined
          "
        />
        <ul
          v-if="errorsOf('document_number').length"
          id="fam-unit-pickup-doc-number-errors"
          role="alert"
          class="text-destructive text-xs"
        >
          <li v-for="m in errorsOf('document_number')" :key="m">{{ m }}</li>
        </ul>
      </div>
    </div>

    <div v-if="canUpdate && mode !== 'create'" class="flex flex-wrap items-center gap-3">
      <Button type="button" variant="outline" size="sm" :disabled="busy" @click="search">
        {{ t('famUnit.pickup.person.search') }}
      </Button>
    </div>

    <div
      v-if="mode === 'found' && found"
      role="status"
      class="border-border rounded-lg border p-3 text-sm"
    >
      <p class="font-medium">{{ found.display_name }}</p>
      <p v-if="found.document" class="text-muted-foreground text-xs">
        {{ typeLabel(found.document.type) }} {{ found.document.number }}
      </p>
      <Button type="button" size="sm" class="mt-2" @click="use(found)">
        {{ t('famUnit.pickup.person.useExisting') }}
      </Button>
    </div>

    <template v-if="mode === 'create'">
      <p v-if="canUpdate" role="status" class="text-muted-foreground text-sm">
        {{ t('famUnit.pickup.person.notFound') }}
      </p>
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="flex flex-col gap-1.5">
          <Label for="fam-unit-pickup-given">{{ t('famUnit.pickup.person.givenName') }}</Label>
          <Input
            id="fam-unit-pickup-given"
            v-model="givenName"
            :aria-invalid="errorsOf('given_name').length ? 'true' : undefined"
          />
          <ul v-if="errorsOf('given_name').length" role="alert" class="text-destructive text-xs">
            <li v-for="m in errorsOf('given_name')" :key="m">{{ m }}</li>
          </ul>
        </div>
        <div class="flex flex-col gap-1.5">
          <Label for="fam-unit-pickup-family1">{{ t('famUnit.pickup.person.familyName1') }}</Label>
          <Input
            id="fam-unit-pickup-family1"
            v-model="familyName1"
            :aria-invalid="errorsOf('family_name_1').length ? 'true' : undefined"
          />
          <ul v-if="errorsOf('family_name_1').length" role="alert" class="text-destructive text-xs">
            <li v-for="m in errorsOf('family_name_1')" :key="m">{{ m }}</li>
          </ul>
        </div>
        <div class="flex flex-col gap-1.5">
          <Label for="fam-unit-pickup-family2">{{ t('famUnit.pickup.person.familyName2') }}</Label>
          <Input id="fam-unit-pickup-family2" v-model="familyName2" />
        </div>
        <div class="flex flex-col gap-1.5">
          <Label for="fam-unit-pickup-phone">{{ t('famUnit.pickup.person.phone') }}</Label>
          <Input
            id="fam-unit-pickup-phone"
            v-model="phone"
            type="tel"
            :aria-invalid="errorsOf('contact_phone').length ? 'true' : undefined"
          />
          <ul v-if="errorsOf('contact_phone').length" role="alert" class="text-destructive text-xs">
            <li v-for="m in errorsOf('contact_phone')" :key="m">{{ m }}</li>
          </ul>
        </div>
      </div>
    </template>

    <p v-if="reused" role="status" class="text-sm">{{ t('famUnit.pickup.person.reused') }}</p>
    <p v-if="reused && mismatch" role="status" class="text-sm">
      {{ t('famUnit.pickup.person.dataMismatch') }}
    </p>
    <p v-if="formError" role="alert" class="text-destructive text-sm">{{ formError }}</p>
  </div>
</template>
