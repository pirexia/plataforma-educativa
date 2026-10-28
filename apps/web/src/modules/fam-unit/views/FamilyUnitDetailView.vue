<script setup lang="ts">
/**
 * `/familias/:publicId` (`docs/modulos/REQ-FAM-UNIT/funcional.md §10`, `§4.2` a `§4.5`, `REQ-FAM-UNIT-001`/`-002`/`-004`;
 * `1.14-d`). Ficha de una unidad familiar: tutores, alumnos, vínculos (con la revocación si se tiene
 * `revocacion_tutor.leer`) y consentimientos de imagen (si se tiene `consentimiento_imagen.leer`). Cada sección y cada
 * acción se muestran si y solo si el permiso de su *endpoint* está en `/me.permissions` (`RN-CORE-61`); el servidor
 * decide igualmente (`INV-002`). Con ámbito `unidad_familiar` el servidor ya acota lo que devuelve (`RN-FAM-UNIT-36`).
 *
 * Accesibilidad (WCAG 2.2 AA): `activo`/`revocado` y `autorizo`/`no_autorizo`/`pendiente` se pintan con texto e icono
 * propios, nunca solo con color; cada botón de fila lleva un nombre accesible único; el borrado y la revocación piden
 * confirmación (`RN-CORE-64`). Un `409`/`422`/`429` se muestra junto a la acción y la pantalla no cambia hasta que la
 * acción tiene éxito.
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { Ban, CircleCheck, CircleHelp, CircleX } from '@lucide/vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import {
  DataTable,
  useDataTableFormatters,
  type DataTableColumn,
  type DataTableLocalFetcher,
} from '@/data-table'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import ActionNotices from '../components/ActionNotices.vue'
import GuardianFormDialog from '../components/GuardianFormDialog.vue'
import LinkFormDialog from '../components/LinkFormDialog.vue'
import StatusBadge from '../components/StatusBadge.vue'
import StudentFormDialog from '../components/StudentFormDialog.vue'
import {
  addFamilyUnitStudent,
  createGuardian,
  createGuardianLink,
  deleteGuardian,
  getFamilyUnit,
  liftGuardianLinkRevocation,
  listGuardianLinks,
  listGuardians,
  listFamilyUnitStudents,
  listImageConsents,
  removeFamilyUnitStudent,
  revokeGuardianLink,
  setPrincipalGuardian,
  type CreateGuardianLinkBody,
} from '../api'
import { useActions } from '../composables/useActions'
import { useLabels } from '../composables/useLabels'
import { useLoad } from '../composables/useLoad'
import { usePermissions } from '../composables/usePermissions'
import type {
  FamilyUnit,
  FamilyUnitStudent,
  Guardian,
  GuardianStudentLink,
  ImageConsent,
} from '../types'

/** Una unidad tiene decenas de personas como mucho (`api.md §3`): una sola página basta. */
const PER_PAGE = 100
/** `OPEN-FAM-UNIT-07`: el único motivo de revocación es la resolución judicial. */
const REVOCATION_REASON = 'resolucion_judicial'

interface FamilyUnitData {
  unit: FamilyUnit
  guardians: Guardian[]
  students: FamilyUnitStudent[]
  links: GuardianStudentLink[] | null
  consents: ImageConsent[] | null
}

const t = useT()
const route = useRoute()
const { can } = usePermissions()
const { formatDateTime } = useDataTableFormatters()
const labels = useLabels()
const actions = useActions()

const publicId = computed(() => String(route.params.publicId))

const canReadLinks = computed(() => can('vinculo_tutor.leer'))
const canReadRevocations = computed(() => can('revocacion_tutor.leer'))
const canReadConsents = computed(() => can('consentimiento_imagen.leer'))
const canAddGuardian = computed(() => can('familia.crear'))
const canUpdate = computed(() => can('familia.actualizar'))
const canRemoveGuardian = computed(() => can('familia.eliminar'))
const canCreateLink = computed(() => can('vinculo_tutor.crear'))
const canRevoke = computed(() => can('revocacion_tutor.crear'))
const canLift = computed(() => can('revocacion_tutor.eliminar'))

