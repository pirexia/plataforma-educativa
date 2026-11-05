<script setup lang="ts">
/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.4` (`1.14b-c1` T1, solo lectura). Sección «Personas autorizadas a
 * recoger» de la ficha de familia: la monta `FamilyUnitDetailView` solo con `autorizado_recogida.leer` (`RN-CORE-61`,
 * UI-1); sin el permiso no existe en el DOM y no se hace ninguna petición a `/pickup-*`.
 *
 * Recibe la unidad y sus alumnos **ya cargados** por la vista (no los vuelve a pedir). Al montar no hay petición alguna
 * (UI-2): elegir un alumno carga su tabla de autorizaciones (no escribe `read`), y la lista vigente solo se pide al
 * pulsar «Ver lista de recogida». El nombre del alumno vive únicamente en memoria de este componente: ni URL, ni
 * `history.state`, ni título, ni almacenamiento (UI-3, `OPEN-FAM-UNIT-58`). Al cambiar de alumno se remontan los dos
 * paneles (`:key`) y con ellos se descarta lo cargado, incluidas las URL firmadas. #512 (decisión del usuario, 2026-11-05):
 * tras retirar una autorización o quitar una foto se descarta también la lista de recogida local (sin petición alguna)
 * y vuelve al estado «lista no cargada»; no se avisa de que esté desactualizada.
 */
import { computed, ref } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  deletePickupPersonPhoto,
  updatePickupAuthorizationValidity,
  withdrawPickupAuthorization,
} from '../api'
import { useActions } from '../composables/useActions'
import { usePermissions } from '../composables/usePermissions'
import type { FamilyUnit, FamilyUnitStudent, Guardian, PickupAuthorization } from '../types'
import ActionNotices from './ActionNotices.vue'
import PickupAuthorizationsTable from './PickupAuthorizationsTable.vue'
import PickupAuthorizationWizard from './PickupAuthorizationWizard.vue'
import PickupListPanel from './PickupListPanel.vue'
import PickupPersonDialog from './PickupPersonDialog.vue'
import PickupPhotoDialog from './PickupPhotoDialog.vue'
import PickupValidityDialog from './PickupValidityDialog.vue'

const props = defineProps<{
  unit: FamilyUnit
  students: FamilyUnitStudent[]
  guardians: Guardian[]
}>()

const t = useT()
const { can } = usePermissions()
const actions = useActions()

const studentPerson = ref<string>('')
const table = ref<{ refresh: () => Promise<void> } | null>(null)
/** #512: subir este número remonta `PickupListPanel` y con ello descarta la lista de recogida ya cargada. */
const listEpoch = ref(0)

function discardLoadedList(): void {
  listEpoch.value++
}

// UI-1: la interfaz oculta, el servidor decide (`INV-002`). Permisos de `/me`, nunca el rol.
const canCreate = computed(() => can('autorizado_recogida.crear'))
const canUpdate = computed(() => can('autorizado_recogida.actualizar'))
const canDelete = computed(() => can('autorizado_recogida.eliminar'))
const canReadTerms = computed(() => can('texto_legal.leer'))

const principal = computed(
  () => props.guardians.find((item) => item.public_id === props.unit.principal_guardian) ?? null,
)
const principalName = computed(() =>
  principal.value && principal.value.display_name !== ''
    ? principal.value.display_name
    : t('famUnit.guardians.removedUser'),
)

/** `OPEN-FAM-UNIT-55` A y §23.4.3: sin tutor principal o sin `texto_legal.leer` el alta se explica y no se ofrece. */
const newDisabledReason = computed<string | null>(() => {
  if (principal.value === null) return t('famUnit.pickup.actions.newDisabledNoPrincipal')
  if (!canReadTerms.value) return t('famUnit.pickup.actions.newDisabledNoTermsRead')

  return null
})

function studentName(person: string): string {
  return props.students.find((item) => item.student_person === person)?.display_name ?? ''
}

const wizardOpen = ref(false)
const validityTarget = ref<PickupAuthorization | null>(null)
const validityOpen = ref(false)
const photoTarget = ref<PickupAuthorization | null>(null)
const photoOpen = ref(false)
const personTarget = ref<string | null>(null)
const personOpen = ref(false)

async function reloadTable(): Promise<void> {
  await table.value?.refresh()
}

function flash(key: string): void {
  actions.clear()
  actions.message.value = t(key)
}

async function onValidityConflict(message: string): Promise<void> {
  actions.clear()
  actions.actionError.value = message
  await reloadTable()
}

async function withdraw(row: PickupAuthorization): Promise<void> {
  const person = row.person.display_name
  const student = studentName(row.student_person)

  const done = await actions.confirmAndRun(
    {
      title: t('famUnit.pickup.confirmWithdraw.title', { person }),
      description: t('famUnit.pickup.confirmWithdraw.description', { student }),
      confirmLabel: t('famUnit.pickup.actions.withdraw', { person, student }),
      destructive: true,
    },
    () => withdrawPickupAuthorization(row.public_id),
    t('famUnit.pickup.flash.withdrawn'),
  )

  if (done) {
    discardLoadedList()
    await reloadTable()
  }
}

async function removePhoto(row: PickupAuthorization): Promise<void> {
  const person = row.person.display_name

  const done = await actions.confirmAndRun(
    {
      title: t('famUnit.pickup.actions.removePhoto', { person }),
      description: t('famUnit.pickup.confirmRemovePhoto.description'),
      confirmLabel: t('famUnit.pickup.actions.removePhoto', { person }),
      destructive: true,
    },
    () => deletePickupPersonPhoto(row.person.public_id),
    t('famUnit.pickup.flash.photoRemoved'),
  )

  if (done) {
    discardLoadedList()
    await reloadTable()
  }
}

function editValidity(row: PickupAuthorization): void {
  validityTarget.value = row
  validityOpen.value = true
}

function uploadPhoto(row: PickupAuthorization): void {
  photoTarget.value = row
  photoOpen.value = true
}

function viewPerson(row: PickupAuthorization): void {
  personTarget.value = row.person.public_id
  personOpen.value = true
}

async function afterWizard(changed: boolean): Promise<void> {
  if (changed) {
    flash('famUnit.pickup.flash.created')
    await reloadTable()
  }
}
</script>

<template>
  <section aria-labelledby="fam-unit-pickup" class="flex flex-col gap-4">
    <h2 id="fam-unit-pickup" class="text-sm font-semibold">
      {{ t('famUnit.pickup.section.title') }}
    </h2>

    <ActionNotices :message="actions.message.value" :action-error="actions.actionError.value" />

    <div v-if="canCreate" class="flex flex-col gap-1">
      <div>
        <Button
          type="button"
          size="sm"
          :disabled="newDisabledReason !== null"
          :aria-describedby="
            newDisabledReason !== null ? 'fam-unit-pickup-new-disabled' : undefined
          "
          @click="wizardOpen = true"
        >
          {{ t('famUnit.pickup.actions.new') }}
        </Button>
      </div>
      <p
        v-if="newDisabledReason !== null"
        id="fam-unit-pickup-new-disabled"
        class="text-muted-foreground text-xs"
      >
        {{ newDisabledReason }}
      </p>
    </div>

    <div class="flex max-w-sm flex-col gap-1.5">
      <Label for="fam-unit-pickup-student">{{ t('famUnit.pickup.section.chooseStudent') }}</Label>
      <Select v-model="studentPerson">
        <SelectTrigger id="fam-unit-pickup-student">
          <SelectValue :placeholder="t('famUnit.form.choose')" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem v-for="item in students" :key="item.public_id" :value="item.student_person">
            {{ item.display_name }}
          </SelectItem>
        </SelectContent>
      </Select>
    </div>

    <template v-if="studentPerson !== ''">
      <PickupListPanel
        :key="`list-${studentPerson}-${listEpoch}`"
        :student-person="studentPerson"
      />
      <PickupAuthorizationsTable
        :key="`table-${studentPerson}`"
        ref="table"
        :student-person="studentPerson"
        :student-name="studentName(studentPerson)"
        :can-update="canUpdate"
        :can-delete="canDelete"
        @withdraw="withdraw"
        @edit-validity="editValidity"
        @upload-photo="uploadPhoto"
        @remove-photo="removePhoto"
        @view-person="viewPerson"
      />
    </template>
    <p v-else class="text-muted-foreground text-sm">{{ t('famUnit.pickup.list.initial') }}</p>

    <PickupAuthorizationWizard
      v-if="canCreate && canReadTerms"
      v-model:open="wizardOpen"
      :students="students"
      :initial-student="studentPerson"
      :principal-guardian="unit.principal_guardian"
      :principal-name="principalName"
      :can-update="canUpdate"
      @done="afterWizard"
    />
    <PickupValidityDialog
      v-if="canUpdate"
      v-model:open="validityOpen"
      :authorization="validityTarget"
      :save="
        (validUntil) => updatePickupAuthorizationValidity(validityTarget!.public_id, validUntil)
      "
      @saved="
        () => {
          flash('famUnit.pickup.flash.validitySaved')
          void reloadTable()
        }
      "
      @conflict="onValidityConflict"
    />
    <PickupPhotoDialog
      v-if="canUpdate && photoTarget"
      v-model:open="photoOpen"
      :person-public-id="photoTarget.person.public_id"
      :person-name="photoTarget.person.display_name"
      @saved="
        () => {
          flash('famUnit.pickup.flash.photoSaved')
          void reloadTable()
        }
      "
    />
    <PickupPersonDialog
      v-if="canUpdate && personTarget"
      v-model:open="personOpen"
      :person-public-id="personTarget"
      @saved="
        () => {
          flash('famUnit.pickup.flash.personSaved')
          void reloadTable()
        }
      "
    />
    <ConfirmDialog
      :open="actions.confirmation.open.value"
      :request="actions.confirmation.request.value"
      @confirm="actions.confirmation.confirm"
      @cancel="actions.confirmation.cancel"
    />
  </section>
</template>