async function loadAll(): Promise<FamilyUnitData> {
  const id = publicId.value
  const [unit, guardians, students, links, consents] = await Promise.all([
    getFamilyUnit(id),
    listGuardians({ family_unit: id, per_page: PER_PAGE }),
    listFamilyUnitStudents(id),
    canReadLinks.value ? listGuardianLinks({ family_unit: id, per_page: PER_PAGE }) : null,
    canReadConsents.value ? listImageConsents({ family_unit: id, per_page: PER_PAGE }) : null,
  ])

  return {
    unit,
    guardians: guardians.data,
    students: students.data,
    links: links === null ? null : links.data,
    consents: consents === null ? null : consents.data,
  }
}

const { data, loading, error, load } = useLoad<FamilyUnitData>(loadAll)

/** Recarga sin volver al estado de carga: los diálogos y el foco se conservan. */
async function refresh(): Promise<void> {
  try {
    data.value = await loadAll()
    await refreshTables()
  } catch (err) {
    actions.fail(err)
  }
}

onMounted(load)

const guardianFormOpen = ref(false)
const studentFormOpen = ref(false)
const linkFormOpen = ref(false)

function guardianName(id: string): string {
  const guardian = data.value?.guardians.find((item) => item.public_id === id)

  return guardian && guardian.display_name !== ''
    ? guardian.display_name
    : t('famUnit.guardians.removedUser')
}

function studentName(person: string): string {
  return data.value?.students.find((item) => item.student_person === person)?.display_name ?? person
}

function displayOf(guardian: Guardian): string {
  return guardian.display_name !== '' ? guardian.display_name : t('famUnit.guardians.removedUser')
}

function yesNo(value: boolean): string {
  return value ? t('famUnit.common.yes') : t('famUnit.common.no')
}

function isRevoked(link: GuardianStudentLink): boolean {
  return link.status === 'revocado'
}

// --- Tablas (componente de `ADR-054`, modo `local`: colecciones pequeñas ya cargadas por la ficha) ---------------

type TableRef = { refresh: () => Promise<void> } | null

const guardiansTable = ref<TableRef>(null)
const studentsTable = ref<TableRef>(null)
const linksTable = ref<TableRef>(null)
const consentsTable = ref<TableRef>(null)

const showGuardianActions = computed(() => canUpdate.value || canRemoveGuardian.value)
const showLinkActions = computed(() => canRevoke.value || canLift.value)

const guardianColumns = computed<DataTableColumn<Guardian>[]>(() => [
  {
    id: 'display_name',
    headerKey: 'famUnit.fields.name',
    value: (row) => displayOf(row),
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'is_principal',
    headerKey: 'famUnit.fields.principalGuardian',
    value: (row) =>
      row.is_principal ? t('famUnit.guardians.principalYes') : t('famUnit.common.dash'),
    card: 'field',
  },
  ...(showGuardianActions.value
    ? [
        {
          id: 'actions',
          headerKey: 'famUnit.fields.actions',
          hideable: false,
          card: 'actions',
        } satisfies DataTableColumn<Guardian>,
      ]
    : []),
])

const studentColumns = computed<DataTableColumn<FamilyUnitStudent>[]>(() => [
  {
    id: 'display_name',
    headerKey: 'famUnit.fields.name',
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  ...(canUpdate.value
    ? [
        {
          id: 'actions',
          headerKey: 'famUnit.fields.actions',
          hideable: false,
          card: 'actions',
        } satisfies DataTableColumn<FamilyUnitStudent>,
      ]
    : []),
])

const linkColumns = computed<DataTableColumn<GuardianStudentLink>[]>(() => [
  {
    id: 'guardian',
    headerKey: 'famUnit.fields.guardian',
    value: (row) => guardianName(row.guardian),
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'student_person',
    headerKey: 'famUnit.fields.student',
    value: (row) => studentName(row.student_person),
    card: 'subtitle',
  },
  {
    id: 'relationship',
    headerKey: 'famUnit.fields.relationship',
    value: (row) => labels.relationshipLabel(row.relationship),
    card: 'field',
  },
  {
    id: 'is_legal_guardian',
    headerKey: 'famUnit.fields.isLegalGuardian',
    value: (row) => yesNo(row.is_legal_guardian),
    card: 'field',
  },
  {
    id: 'access_level',
    headerKey: 'famUnit.fields.accessLevel',
    value: (row) => labels.accessLevelLabel(row.access_level),
    card: 'field',
  },
  {
    id: 'is_emergency_contact',
    headerKey: 'famUnit.fields.isEmergencyContact',
    value: (row) => yesNo(row.is_emergency_contact),
    card: 'field',
  },
  {
    id: 'status',
    headerKey: 'famUnit.fields.status',
    value: (row) => labels.linkStatusLabel(row.status),
    card: 'field',
  },
  ...(showLinkActions.value
    ? [
        {
          id: 'actions',
          headerKey: 'famUnit.fields.actions',
          hideable: false,
          card: 'actions',
        } satisfies DataTableColumn<GuardianStudentLink>,
      ]
    : []),
])

const consentColumns: DataTableColumn<ImageConsent>[] = [
  {
    id: 'display_name',
    headerKey: 'famUnit.fields.student',
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'level',
    headerKey: 'famUnit.fields.level',
    value: (row) => labels.consentLevelLabel(row.level),
    card: 'field',
  },
  { id: 'state', headerKey: 'famUnit.fields.state', card: 'field' },
  {
    id: 'last_decision_at',
    headerKey: 'famUnit.fields.lastDecisionAt',
    value: (row) => formatDateTime(row.last_decision_at) ?? t('famUnit.common.dash'),
    card: 'field',
  },
]

const fetchGuardians: DataTableLocalFetcher<Guardian> = async () => ({
  data: data.value?.guardians ?? [],
})
const fetchStudents: DataTableLocalFetcher<FamilyUnitStudent> = async () => ({
  data: data.value?.students ?? [],
})
const fetchLinks: DataTableLocalFetcher<GuardianStudentLink> = async () => ({
  data: data.value?.links ?? [],
})
const fetchConsents: DataTableLocalFetcher<ImageConsent> = async () => ({
  data: data.value?.consents ?? [],
})

const consentRowKey = (row: ImageConsent): string => `${row.student_person}:${row.level}`

async function refreshTables(): Promise<void> {
  await Promise.all(
    [guardiansTable, studentsTable, linksTable, consentsTable].map((table) =>
      table.value?.refresh(),
    ),
  )
}

// --- Acciones ----------------------------------------------------------------

async function saveGuardian(user: string): Promise<void> {
  await createGuardian({ user, family_unit: publicId.value })
}

async function saveStudent(studentPerson: string): Promise<void> {
  await addFamilyUnitStudent(publicId.value, studentPerson)
}

async function saveLink(body: CreateGuardianLinkBody): Promise<void> {
  await createGuardianLink(body)
}

async function afterSave(messageKey: string): Promise<void> {
  actions.clear()
  actions.message.value = t(messageKey)
  await refresh()
}

async function makePrincipal(guardian: Guardian): Promise<void> {
  const done = await actions.run(
    () => setPrincipalGuardian(publicId.value, guardian.public_id),
    t('famUnit.flash.principalSet', { name: displayOf(guardian) }),
  )

  if (done) await refresh()
}

async function removeGuardian(guardian: Guardian): Promise<void> {
  const name = displayOf(guardian)
  const done = await actions.confirmAndRun(
    {
      title: t('famUnit.guardians.confirmRemove.title', { name }),
      description: t('famUnit.guardians.confirmRemove.description'),
      confirmLabel: t('famUnit.guardians.confirmRemove.confirm', { name }),
      destructive: true,
    },
    () => deleteGuardian(guardian.public_id),
    t('famUnit.flash.guardianRemoved', { name }),
  )

  if (done) await refresh()
}

async function removeStudent(student: FamilyUnitStudent): Promise<void> {
  const name = student.display_name
  const done = await actions.confirmAndRun(
    {
      title: t('famUnit.students.confirmRemove.title', { name }),
      description: t('famUnit.students.confirmRemove.description'),
      confirmLabel: t('famUnit.students.confirmRemove.confirm', { name }),
      destructive: true,
    },
    () => removeFamilyUnitStudent(publicId.value, student.public_id),
    t('famUnit.flash.studentRemoved', { name }),
  )

  if (done) await refresh()
}

async function revoke(link: GuardianStudentLink): Promise<void> {
  const guardian = guardianName(link.guardian)
  const student = studentName(link.student_person)
  const done = await actions.confirmAndRun(
    {
      title: t('famUnit.links.confirmRevoke.title', { guardian, student }),
      description: t('famUnit.links.confirmRevoke.description', {
        reason: labels.revocationReasonLabel(REVOCATION_REASON),
      }),
      confirmLabel: t('famUnit.links.confirmRevoke.confirm', { guardian, student }),
      destructive: true,
    },
    () => revokeGuardianLink(link.public_id, REVOCATION_REASON),
    t('famUnit.flash.linkRevoked', { guardian, student }),
  )

  if (done) await refresh()
}

async function lift(link: GuardianStudentLink): Promise<void> {
  const guardian = guardianName(link.guardian)
  const student = studentName(link.student_person)
  const done = await actions.confirmAndRun(
    {
      title: t('famUnit.links.confirmLift.title', { guardian, student }),
      description: t('famUnit.links.confirmLift.description'),
      confirmLabel: t('famUnit.links.confirmLift.confirm', { guardian, student }),
    },
    () => liftGuardianLinkRevocation(link.public_id),
    t('famUnit.flash.linkLifted', { guardian, student }),
  )

  if (done) await refresh()
}

function consentIcon(state: string) {
  if (state === 'autorizo') return CircleCheck
  if (state === 'no_autorizo') return CircleX

  return CircleHelp
}
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-6">
    <LoadingState v-if="loading && data === null" />
    <ErrorState v-else-if="error" :state="error" @retry="load" />

    <template v-else-if="data">
      <header class="flex flex-col gap-1">
        <p class="text-sm">
          <RouterLink
            :to="{ name: 'fam-unit-list' }"
            class="text-primary-on-background underline underline-offset-4"
          >
            {{ t('famUnit.nav.backToUnits') }}
          </RouterLink>
        </p>
        <h1 class="text-lg font-semibold">
          {{ t('famUnit.detail.title', { code: data.unit.code }) }}
        </h1>
      </header>

      <ActionNotices :message="actions.message.value" :action-error="actions.actionError.value" />

      <section aria-labelledby="fam-unit-guardians" class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 id="fam-unit-guardians" class="text-sm font-semibold">
            {{ t('famUnit.guardians.title') }}
          </h2>
          <Button v-if="canAddGuardian" type="button" size="sm" @click="guardianFormOpen = true">
            {{ t('famUnit.guardians.add') }}
          </Button>
        </div>

        <DataTable
          ref="guardiansTable"
          table-id="fam_unit.detail_guardians"
          :caption="t('famUnit.guardians.caption')"
          :columns="guardianColumns"
          mode="local"
          :fetcher="fetchGuardians"
          hide-single-page-footer
          :empty-title="t('famUnit.guardians.empty')"
          :card-heading-level="3"
        >
          <template #cell-actions="{ row }">
            <div class="flex flex-wrap gap-2">
              <Button
                v-if="canUpdate && !row.is_principal"
                type="button"
                variant="outline"
                size="sm"
                :disabled="actions.busy.value"
                :aria-label="t('famUnit.guardians.makePrincipalFor', { name: displayOf(row) })"
                @click="makePrincipal(row)"
              >
                {{ t('famUnit.guardians.makePrincipal') }}
              </Button>
              <Button
                v-if="canRemoveGuardian"
                type="button"
                variant="outline"
                size="sm"
                :disabled="actions.busy.value"
                :aria-label="t('famUnit.guardians.removeFor', { name: displayOf(row) })"
                @click="removeGuardian(row)"
              >
                {{ t('famUnit.common.remove') }}
              </Button>
            </div>
          </template>
        </DataTable>
      </section>

      <section aria-labelledby="fam-unit-students" class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 id="fam-unit-students" class="text-sm font-semibold">
            {{ t('famUnit.students.title') }}
          </h2>
          <Button v-if="canUpdate" type="button" size="sm" @click="studentFormOpen = true">
            {{ t('famUnit.students.add') }}
          </Button>
        </div>

        <DataTable
          ref="studentsTable"
          table-id="fam_unit.detail_students"
          :caption="t('famUnit.students.caption')"
          :columns="studentColumns"
          mode="local"
          :fetcher="fetchStudents"
          hide-single-page-footer
          :empty-title="t('famUnit.students.empty')"
          :card-heading-level="3"
        >
          <template #cell-actions="{ row }">
            <Button
              type="button"
              variant="outline"
              size="sm"
              :disabled="actions.busy.value"
              :aria-label="t('famUnit.students.removeFor', { name: row.display_name })"
              @click="removeStudent(row)"
            >
              {{ t('famUnit.common.remove') }}
            </Button>
          </template>
        </DataTable>
      </section>

      <section
        v-if="data.links !== null"
        aria-labelledby="fam-unit-links"
        class="flex flex-col gap-3"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 id="fam-unit-links" class="text-sm font-semibold">{{ t('famUnit.links.title') }}</h2>
          <Button
            v-if="canCreateLink"
            type="button"
            size="sm"
            :disabled="data.guardians.length === 0 || data.students.length === 0"
            @click="linkFormOpen = true"
          >
            {{ t('famUnit.links.add') }}
          </Button>
        </div>
        <p
          v-if="canCreateLink && (data.guardians.length === 0 || data.students.length === 0)"
          class="text-muted-foreground text-xs"
        >
          {{ t('famUnit.links.addNeeds') }}
        </p>

        <DataTable
          ref="linksTable"
          table-id="fam_unit.detail_links"
          :caption="t('famUnit.links.caption')"
          :columns="linkColumns"
          mode="local"
          :fetcher="fetchLinks"
          hide-single-page-footer
          :empty-title="t('famUnit.links.empty')"
          :card-heading-level="3"
        >
          <template #cell-status="{ row }">
            <StatusBadge
              :text="labels.linkStatusLabel(row.status)"
              :icon="isRevoked(row) ? Ban : CircleCheck"
              :variant="isRevoked(row) ? 'destructive' : 'outline'"
            />
            <p
              v-if="isRevoked(row) && canReadRevocations && row.revocation"
              class="text-muted-foreground mt-1 text-xs"
              data-slot="fam-unit-revocation"
            >
              {{
                t('famUnit.links.revocation', {
                  reason: labels.revocationReasonLabel(row.revocation.reason),
                  date: formatDateTime(row.revocation.revoked_at) ?? '',
                })
              }}
            </p>
          </template>
          <template #cell-actions="{ row }">
            <Button
              v-if="!isRevoked(row) && canRevoke"
              type="button"
              variant="outline"
              size="sm"
              :disabled="actions.busy.value"
              :aria-label="
                t('famUnit.links.revokeFor', {
                  guardian: guardianName(row.guardian),
                  student: studentName(row.student_person),
                })
              "
              @click="revoke(row)"
            >
              {{ t('famUnit.links.revoke') }}
            </Button>
            <Button
              v-else-if="isRevoked(row) && canLift"
              type="button"
              variant="outline"
              size="sm"
              :disabled="actions.busy.value"
              :aria-label="
                t('famUnit.links.liftFor', {
                  guardian: guardianName(row.guardian),
                  student: studentName(row.student_person),
                })
              "
              @click="lift(row)"
            >
              {{ t('famUnit.links.lift') }}
            </Button>
          </template>
        </DataTable>
      </section>

      <section
        v-if="data.consents !== null"
        aria-labelledby="fam-unit-consents"
        class="flex flex-col gap-3"
      >
        <h2 id="fam-unit-consents" class="text-sm font-semibold">
          {{ t('famUnit.consents.title') }}
        </h2>

        <DataTable
          ref="consentsTable"
          table-id="fam_unit.detail_consents"
          :caption="t('famUnit.consents.unitCaption')"
          :columns="consentColumns"
          mode="local"
          :fetcher="fetchConsents"
          :row-key="consentRowKey"
          hide-single-page-footer
          :empty-title="t('famUnit.consents.emptyUnit')"
          :card-heading-level="3"
        >
          <template #cell-state="{ row }">
            <StatusBadge
              :text="labels.consentStateLabel(row.state)"
              :icon="consentIcon(row.state)"
              :variant="
                row.state === 'autorizo'
                  ? 'outline'
                  : row.state === 'no_autorizo'
                    ? 'destructive'
                    : 'secondary'
              "
            />
          </template>
        </DataTable>
      </section>

      <GuardianFormDialog
        v-model:open="guardianFormOpen"
        :save="saveGuardian"
        @saved="afterSave('famUnit.flash.guardianCreated')"
      />
      <StudentFormDialog
        v-model:open="studentFormOpen"
        :save="saveStudent"
        @saved="afterSave('famUnit.flash.studentAdded')"
      />
      <LinkFormDialog
        v-model:open="linkFormOpen"
        :guardians="data.guardians"
        :students="data.students"
        :save="saveLink"
        @saved="afterSave('famUnit.flash.linkCreated')"
      />
    </template>

    <ConfirmDialog
      :open="actions.confirmation.open.value"
      :request="actions.confirmation.request.value"
      @confirm="actions.confirmation.confirm"
      @cancel="actions.confirmation.cancel"
    />
  </div>
</template>
